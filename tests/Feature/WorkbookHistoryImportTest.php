<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorkbookHistoryImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_workbook_sheets_keep_cell_values_and_uncomputed_formulas(): void
    {
        $this->artisan('energy:archive-workbook-v5')->assertSuccessful();
        $this->assertSame(36, DB::table('v5_records')->where('dataset', 'like', 'workbook_v5_%')->distinct()->count('dataset'));
        $chart = json_decode(DB::table('v5_records')->where('dataset', 'workbook_v5_chart_data')->where('row_number', 2)->value('payload'), true);
        $this->assertSame('2003', $chart['cells']['A2']['value']);
        $this->assertSame('6183000', $chart['cells']['B2']['value']);
        $summary = json_decode(DB::table('v5_records')->where('dataset', 'workbook_v5_retail_tariff_summary')->where('row_number', 3)->value('payload'), true);
        $this->assertSame("'Retail_Tariff_History'!B36", $summary['cells']['B3']['formula']);
        $this->assertSame('', $summary['cells']['B3']['value']);
        $count = DB::table('v5_records')->where('dataset', 'like', 'workbook_v5_%')->count();
        $this->artisan('energy:archive-workbook-v5')->assertSuccessful();
        $this->assertSame($count, DB::table('v5_records')->where('dataset', 'like', 'workbook_v5_%')->count());
    }

    public function test_prices_infrastructure_and_economic_series_are_imported_once_with_original_provenance(): void
    {
        $this->artisan('energy:import-v5')->assertSuccessful();
        $this->artisan('energy:import-workbook-history')->assertSuccessful();
        $count = DB::table('observations')->where('parser_version', 'v5-workbook-history-1')->count();
        $this->assertGreaterThan(500, $count);
        $price = DB::table('observations')->where('variable_code', 'ANDE_TARIFF_RESIDENTIAL')->where('period_start', '1990-01-01 00:00:00')->first();
        $this->assertEquals(56.1, $price->value);
        $this->assertSame('Gs/kWh', $price->unit);
        $this->assertSame('energiapy_prices_v5', json_decode($price->provenance, true)['dataset']);
        $this->getJson('/api/history?metric=TX_LINE_500KV&asset=PY_GRID&grain=year&unit=km&source=ANDE')->assertOk()->assertJsonPath('meta.total', 21);
        $royalty = DB::table('observations')->where('variable_code', 'ITAIPU_ROYALTIES_CASH')->where('period_start', '1989-01-01 00:00:00')->first();
        $this->assertEquals(36734560, $royalty->value);
        $this->assertSame('USD', $royalty->unit);
        $this->assertSame('PY_TREASURY', json_decode($royalty->provenance, true)['original']['payee']);
        $this->artisan('energy:import-workbook-history')->assertSuccessful();
        $this->assertSame($count, DB::table('observations')->where('parser_version', 'v5-workbook-history-1')->count());
    }

    public function test_context_retains_category_quality_and_provisional_macro_data(): void
    {
        $this->artisan('energy:import-v5')->assertSuccessful();
        $this->artisan('energy:import-workbook-context')->assertSuccessful();
        $customers = DB::table('observations')->where('variable_code', 'ANDE_CUSTOMER_COUNT')->where('asset_code', 'ANDE_CUSTOMER_RESIDENTIAL')->first();
        $this->assertEquals(1482966, $customers->value);
        $this->assertSame('count', $customers->unit);
        $this->assertSame('2025-01-01 00:00:00', $customers->period_start);
        $macro = DB::table('observations')->where('variable_code', 'CPI_ANNUAL_INFLATION_WDI')->where('period_start', '1990-01-01 00:00:00')->first();
        $this->assertEqualsWithDelta(37.2599561799201, $macro->value, 0.000001);
        $this->assertSame('percent', $macro->unit);
        $this->assertSame('PROVISIONAL', $macro->quality);
        $this->assertSame('Fracción original × 100.', json_decode($macro->provenance, true)['calculation']);
        $count = DB::table('observations')->where('parser_version', 'v5-workbook-context-1')->count();
        $this->artisan('energy:import-workbook-context')->assertSuccessful();
        $this->assertSame($count, DB::table('observations')->where('parser_version', 'v5-workbook-context-1')->count());
    }
}
