<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HistoryCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_exposes_all_retained_tables_and_native_observation_filters(): void
    {
        $this->artisan('energy:import-v5')->assertSuccessful();
        $catalog = $this->getJson('/api/history/catalog')->assertOk()->json();
        $this->assertCount(DB::table('v5_records')->distinct()->pluck('dataset')->count(), $catalog['datasets']);
        $this->assertSame(4321, $catalog['meta']['workbook_rows']);
        $this->assertSame('Generación, consumo y demanda', collect($catalog['categories'])->firstWhere('key', 'energy')['label']);
        $tariffs = collect($catalog['datasets'])->firstWhere('key', 'energiapy_tariff_normalized_v5');
        $this->assertSame('tariffs', $tariffs['category']);
        $this->assertContains('Tarifa real (Gs 2025/kWh)', $tariffs['columns']);
        $this->assertSame('Año', $tariffs['date_column']);
        $demand = collect($catalog['metrics'])->firstWhere('key', 'PY_CONSUMPTION_ENERGY');
        $this->assertSame('Consumo anual Paraguay', $demand['label']);
        $this->assertContains('year', $demand['grains']);
        $this->assertContains('PY_SIN', $demand['assets']);
        $this->assertNotEmpty($demand['series']);
        $this->getJson('/api/history?metric=PY_MAX_DEMAND&grain=event')->assertOk();
        $this->getJson('/api/history?grain=invalid')->assertUnprocessable();
    }

    public function test_workbook_table_search_and_calendar_year_filters_preserve_original_cells(): void
    {
        foreach ([['Año' => '2024', 'Categoría' => 'Hogares', 'Valor' => '=A1*2'], ['Año' => '2025', 'Categoría' => 'Empresas', 'Valor' => '20']] as $i => $payload) {
            DB::table('v5_records')->insert(['dataset' => 'sample_years', 'checksum' => str_repeat('a', 64), 'row_number' => $i + 1, 'payload' => json_encode($payload)]);
        }
        $this->getJson('/api/history?dataset=sample_years&from=2024-06-01&to=2024-07-01')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('meta.date_filter_applied', true)
            ->assertJsonPath('data.0.Valor', '=A1*2')->assertJsonPath('data.0._row_number', 1);
        $this->getJson('/api/history?dataset=sample_years&q=Empresas')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.Valor', '20');
        $this->getJson('/api/history?dataset=does_not_exist')->assertNotFound();
    }

    public function test_table_without_a_calendar_field_is_not_silently_removed_by_date_filters(): void
    {
        DB::table('v5_records')->insert(['dataset' => 'terms', 'checksum' => str_repeat('b', 64), 'row_number' => 1, 'payload' => json_encode(['Concepto' => 'Precio por energía', 'Unidad' => 'USD/MWh'])]);
        $this->getJson('/api/history?dataset=terms&from=2026-01-01')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('meta.date_filter_applied', false)->assertJsonPath('meta.date_column', null);
    }

    public function test_observations_keep_units_separate_and_allow_explicit_source_selection(): void
    {
        $base = ['variable_code' => 'ENERGY', 'asset_code' => 'TEST', 'value' => 10, 'unit' => 'MWh', 'grain' => 'season', 'period_start' => '2025-01-01', 'period_end' => '2025-03-31', 'measured_at' => null, 'published_at' => null, 'collected_at' => '2025-04-01', 'source_code' => 'A', 'source_url' => 'https://example.org/a', 'quality' => 'OFFICIAL_AGGREGATE', 'authority' => 'OFFICIAL_PRIMARY', 'priority' => 50, 'parser_confidence' => 1, 'parser_version' => 'test', 'raw_checksum' => str_repeat('c', 64), 'fetch_id' => null, 'provenance' => '{}'];
        foreach ([$base, array_replace($base, ['value' => 11, 'priority' => 100, 'source_code' => 'B']), array_replace($base, ['unit' => 'GWh', 'value' => .01])] as $i => $row) {
            DB::table('observations')->insert($row + ['identity' => hash('sha256', (string) $i)]);
        }
        $this->getJson('/api/history?metric=ENERGY&grain=season')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/history?metric=ENERGY&grain=season&unit=MWh')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.value', 11);
        $this->getJson('/api/history?metric=ENERGY&grain=season&unit=MWh&source=A')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.value', 10);
    }

    public function test_workbook_table_paginates_without_omitting_rows(): void
    {
        for ($i = 1; $i <= 501; $i++) {
            DB::table('v5_records')->insert(['dataset' => 'many_rows', 'checksum' => str_repeat('d', 64), 'row_number' => $i, 'payload' => json_encode(['Código' => (string) $i])]);
        }
        $this->getJson('/api/history?dataset=many_rows')->assertOk()->assertJsonCount(500, 'data')->assertJsonPath('meta.total', 501);
        $this->getJson('/api/history?dataset=many_rows&page=2')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.Código', '501');
    }
}
