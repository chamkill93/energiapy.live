<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ItaipuAnnualReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_annual_reports_are_idempotent_and_keep_units_periods_and_precision(): void
    {
        $this->artisan('energy:import-itaipu-annual-reports')->assertSuccessful();
        $this->artisan('energy:import-itaipu-annual-reports')->assertSuccessful();
        $this->assertSame(14, DB::table('observations')->count());
        $this->getJson('/api/history?metric=ENERGY_TO_PY&asset=ITAIPU&unit=MWh&source=ITAIPU&grain=year')
            ->assertOk()->assertJsonPath('meta.total', 6)
            ->assertJsonPath('data.0.value', 15861000)
            ->assertJsonPath('data.5.value', 25768000)
            ->assertJsonPath('data.5.quality', 'OFFICIAL_AGGREGATE_APPROXIMATE');
        $this->getJson('/api/history?metric=ITAIPU_GROSS_GENERATION&asset=ITAIPU&unit=MWh&source=ITAIPU&grain=year')
            ->assertOk()->assertJsonPath('meta.total', 6)->assertJsonPath('data.3.value', 83879000)
            ->assertJsonPath('data.5.value', 72879287);
    }

    public function test_precise_generation_remains_preferred_over_rounded_report(): void
    {
        $this->artisan('energy:import-v5')->assertSuccessful();
        $this->artisan('energy:import-itaipu-annual-reports')->assertSuccessful();
        $rows = $this->getJson('/api/history?metric=ITAIPU_GROSS_GENERATION&asset=ITAIPU&unit=MWh&source=ITAIPU&grain=year')->assertOk()->json('data');
        $this->assertEquals(72879287, collect($rows)->first(fn ($row) => str_starts_with($row['period_start'], '2025'))['value']);
    }
}
