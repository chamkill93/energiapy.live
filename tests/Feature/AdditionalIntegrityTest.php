<?php

namespace Tests\Feature;

use App\Services\GisService;
use App\Services\OperationalMetrics;
use App\Services\SourceCollector;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdditionalIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_monitoring_shows_live_map_and_historical_data_remain_available(): void
    {
        foreach (['/', '/monitoreo'] as $path) {
            $this->get($path)->assertOk()->assertSee('id="map"', false)
                ->assertSee('<a href="/monitoreo" class="nav-link active"', false)
                ->assertDontSee('Mapa en Vivo')->assertDontSee('Monitoreo histórico')
                ->assertDontSee('/publications.js', false);
        }
        $this->getJson('/api/monitoring/summary')->assertOk()->assertJsonPath('baselines', []);
        $this->artisan('energy:import-v5')->assertSuccessful();
        $rows=$this->getJson('/api/monitoring/summary')->assertOk()->json('baselines');
        $this->assertNotEmpty($rows);
        $this->assertSame('HISTORICAL',$rows[0]['state']);
        $this->assertNotEmpty($rows[0]['provenance']['record_id']);
    }

    public function test_bcp_keeps_reference_date_and_currency_units(): void
    {
        $r = (new SourceCollector)->parseBcp('<input id="fecha" value="11/09/2026"><table><tr><td>Dólar</td><td>USD</td><td>1</td><td>6.014,84</td></tr></table>')[0];
        $this->assertSame(6014.84, $r['value']);
        $this->assertSame('PYG/USD', $r['unit']);
        $this->assertNull($r['measured_at']);
        $this->assertSame('2026-09-11', $r['provenance']['publication_date']);
    }

    public function test_noaa_marks_forecast_and_rolls_year(): void
    {
        $r = (new SourceCollector)->parseNoaa('<body>Issued December 2026<table id="probabilities-table"><tr><th>DJF</th><td>30</td><td>40</td><td>30</td></tr><tr><th>JFM</th><td>20</td><td>50</td><td>30</td></tr></table></body>');
        $this->assertCount(6, $r);
        $this->assertSame('2027-01-01 00:00:00', $r[3]['period_start']);
        $this->assertSame('OFFICIAL_FORECAST', $r[0]['quality']);
    }

    public function test_noaa_rejects_invalid_probabilities(): void
    {
        $this->expectException(\RuntimeException::class);
        (new SourceCollector)->parseNoaa('<body>Issued September 2026<table id="probabilities-table"><tr><th>SON</th><td>60</td><td>70</td><td>30</td></tr></table></body>');
    }

    public function test_international_does_not_substitute_other_countries(): void
    {
        $this->expectException(\RuntimeException::class);
        (new SourceCollector)->parseInternational("din_instante;nom_paisdestino;val_intercambiomwmed\n2026-09-11 12:00:00;Argentina;120\n");
    }

    public function test_hydrology_preserves_report_date_without_inventing_time(): void
    {
        $r = (new SourceCollector)->parseEbyHydrology('<h2 class="page-title">Cota: 82.82 msnm Resumen Ejecutivo 6078 11-09-2026</h2>')[0];
        $this->assertSame(82.82, $r['value']);
        $this->assertNull($r['measured_at']);
    }

    public function test_full_day_needs_all_24_hours_and_missing_interval_yields_no_energy(): void
    {
        $start = CarbonImmutable::parse('2026-09-10', 'America/Asuncion')->utc();
        for ($i = 0; $i < 24; $i++) {
            $time = $start->addHours($i)->format('Y-m-d H:i:s');
            DB::table('observations')->insert(['identity' => hash('sha256', 'test-'.$i), 'variable_code' => 'DEMAND_MW', 'asset_code' => 'PY_SIN', 'value' => 100, 'unit' => 'MW', 'grain' => 'hour', 'period_start' => $time, 'period_end' => $time, 'measured_at' => $time, 'collected_at' => $time, 'source_code' => 'TEST_FIXTURE', 'source_url' => 'https://example.test/fixture', 'quality' => 'OFFICIAL_HOURLY', 'authority' => 'OFFICIAL_PRIMARY', 'parser_version' => 'test-only', 'raw_checksum' => str_repeat('0', 64), 'provenance' => '{}']);
        }
        $service = new OperationalMetrics;
        $full = $service->period('DEMAND_MW', 'PY_SIN', 'today', '2026-09-10');
        $this->assertSame(24, $full['expected_hours']);
        $this->assertTrue($full['complete']);
        $this->assertEquals(2400, $full['energy_mwh']);
        DB::table('observations')->where('id', 12)->delete();
        $this->assertNull($service->period('DEMAND_MW', 'PY_SIN', 'today', '2026-09-10')['energy_mwh']);
    }

    public function test_gis_rejects_unverified_geometry(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new GisService)->validateFeature(['geometry' => ['type' => 'Point', 'coordinates' => [-57, -25]], 'properties' => ['quality' => 'MOCKUP']]);
    }

    public function test_regional_public_gis_and_territorial_reference_are_exposed_with_scope(): void
    {
        (new GisService)->validateFeature([
            'geometry' => ['type' => 'LineString', 'coordinates' => [[-57.6, -25.3], [-56.7, -24.9]]],
            'properties' => [
                'code' => 'UPME-TEST',
                'quality' => 'OFFICIAL_REGIONAL_GIS',
                'source_url' => 'https://geo.upme.gov.co/server/rest/services/example',
                'collected_at' => '2026-09-12T12:00:00Z',
            ],
        ]);

        $this->artisan('energy:import-v5')->assertSuccessful();
        $response = $this->getJson('/api/data/territorial-demand')->assertOk();
        $this->assertCount(18, $response->json('features'));
        $response->assertJsonPath('meta.status', 'historical_reference')
            ->assertJsonPath('meta.period_year', '2024');
        $this->assertStringContainsString('no representa consumo en vivo', $response->json('meta.notice'));
        $this->assertStringContainsString('no al departamento individual', $response->json('features.0.properties.scope_notice'));
    }

    public function test_tariff_history_calculates_numeric_outputs_from_provenance(): void
    {
        $r = $this->getJson('/api/citizenship/history')->assertOk()->json('data');
        $this->assertNotEmpty($r);
        foreach ($r as $x) {
            $this->assertIsFloat($x['usd_mwh']);
            $this->assertEqualsWithDelta($x['nominal_gs_kwh'] * 1000 / $x['provenance']['annual_fx_pyg_usd'], $x['usd_mwh'], 0.00001);
        }
    }
}
