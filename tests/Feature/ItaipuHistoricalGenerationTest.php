<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ItaipuHistoricalGenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_annual_generation_is_continuous_from_1984_to_2025_without_replacing_precise_values(): void
    {
        $this->artisan('energy:import-v5')->assertSuccessful();
        $this->artisan('energy:import-itaipu-annual-reports')->assertSuccessful();
        $this->artisan('energy:import-itaipu-historical-generation')->assertSuccessful();
        $before = DB::table('observations')->count();
        $this->artisan('energy:import-itaipu-historical-generation')->assertSuccessful();
        $this->assertSame($before, DB::table('observations')->count());
        $data = $this->getJson('/api/history?metric=ITAIPU_GROSS_GENERATION&asset=ITAIPU&unit=MWh&source=ITAIPU&grain=year')->assertOk()->json('data');
        $years = collect($data)->map(fn ($row) => (int) substr($row['period_start'], 0, 4))->all();
        $this->assertSame(range(1984, 2025), $years);
        $byYear = collect($data)->keyBy(fn ($row) => substr($row['period_start'], 0, 4));
        $this->assertEquals(92245500, $byYear['2011']['value']);
        $this->assertEquals(96586000, $byYear['2018']['value']);
        $this->assertEquals(72879287, $byYear['2025']['value']);
        $this->assertSame('OFFICIAL_AGGREGATE_APPROXIMATE', $byYear['2018']['quality']);
    }
}
