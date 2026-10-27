<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AndeDistributionLossesTest extends TestCase
{
    use RefreshDatabase;

    public function test_official_loss_series_keeps_dates_sources_and_no_duplicates(): void
    {
        $this->artisan('energy:import-ande-distribution-losses')->assertSuccessful();
        $this->artisan('energy:import-ande-distribution-losses')->assertSuccessful();
        $this->assertSame(3, DB::table('observations')->count());
        $this->getJson('/api/history?metric=DISTRIBUTION_LOSS_RATE&asset=PY_GRID&unit=percent&source=ANDE&grain=year')
            ->assertOk()->assertJsonPath('meta.total', 3)->assertJsonPath('data.0.value', 23.4)
            ->assertJsonPath('data.1.value', 21.9)->assertJsonPath('data.2.value', 20.03);
    }
}
