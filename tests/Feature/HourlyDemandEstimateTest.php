<?php

namespace Tests\Feature;

use App\Services\EnergyData;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HourlyDemandEstimateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_uses_local_hour_and_preserves_published_monthly_energy(): void
    {
        CarbonImmutable::setTestNow('2026-10-26 15:00:00 UTC');
        try {
            $this->artisan('energy:import-ande-august-2026')->assertSuccessful();
            $service = app(EnergyData::class);
            $reference = $service->demandMonthlyReference();
            $expected = [0 => .70, 7 => .70, 8 => .85, 10 => .85, 11 => 1.0, 14 => 1.0, 15 => .85, 17 => .85, 18 => 1.0, 21 => 1.0, 22 => .70, 23 => .70];
            foreach ($expected as $hour => $weight) {
                $time = CarbonImmutable::create(2026, 10, 26, $hour, 0, 0, 'America/Asuncion');
                $estimate = $service->demandHourlyEstimate($time);
                $this->assertEquals($weight, $estimate['weight']);
                $this->assertEqualsWithDelta($reference['value'] * $weight / .8375, $estimate['value'], .00001);
                $this->assertSame('MODELED_HOURLY_REFERENCE', $estimate['kind']);
            }
            $sum = 0.0;
            for ($hour = 0; $hour < 24; $hour++) {
                $sum += $service->demandHourlyEstimate(CarbonImmutable::create(2026, 8, 1, $hour, 0, 0, 'America/Asuncion'))['value'];
            }
            $this->assertEqualsWithDelta(2605369, $sum * 31, .01);
            $r = $this->getJson('/api/live')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
            $r->assertJsonPath('system.demand_mw', null)->assertJsonPath('system.demand_estimate.kind', 'MODELED_HOURLY_REFERENCE');
            $this->assertSame('https://www.ande.gov.py/interna.php?id=15615', $r->json('system.demand_estimate.source_url'));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_no_estimate_is_produced_without_a_monthly_reference(): void
    {
        $this->assertNull(app(EnergyData::class)->demandHourlyEstimate());
    }

    public function test_cached_live_payload_recomputes_at_local_hour_boundary(): void
    {
        CarbonImmutable::setTestNow('2026-10-26 13:59:59 UTC');
        try {
            $this->artisan('energy:import-ande-august-2026')->assertSuccessful();
            $this->getJson('/api/live')->assertOk()->assertJsonPath('system.demand_estimate.weight', 0.85);
            CarbonImmutable::setTestNow('2026-10-26 14:00:00 UTC');
            $this->getJson('/api/live')->assertOk()->assertJsonPath('system.demand_estimate.weight', 1);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_historical_month_with_dst_uses_actual_hour_count(): void
    {
        CarbonImmutable::setTestNow('2026-10-26 15:00:00 UTC');
        try {
            $this->artisan('energy:import-ande-august-2026')->assertSuccessful();
            $row = (array) DB::table('observations')->where('variable_code', 'PY_CONSUMPTION_ENERGY')->where('grain', 'month')->where('period_start', '2026-08-01 00:00:00')->first();
            DB::table('observations')->delete();
            unset($row['id']);
            $row['identity'] = hash('sha256', 'hourly-estimate-dst-test');
            $row['period_start'] = '2018-10-01 00:00:00';
            $row['period_end'] = '2018-10-31 00:00:00';
            $row['value'] = 100000;
            DB::table('observations')->insert($row);
            $service = app(EnergyData::class);
            $estimate = $service->demandHourlyEstimate(CarbonImmutable::parse('2026-10-26 12:00:00', 'America/Asuncion'));
            $this->assertEquals(743, $estimate['duration_hours']);
            $this->assertEqualsWithDelta(622.4 / 743, $estimate['normalization_factor'], .000001);
            $this->assertEqualsWithDelta(100000, $estimate['value'] * 622.4, .001);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_model_does_not_replace_an_official_current_observation(): void
    {
        CarbonImmutable::setTestNow('2026-10-26 15:00:00 UTC');
        try {
            $this->artisan('energy:import-ande-august-2026')->assertSuccessful();
            $row = (array) DB::table('observations')->where('variable_code', 'PY_CONSUMPTION_ENERGY')->where('grain', 'month')->where('period_start', '2026-08-01 00:00:00')->first();
            unset($row['id']);
            $row['identity'] = hash('sha256', 'hourly-estimate-observation-test');
            $row['variable_code'] = 'DEMAND_MW';
            $row['value'] = 4123;
            $row['unit'] = 'MW';
            $row['grain'] = 'hour';
            $row['quality'] = 'OFFICIAL_HOURLY';
            $row['measured_at'] = '2026-10-26 15:00:00';
            $row['period_start'] = '2026-10-26 15:00:00';
            $row['period_end'] = '2026-10-26 16:00:00';
            DB::table('observations')->insert($row);
            $r = $this->getJson('/api/live')->assertOk();
            $this->assertEquals(4123, $r->json('system.demand_mw'));
            $this->assertSame('MODELED_HOURLY_REFERENCE', $r->json('system.demand_estimate.kind'));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }
}
