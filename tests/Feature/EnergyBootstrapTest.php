<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EnergyBootstrapTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresh_database_reconstructs_historical_series_and_map(): void
    {
        $this->artisan('energy:bootstrap-data')->assertSuccessful();
        $this->assertSame(8888, DB::table('v5_records')->count());
        $this->assertSame(179, DB::table('grid_assets')->where('kind', 'grid')->count());
        $this->assertSame(124, DB::table('grid_assets')->where('kind', 'substations')->count());
        $this->assertSame(1, DB::table('grid_assets')->where('kind', 'plants')->count());
        foreach ([
            ['ITAIPU_GROSS_GENERATION', 'ITAIPU', 'MWh', 'ITAIPU', 'year', 42],
            ['PY_RESEARCH_MEAN_HOURLY_DEMAND', 'PY_SIN', 'MW', 'UNA_DEMAND_RESEARCH', 'month', 180],
            ['ITAIPU_ONS_MONTHLY_MEAN_TOTAL', 'ITAIPU', 'MW', 'ONS_ITAIPU_HOURLY', 'month', 321],
            ['PY_ELECTRICITY_ACCESS', 'PY', 'percent', 'WORLD_BANK', 'year', 30],
            ['GENERATION_ENERGY', 'YACYRETA', 'MWh', 'CAMMESA', 'year', 9],
        ] as [$metric, $asset, $unit, $source, $grain, $total]) {
            $this->getJson('/api/history?'.http_build_query(compact('metric', 'asset', 'unit', 'source', 'grain')))
                ->assertOk()->assertJsonPath('meta.total', $total);
        }
        $this->getJson('/api/gis/grid')->assertOk()->assertJsonCount(179, 'features');
        $this->getJson('/api/gis/substations')->assertOk()->assertJsonCount(124, 'features');
        $this->getJson('/api/live')->assertOk()->assertJsonCount(3, 'plants');
    }
}
