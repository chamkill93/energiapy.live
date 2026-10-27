<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorldBankElectricityTest extends TestCase
{
    use RefreshDatabase;

    public function test_snapshots_provide_series_without_nulls_or_duplicates_and_keep_native_units(): void
    {
        $this->artisan('energy:import-worldbank-electricity')->assertSuccessful();
        $this->artisan('energy:import-worldbank-electricity')->assertSuccessful();
        $this->assertSame(125, DB::table('observations')->where('parser_version', 'worldbank-electricity-1')->count());
        $this->getJson('/api/history?metric=PY_ELECTRICITY_ACCESS_RURAL&asset=PY&unit=percent&source=WORLD_BANK&grain=year')
            ->assertOk()->assertJsonPath('meta.total', 30)->assertJsonPath('data.0.period_start', '1995-01-01 00:00:00')
            ->assertJsonPath('data.29.value', 99.6)->assertJsonPath('data.29.quality', 'OFFICIAL_ESTIMATE');
        $perCapita = $this->getJson('/api/history?metric=PY_ELECTRICITY_CONSUMPTION_PER_CAPITA&asset=PY&unit=kWh%2Fperson&source=WORLD_BANK&grain=year')
            ->assertOk()->assertJsonPath('meta.total', 35)->json('data.34.value');
        $this->assertEqualsWithDelta(2849.26599253906, $perCapita, 0.000001);
    }
}
