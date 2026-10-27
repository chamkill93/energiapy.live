<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VmmeMonthlySinTest extends TestCase
{
    use RefreshDatabase;

    public function test_monthly_sin_reports_preserve_comparison_years_and_are_idempotent(): void
    {
        $this->artisan('energy:import-vmme-monthly-sin')->assertSuccessful();
        $this->artisan('energy:import-vmme-monthly-sin')->assertSuccessful();
        $this->assertSame(24, DB::table('observations')->count());
        $this->getJson('/api/history?metric=PY_CONSUMPTION_ENERGY&asset=PY_SIN&unit=MWh&grain=month&source=VMME')
            ->assertOk()->assertJsonPath('meta.total', 24)
            ->assertJsonPath('data.0.value', 2554752)->assertJsonPath('data.23.value', 3055307)
            ->assertJsonPath('data.23.quality', 'OFFICIAL_PRELIMINARY');
        $february = DB::table('observations')->where('period_start', '2024-02-01 00:00:00')->first();
        $this->assertSame('2024-02-29 00:00:00', $february->period_end);
    }

    public function test_later_ande_reference_overrides_preliminary_august(): void
    {
        $this->artisan('energy:import-vmme-monthly-sin')->assertSuccessful();
        $this->artisan('energy:import-ande-august-2026')->assertSuccessful();
        $rows = $this->getJson('/api/history?metric=PY_CONSUMPTION_ENERGY&asset=PY_SIN&unit=MWh&grain=month')->assertOk()->json('data');
        $august = collect($rows)->first(fn ($row) => str_starts_with($row['period_start'], '2025-08'));
        $this->assertEquals(2244268, $august['value']);
        $this->assertSame('ANDE', $august['source']);
    }

    public function test_official_agency_revises_june_and_fills_2026_gap(): void
    {
        $this->artisan('energy:import-vmme-monthly-sin')->assertSuccessful();
        $this->artisan('energy:import-agency-june-2026')->assertSuccessful();
        $this->artisan('energy:import-agency-june-2026')->assertSuccessful();
        $this->assertSame(26, DB::table('observations')->count());
        $rows = $this->getJson('/api/history?metric=PY_CONSUMPTION_ENERGY&asset=PY_SIN&unit=MWh&grain=month')->assertOk()->json('data');
        $june2025 = collect($rows)->first(fn ($row) => str_starts_with($row['period_start'], '2025-06'));
        $june2026 = collect($rows)->first(fn ($row) => str_starts_with($row['period_start'], '2026-06'));
        $this->assertEquals(2067602, $june2025['value']);
        $this->assertEquals(2310632, $june2026['value']);
        $this->assertSame('AGENCIA_IP_ANDE', $june2026['source']);
    }
}
