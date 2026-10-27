<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CammesaAnnualSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_annual_summaries_reconcile_and_partial_year_is_separate(): void
    {
        $this->artisan('energy:import-cammesa-annual')->assertSuccessful();
        $this->artisan('energy:import-cammesa-annual')->assertSuccessful();
        $this->assertSame(30, DB::table('observations')->count());
        $this->getJson('/api/history?metric=GENERATION_ENERGY&asset=YACYRETA&unit=MWh&source=CAMMESA&grain=year')
            ->assertOk()->assertJsonPath('meta.total', 9)->assertJsonPath('data.0.period_start', '2017-01-01 00:00:00');
        $this->getJson('/api/history?metric=ENERGY_TO_PY&asset=YACYRETA&unit=MWh&source=CAMMESA&grain=year')
            ->assertOk()->assertJsonPath('meta.total', 9)->assertJsonPath('data.7.value', 5107240.414);
        $this->getJson('/api/history?metric=ENERGY_TO_AR&asset=YACYRETA&unit=MWh&source=CAMMESA&grain=YTD')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.period_end', '2026-09-30 00:00:00');
        $row = DB::table('observations')->where('variable_code', 'ENERGY_TO_PY')->where('grain', 'year')->where('period_start', '2024-01-01 00:00:00')->first();
        $this->assertSame('F14', json_decode($row->provenance, true)['cell']);
    }
}
