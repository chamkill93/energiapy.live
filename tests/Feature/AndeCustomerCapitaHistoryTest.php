<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AndeCustomerCapitaHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_historical_series_preserve_methodology_and_are_idempotent(): void
    {
        $this->artisan('energy:import-ande-customer-capita-history')->assertSuccessful();
        $this->artisan('energy:import-ande-customer-capita-history')->assertSuccessful();
        $this->assertSame(42, DB::table('observations')->count());
        $this->getJson('/api/history?metric=ANDE_BILLED_CUSTOMERS_NATIONAL&asset=PY_SIN&unit=count&source=ANDE&grain=year')
            ->assertOk()->assertJsonPath('meta.total', 21)->assertJsonPath('data.0.value', 976279)->assertJsonPath('data.20.value', 1583969);
        $this->getJson('/api/history?metric=ANDE_BILLED_KWH_PER_CAPITA&asset=PY_SIN&unit=kWh%2Fperson%2Fyear&source=ANDE&grain=year')
            ->assertOk()->assertJsonPath('meta.total', 21)->assertJsonPath('data.20.value', 2627);
        $row = DB::table('observations')->where('variable_code', 'ANDE_BILLED_KWH_PER_CAPITA')->where('period_start', '2022-01-01 00:00:00')->first();
        $this->assertSame(2022, json_decode($row->provenance, true)['methodology_break_year']);
    }
}
