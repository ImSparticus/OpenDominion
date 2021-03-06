<?php

namespace OpenDominion\Services\Dominion;

use DB;
use Exception;
use Illuminate\Support\Carbon;
use Log;
use OpenDominion\Calculators\Dominion\CasualtiesCalculator;
use OpenDominion\Calculators\Dominion\LandCalculator;
use OpenDominion\Calculators\Dominion\PopulationCalculator;
use OpenDominion\Calculators\Dominion\ProductionCalculator;
use OpenDominion\Calculators\Dominion\SpellCalculator;
use OpenDominion\Calculators\NetworthCalculator;
use OpenDominion\Models\Dominion;
use OpenDominion\Models\Round;
use Throwable;

class TickService
{
    /** @var Carbon */
    protected $now;

    /** @var CasualtiesCalculator */
    protected $casualtiesCalculator;

    /** @var LandCalculator */
    protected $landCalculator;

    /** @var NetworthCalculator */
    protected $networthCalculator;

    /** @var PopulationCalculator */
    protected $populationCalculator;

    /** @var ProductionCalculator */
    protected $productionCalculator;

    /** @var SpellCalculator */
    protected $spellCalculator;

    /**
     * TickService constructor.
     */
    public function __construct()
    {
        $this->now = now();
        $this->casualtiesCalculator = app(CasualtiesCalculator::class);
        $this->landCalculator = app(LandCalculator::class);
        $this->networthCalculator = app(NetworthCalculator::class);
        $this->populationCalculator = app(PopulationCalculator::class);
        $this->productionCalculator = app(ProductionCalculator::class);
        $this->spellCalculator = app(SpellCalculator::class);
    }

    /**
     * Does an hourly tick on all active dominions.
     *
     * @throws Exception|Throwable
     */
    public function tickHourly()
    {
        Log::debug('Hourly tick started');

        DB::transaction(function () {
            foreach (Round::with('dominions')->active()->get() as $round) {
                foreach ($round->dominions as $dominion) {
                    $this->tickDominion($dominion);
                }
            }
        });

        Log::info('Ticked X dominions in Y seconds');
    }

    /**
     * Does a daily tick on all active dominions and rounds.
     *
     * @throws Exception|Throwable
     */
    public function tickDaily()
    {
        Log::debug('Daily tick started');

        DB::transaction(function () {
            foreach (Round::with('dominions')->active()->get() as $round) {
                $dominionIds = [];

                foreach ($round->dominions as $dominion) {
                    $dominionIds[] = $dominion->id;

                    $dominion->daily_platinum = false;
                    $dominion->daily_land = false;

                    $dominion->save();
                }

                $this->updateDailyRankings($dominionIds);
            }
        });

        Log::info('Daily tick finished');
    }

    protected function tickDominion(Dominion $dominion)
    {
        // todo: split up in their own methods

        // Queues
        foreach ($this->tickExplorationQueue($dominion) as $land => $amount) {
            $dominion->{'land_' . $land} += $amount;
        }

        foreach ($this->tickConstructionQueue($dominion) as $building => $amount) {
            $dominion->{'building_' . $building} += $amount;
        }

        foreach ($this->tickTrainingQueue($dominion) as $unit => $amount) {
            $dominion->{'military_' . $unit} += $amount;
        }

        // todo: tickReturningQueue

        // Hacky refresh active spells for dominion
        $this->spellCalculator->getActiveSpells($dominion, true);

        // Resources
        $dominion->resource_platinum += $this->productionCalculator->getPlatinumProduction($dominion);
        $dominion->resource_food += $this->productionCalculator->getFoodNetChange($dominion);
        $dominion->resource_lumber += $this->productionCalculator->getLumberNetChange($dominion);
        $dominion->resource_mana += $this->productionCalculator->getManaNetChange($dominion);
        $dominion->resource_ore += $this->productionCalculator->getOreProduction($dominion);
        $dominion->resource_gems += $this->productionCalculator->getGemProduction($dominion);
        $dominion->resource_boats += $this->productionCalculator->getBoatProduction($dominion);

        // Starvation casualties
        if ($dominion->resource_food < 0) {
            $casualties = $this->casualtiesCalculator->getStarvationCasualtiesByUnitType($dominion);

            foreach ($casualties as $unit => $unitCasualties) {
                $dominion->{$unit} -= $unitCasualties;
            }

            $dominion->resource_food = 0;
        }

        // Population
        $populationPeasantGrowth = $this->populationCalculator->getPopulationPeasantGrowth($dominion);

        $dominion->peasants += $populationPeasantGrowth;
        $dominion->peasants_last_hour = $populationPeasantGrowth;
        $dominion->military_draftees += $this->populationCalculator->getPopulationDrafteeGrowth($dominion);

        // Morale
        if ($dominion->morale < 70) {
            $dominion->morale += 6;

        } elseif ($dominion->morale < 100) {
            $dominion->morale = min(($dominion->morale + 3), 100);
        }

        // Spy Strength
        if ($dominion->spy_strength < 100) {
            $dominion->spy_strength = min(($dominion->spy_strength + 4), 100);
        }

        // Wizard Strength
        if ($dominion->wizard_strength < 100) {
            $dominion->wizard_strength = min(($dominion->wizard_strength + 4), 100);
        }

        // Active spells
        $this->tickActiveSpells($dominion);

        $dominion->save(['event' => HistoryService::EVENT_TICK]);
    }

    protected function tickExplorationQueue(Dominion $dominion): array
    {
        // Two-step process to avoid getting UNIQUE constraint integrity errors
        // since we can't reliably use deferred transactions, deferred update
        // queries or update+orderby cross-database vendors
        DB::table('queue_exploration')
            ->where('dominion_id', $dominion->id)
            ->where('hours', '>', 0)
            ->update([
                'hours' => DB::raw('-(`hours` - 1)'),
            ]);

        DB::table('queue_exploration')
            ->where('dominion_id', $dominion->id)
            ->where('hours', '<', 0)
            ->update([
                'hours' => DB::raw('-`hours`'),
                'updated_at' => $this->now,
            ]);

        $finished = DB::table('queue_exploration')
            ->where('dominion_id', $dominion->id)
            ->where('hours', 0)
            ->get();

        $return = [];

        foreach ($finished as $row) {
            $return[$row->land_type] = $row->amount;

            // Cleanup
            DB::table('queue_exploration')
                ->where('dominion_id', $dominion->id)
                ->where('land_type', $row->land_type)
                ->where('hours', 0)
                ->delete();
        }

        return $return;
    }

    protected function tickConstructionQueue(Dominion $dominion): array
    {
        // Two-step process to avoid getting UNIQUE constraint integrity errors
        // since we can't reliably use deferred transactions, deferred update
        // queries or update+orderby cross-database vendors
        DB::table('queue_construction')
            ->where('dominion_id', $dominion->id)
            ->where('hours', '>', 0)
            ->update([
                'hours' => DB::raw('-(`hours` - 1)'),
            ]);

        DB::table('queue_construction')
            ->where('dominion_id', $dominion->id)
            ->where('hours', '<', 0)
            ->update([
                'hours' => DB::raw('-`hours`'),
                'updated_at' => $this->now,
            ]);

        $finished = DB::table('queue_construction')
            ->where('dominion_id', $dominion->id)
            ->where('hours', 0)
            ->get();

        $return = [];

        foreach ($finished as $row) {
            $return[$row->building] = $row->amount;

            // Cleanup
            DB::table('queue_construction')
                ->where('dominion_id', $dominion->id)
                ->where('building', $row->building)
                ->where('hours', 0)
                ->delete();
        }

        return $return;
    }

    protected function ticktrainingQueue(Dominion $dominion): array
    {
        // Two-step process to avoid getting UNIQUE constraint integrity errors
        // since we can't reliably use deferred transactions, deferred update
        // queries or update+orderby cross-database vendors
        DB::table('queue_training')
            ->where('dominion_id', $dominion->id)
            ->where('hours', '>', 0)
            ->update([
                'hours' => DB::raw('-(`hours` - 1)'),
            ]);

        DB::table('queue_training')
            ->where('dominion_id', $dominion->id)
            ->where('hours', '<', 0)
            ->update([
                'hours' => DB::raw('-`hours`'),
                'updated_at' => $this->now,
            ]);

        $finished = DB::table('queue_training')
            ->where('dominion_id', $dominion->id)
            ->where('hours', 0)
            ->get();

        $return = [];

        foreach ($finished as $row) {
            $return[$row->unit_type] = $row->amount;

            // Cleanup
            DB::table('queue_training')
                ->where('dominion_id', $dominion->id)
                ->where('unit_type', $row->unit_type)
                ->where('hours', 0)
                ->delete();
        }

        return $return;
    }

    protected function tickActiveSpells(Dominion $dominion)
    {
        // Two-step process to avoid getting UNIQUE constraint integrity errors
        // since we can't reliably use deferred transactions, deferred update
        // queries or update+orderby cross-database vendors
        DB::table('active_spells')
            ->where('dominion_id', $dominion->id)
            ->where('duration', '>', 0)
            ->update([
                'duration' => DB::raw('-(`duration` - 1)'),
            ]);

        DB::table('active_spells')
            ->where('dominion_id', $dominion->id)
            ->where('duration', '<', 0)
            ->update([
                'duration' => DB::raw('-`duration`'),
                'updated_at' => $this->now,
            ]);

        DB::table('active_spells')
            ->where('dominion_id', $dominion->id)
            ->where('duration', 0)
            ->delete();
    }

    protected function updateDailyRankings(array $dominionIds)
    {
        // todo: needs a rewrite. haven't been able to do it due to time constraints

        // First pass: Saving land and networth
        Dominion::with(['race', 'realm'])->whereIn('id', $dominionIds)->chunk(50, function ($dominions) {
            foreach ($dominions as $dominion) {
                $where = [
                    'round_id' => (int)$dominion->round_id,
                    'dominion_id' => $dominion->id,
                ];

                $data = [
                    'dominion_name' => $dominion->name,
                    'race_name' => $dominion->race->name,
                    'realm_number' => $dominion->realm->number,
                    'realm_name' => $dominion->realm->name,
                    'land' => $this->landCalculator->getTotalLand($dominion),
                    'networth' => $this->networthCalculator->getDominionNetworth($dominion),
                ];

                $result = DB::table('daily_rankings')->where($where)->get();

                if ($result->isEmpty()) {
                    $row = $where + $data + [
                            'created_at' => $dominion->created_at,
                            'updated_at' => $this->now,
                        ];

                    DB::table('daily_rankings')->insert($row);
                } else {
                    $row = $data + [
                            'updated_at' => $this->now,
                        ];

                    DB::table('daily_rankings')->where($where)->update($row);
                }
            }
        });

        // Second pass: Calculating ranks
        $result = DB::table('daily_rankings')
            ->orderBy('land', 'desc')
            ->orderBy(DB::raw('COALESCE(land_rank, created_at)'))
            ->get();

        //Getting all rounds
        $rounds = DB::table('rounds')
            ->where('start_date', '<=', $this->now)
            ->where('end_date', '>', $this->now)
            ->get();

        foreach ($rounds as $round) {
            $rank = 1;

            foreach ($result as $row) {
                if ($row->round_id == (int)$round->id) {
                    DB::table('daily_rankings')
                        ->where('id', $row->id)
                        ->where('round_id', $round->id)
                        ->update([
                            'land_rank' => $rank,
                            'land_rank_change' => (($row->land_rank !== null) ? ($row->land_rank - $rank) : 0),
                        ]);

                    $rank++;
                }
            }

            $result = DB::table('daily_rankings')
                ->orderBy('networth', 'desc')
                ->orderBy(DB::raw('COALESCE(networth_rank, created_at)'))
                ->get();

            $rank = 1;

            foreach ($result as $row) {
                if ($row->round_id == (int)$round->id) {
                    DB::table('daily_rankings')
                        ->where('id', $row->id)
                        ->update([
                            'networth_rank' => $rank,
                            'networth_rank_change' => (($row->networth_rank !== null) ? ($row->networth_rank - $rank) : 0),
                        ]);

                    $rank++;
                }
            }
        }
    }
}
