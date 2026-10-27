<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AndeConsumptionOrigin2025Test extends TestCase
{
    use RefreshDatabase;

    public function test_origin_components_are_source_backed_annual_values_and_idempotent(): void
    {
        $this->artisan('energy:import-ande-consumption-origin-2025')->assertSuccessful();
        $this->artisan('energy:import-ande-consumption-origin-2025')->assertSuccessful();
        $rows = DB::table('observations')->where('variable_code', 'ENERGY_TO_PY')->get()->keyBy('asset_code');
        $this->assertCount(3, $rows);
        $this->assertEquals(25768000, $rows['ITAIPU']->value);
        $this->assertEquals(3081000, $rows['YACYRETA']->value);
        $this->assertEquals(570000, $rows['ACARAY']->value);
        $this->assertEquals(29419000, $rows->sum('value'));
        $this->assertSame('year', $rows['ACARAY']->grain);
        $this->assertSame('OFFICIAL_AGGREGATE_APPROXIMATE', $rows['ACARAY']->quality);
    }
}
