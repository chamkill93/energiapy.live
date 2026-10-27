<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CammesaHistoryImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_official_workbook_import_is_traceable_and_idempotent(): void
    {
        $this->artisan('energy:import-cammesa-binational')->assertSuccessful();
        $this->assertSame(351, DB::table('observations')->where('source_code', 'CAMMESA')->count());
        $this->assertSame(117, DB::table('v5_records')->where('dataset', 'cammesa_hidro_binacional_monthly')->count());
        $record = DB::table('observations')->where('source_code', 'CAMMESA')->where('variable_code', 'ENERGY_TO_AR')
            ->where('period_start', '2026-09-01 00:00:00')->first();
        $this->assertNotNull($record);
        $this->assertEqualsWithDelta(1624650.36, $record->value, 0.01);
        $this->assertSame('MWh', $record->unit);
        $this->assertSame('E138', json_decode($record->provenance, true)['cell']);
        $this->artisan('energy:import-cammesa-binational')->assertSuccessful();
        $this->assertSame(351, DB::table('observations')->where('source_code', 'CAMMESA')->count());
        $this->assertSame(117, DB::table('v5_records')->where('dataset', 'cammesa_hidro_binacional_monthly')->count());
    }
}
