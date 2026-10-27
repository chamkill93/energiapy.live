<?php

namespace Tests\Feature;

use App\Services\EnergyData;
use App\Services\ProjectionService;
use App\Services\SourceCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EnergyIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_public_pages_render(): void
    {
        foreach (['/', '/monitoreo', '/historico', '/proyecciones', '/ciudadania', '/economia', '/fuentes', '/metodologia', '/sobre', '/contacto'] as $url) {
            $this->get($url)->assertOk()->assertSee('EnergiaPY');
        }
    }

    public function test_live_never_fills_missing_data_with_mockup_numbers(): void
    {
        $r = $this->getJson('/api/live')->assertOk()->assertJsonPath('system.demand_mw', null)->assertJsonPath('meta.status', 'no_data')->assertJsonPath('flows', []);
        foreach ($r->json('plants') as $p) {
            $this->assertNull($p['total_mw']);
            $this->assertTrue($p['stale']);
        }
    }

    public function test_v5_import_is_idempotent_and_history_is_not_live(): void
    {
        $this->artisan('energy:import-v5')->assertSuccessful();
        $count = DB::table('observations')->count();
        $this->assertSame(1524, $count);
        $this->assertSame(4321, DB::table('v5_records')->count());
        $this->artisan('energy:import-v5')->assertSuccessful();
        $this->assertSame($count, DB::table('observations')->count());
        $this->getJson('/api/live')->assertJsonPath('system.demand_mw', null);
        $r = $this->getJson('/api/history?metric=PY_CONSUMPTION_ENERGY&grain=year')->assertOk();
        $this->assertGreaterThan(15, count($r->json('data')));
        foreach ($r->json('data') as $o) {
            $this->assertSame('HISTORICAL', $o['state']);
            $this->assertNotEmpty($o['source_url']);
            $this->assertNotEmpty($o['raw_checksum']);
        }
    }

    public function test_eby_has_no_fabricated_measurement_time(): void
    {
        $rows = (new SourceCollector)->parseEby('<div class="counter"><span data-to="1200">1.200</span> mw <h6>de potencia generada en la última hora</h6></div>');
        $this->assertSame(1200.0, $rows[0]['value']);
        $this->assertNull($rows[0]['measured_at']);
    }

    public function test_eby_uses_the_counter_target_rendered_by_the_official_page(): void
    {
        $row = (new SourceCollector)->parseEby('<div class="counter"><span data-to="1100">1200</span> mw <h6>de potencia generada en la última hora</h6></div>')[0];
        $this->assertSame(1100.0, $row['value']);
        $this->assertSame(1200.0, $row['provenance']['html_fallback_value']);
        $this->assertSame(1100.0, $row['provenance']['rendered_counter_target']);
    }

    public function test_failed_parser_preserves_last_good_observation(): void
    {
        Http::fake(['eby.gov.py/*' => Http::sequence()->push('<div class="counter"><span data-to="1200">1200</span> mw <h6>de potencia generada en la última hora</h6></div>')->push('<div>Changed markup</div>')]);
        $collector = new SourceCollector;
        $first = $collector->run('EBY_CURRENT_POWER');
        $this->assertSame('ok', $first['status']);
        $second = $collector->run('EBY_CURRENT_POWER');
        $this->assertSame('error', $second['status']);
        $this->assertSame(1, DB::table('observations')->count());
        $this->assertSame(1200.0, app(EnergyData::class)->latest('GENERATION_MW', 'YACYRETA')['value']);
        $this->assertDatabaseHas('source_health', ['source_code' => 'EBY_CURRENT_POWER', 'status' => 'error', 'failures' => 1]);
    }

    public function test_dmh_retains_station_timestamp_and_orientative_quality(): void
    {
        $raw = json_encode(['estaciones' => [['metadatos' => ['codigo' => 'TEST', 'nombre' => 'TEST ONLY', 'latitud' => -25, 'longitud' => -57], 'ultima_observacion' => ['fecha' => ['utc' => '2026-01-01T00:00:00+0000'], 'temperatura_aire' => ['valor' => 22]]]]]);
        $r = (new SourceCollector)->parseDmh($raw)[0];
        $this->assertSame('OFFICIAL_ORIENTATIVE', $r['quality']);
        $this->assertSame('2026-01-01 00:00:00', $r['measured_at']);
        $this->assertSame('°C', $r['unit']);
    }

    public function test_ons_brasilia_hour_is_normalised_and_selected_as_latest_operational_value(): void
    {
        $raw = "din_instante;val_itaipu_total;val_itaipu_br\n2026-09-10 12:00:00;1000;700\n";
        Http::fake(['ons-aws-prod-opendata.s3.amazonaws.com/*' => Http::response($raw)]);
        $result = (new SourceCollector)->run('ONS_ITAIPU_HOURLY');
        $this->assertSame('ok', $result['status']);
        $latest = app(EnergyData::class)->latest('GENERATION_MW', 'ITAIPU');
        $this->assertSame(1000.0, $latest['value']);
        $this->assertSame('2026-09-10 15:00:00', $latest['measured_at']);
        $this->assertDatabaseHas('observations', ['quality' => 'OFFICIAL_HOURLY', 'measured_at' => '2026-09-10 15:00:00']);
        $itaipu = collect(app(EnergyData::class)->plants())->firstWhere('code', 'ITAIPU');
        $this->assertSame(300.0, $itaipu['to_py_mw']);
        $this->assertSame('DERIVED_FROM_OFFICIAL_SAME_INTERVAL', $itaipu['to_py_provenance']['quality']);
    }

    public function test_replay_requires_real_hourly_demand_and_valid_dates(): void
    {
        $this->getJson('/api/history/replay?date=2026-09-10')->assertOk()->assertJsonPath('eligible', false)->assertJsonPath('coverage', 0);
        $this->getJson('/api/history/replay?date=not-a-date')->assertUnprocessable();
        $this->getJson('/api/history?grain=invalid')->assertUnprocessable();
    }

    public function test_scenarios_report_ranges_and_no_crossing_for_negative_growth(): void
    {
        $service = new ProjectionService;
        $r = $service->calculate(1000, 2025, .05, 2000);
        $this->assertSame(2040, $r['base_year']);
        $this->assertLessThan($r['latest_year'], $r['earliest_year']);
        $this->assertNull($service->calculate(1000, 2025, -.05, 2000)['base_year']);
        $this->assertSame('DERIVED_SCENARIO', $r['quality']);
    }

    public function test_forecast_run_records_input_provenance(): void
    {
        $this->artisan('energy:import-v5')->assertSuccessful();
        $this->postJson('/api/projections', ['growth' => 5, 'capacity' => 45000, 'scenario' => 'Base'])->assertOk()->assertJsonPath('model_version', 'scenario-1.0');
        $run = DB::table('forecast_runs')->first();
        $this->assertNotEmpty(json_decode($run->inputs, true)['input_observation_ids']);
        $this->postJson('/api/projections', ['growth' => 200, 'capacity' => -1, 'scenario' => 'Base'])->assertUnprocessable();
    }

    public function test_gis_has_official_provenance_and_never_fake_transmission(): void
    {
        $this->getJson('/api/gis/grid')->assertOk()->assertJsonPath('features', []);
        $r = $this->getJson('/api/gis/departments')->assertOk();
        $this->assertCount(18, $r->json('features'));
        $this->assertStringContainsString('ine.gov.py', $r->json('meta.source_url'));
    }
}
