<?php

namespace Tests\Feature;

use App\Services\EnergyData;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HistoricalDemandReferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_completed_month_is_separate_from_actual_demand_and_acaray_supply(): void
    {
        CarbonImmutable::setTestNow('2026-10-26 15:00:00 UTC');
        try {
            $this->artisan('energy:import-ande-august-2026')->assertSuccessful();
            $this->artisan('energy:import-ande-august-2026')->assertSuccessful();
            $this->assertSame(9, DB::table('observations')->count());
            $r = $this->getJson('/api/live')->assertOk()->assertJsonPath('system.demand_mw', null)
                ->assertJsonPath('system.demand_reference.kind', 'HISTORICAL_MONTHLY_AVERAGE')
                ->assertJsonPath('system.demand_reference.period_start', '2026-08-01')
                ->assertJsonPath('system.demand_reference.duration_hours', 744)
                ->assertJsonPath('system.demand_reference.source_energy_mwh', 2605369);
            $this->assertEqualsWithDelta(2605369 / 744, $r->json('system.demand_reference.value'), 0.000001);
            $acaray = collect($r->json('plants'))->firstWhere('code', 'ACARAY');
            $this->assertNull($acaray['total_mw']);
            $this->assertEquals(58485, $acaray['to_py_monthly']['value']);
            $this->assertSame('HISTORICAL', $acaray['to_py_monthly']['state']);
            $this->assertSame(2400558.0, (float) DB::table('observations')->where('asset_code', 'ITAIPU')->where('grain', 'month')->value('value'));
            $this->assertSame('OFFICIAL_APPROXIMATE', DB::table('observations')->where('asset_code', 'SOLAR_PTO_ESPERANZA')->where('grain', 'month')->value('quality'));
            $this->assertSame(2, DB::table('observations')->where('variable_code', 'PY_CONSUMPTION_ENERGY')->where('grain', 'YTD')->count());
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_incomplete_calendar_month_is_not_used_as_reference(): void
    {
        CarbonImmutable::setTestNow('2026-10-26 15:00:00 UTC');
        try {
            $this->artisan('energy:import-ande-august-2026')->assertSuccessful();
            $base = (array) DB::table('observations')->where('variable_code', 'PY_CONSUMPTION_ENERGY')->where('grain', 'month')->where('period_start', '2026-08-01 00:00:00')->first();
            unset($base['id']);
            $base['identity'] = hash('sha256', 'incomplete-month-test');
            $base['period_start'] = '2026-09-01 00:00:00';
            $base['period_end'] = '2026-09-15 00:00:00';
            DB::table('observations')->insert($base);
            $this->assertSame('2026-08-01', app(EnergyData::class)->demandMonthlyReference()['period_start']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }
}
