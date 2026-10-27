<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HistoricalHourlyAggregationTest extends TestCase
{
    use RefreshDatabase;

    public function test_processed_demand_uses_all_available_hours_and_keeps_research_provenance(): void
    {
        $this->artisan('energy:import-mendeley-demand')->assertSuccessful();
        $this->artisan('energy:import-mendeley-demand')->assertSuccessful();
        $this->assertSame(180, DB::table('observations')->count());
        $jan = DB::table('observations')->where('variable_code', 'PY_RESEARCH_MEAN_HOURLY_DEMAND')->where('period_start', '2008-01-01 00:00:00')->first();
        $this->assertEqualsWithDelta(1085.6180376344, $jan->value, 0.000001);
        $this->assertSame('RESEARCH_PROCESSED_AGGREGATE', $jan->quality);
        $this->assertSame(744, json_decode($jan->provenance, true)['hourly_records']);
        $dst = DB::table('observations')->where('period_start', '2019-10-01 00:00:00')->first();
        $this->assertSame(743, json_decode($dst->provenance, true)['hourly_records']);
        $this->assertEqualsWithDelta(2077.7134589502, $dst->value, 0.000001);
    }

    public function test_ons_hourly_means_exclude_incomplete_month_and_preserve_units(): void
    {
        $path = storage_path('app/energiapy/raw/ONS_ITAIPU_HOURLY/df8f301d92f11f905b58879ed8c46757012b94ae172bae48421d6cf1521eb265.txt');
        if (! is_file($path)) $this->markTestSkipped('La captura histórica ONS de prueba no está disponible.');
        $this->artisan('energy:import-ons-itaipu-monthly', ['--file' => $path])->assertSuccessful();
        $this->assertSame(642, DB::table('observations')->count());
        $jan = DB::table('observations')->where('variable_code', 'ITAIPU_ONS_MONTHLY_MEAN_TOTAL')->where('period_start', '2000-01-01 00:00:00')->first();
        $this->assertEqualsWithDelta(9518.1735215054, $jan->value, 0.000001);
        $this->assertSame('MW', $jan->unit);
        $this->assertSame(744, json_decode($jan->provenance, true)['hourly_records']);
        $this->assertFalse(DB::table('observations')->where('period_start', '2026-10-01 00:00:00')->exists());
    }
}
