<?php

namespace App\Console\Commands;

use App\Services\CammesaBinationalWorkbook;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ImportCammesaBinational extends Command
{
    protected $signature = 'energy:import-cammesa-binational {--file= : Ruta a la planilla XLSX original} {--dry-run : Valida la planilla sin modificar la base}';

    protected $description = 'Importa la serie mensual Yacyretá de CAMMESA y archiva la planilla completa con procedencia';

    public function handle(CammesaBinationalWorkbook $reader): int
    {
        $path = $this->option('file') ?: storage_path('app/energiapy/hidro-binacional-cammesa.xlsx');
        if (! is_file($path)) {
            $this->error('No se encontró la planilla CAMMESA.');
            return self::FAILURE;
        }
        $data = $reader->read($path);
        $checksum = hash_file('sha256', $path);
        $collectedAt = CarbonImmutable::createFromTimestampUTC(filemtime($path))->format('Y-m-d H:i:s');
        $inserted = 0;
        if (! $this->option('dry-run')) {
            DB::transaction(function () use ($data, $checksum, $collectedAt, $path, &$inserted) {
                foreach ($data['monthly'] as $month) {
                    foreach (['D' => 'GENERATION_ENERGY', 'E' => 'ENERGY_TO_AR', 'F' => 'ENERGY_TO_PY'] as $column => $metric) {
                        $cell = $column.$month['row'];
                        $inserted += DB::table('observations')->insertOrIgnore([
                            'identity' => hash('sha256', 'CAMMESA-BINATIONAL|'.$checksum.'|'.$cell),
                            'variable_code' => $metric, 'asset_code' => 'YACYRETA', 'value' => (float) $month['cells'][$column],
                            'unit' => 'MWh', 'grain' => 'month', 'period_start' => $month['start'].' 00:00:00', 'period_end' => $month['end'].' 00:00:00',
                            'measured_at' => null, 'published_at' => null, 'collected_at' => $collectedAt,
                            'source_code' => 'CAMMESA', 'source_url' => CammesaBinationalWorkbook::URL,
                            'quality' => 'OFFICIAL_AGGREGATE', 'authority' => 'OFFICIAL_PRIMARY', 'priority' => 160,
                            'parser_confidence' => 1, 'parser_version' => 'cammesa-binational-xlsx-1', 'raw_checksum' => $checksum, 'fetch_id' => null,
                            'provenance' => json_encode(['source_file' => basename($path), 'sheet' => CammesaBinationalWorkbook::SHEET, 'cell' => $cell,
                                'import_mode' => 'manual_workbook', 'source_unit' => 'MWh', 'definition' => ['D' => 'Energía Generada NETA YACY', 'E' => 'Energía recibida YAC AR', 'F' => 'Energía recibida YAC PY'][$column],
                                'period_basis' => 'Mes calendario de la planilla; no es potencia instantánea.'], JSON_UNESCAPED_UNICODE),
                        ]);
                    }
                }
                foreach (['monthly', 'annual'] as $kind) {
                    foreach ($data[$kind] as $index => $row) {
                        DB::table('v5_records')->updateOrInsert(['dataset' => 'cammesa_hidro_binacional_'.$kind, 'row_number' => $index + 1], [
                            'checksum' => $checksum, 'payload' => json_encode($this->archiveRow($row, $kind, basename($path)), JSON_UNESCAPED_UNICODE),
                        ]);
                    }
                }
            });
            Cache::forget('energy-live');
        }
        $this->info(count($data['monthly']).' meses validados; '.count($data['annual']).' resúmenes anuales; '.($this->option('dry-run') ? 'sin cambios en la base' : $inserted.' observaciones nuevas').'.');
        return self::SUCCESS;
    }

    private function archiveRow(array $row, string $kind, string $filename): array
    {
        $names = ['D' => 'yacyreta_generacion_neta_mwh', 'E' => 'yacyreta_recibido_argentina_mwh', 'F' => 'yacyreta_recibido_paraguay_mwh',
            'G' => 'yacyreta_participacion_argentina_fraccion', 'H' => 'yacyreta_participacion_paraguay_fraccion', 'I' => 'yacyreta_caudal_entrante_m3_s',
            'J' => 'yacyreta_caudal_turbinado_m3_s', 'K' => 'yacyreta_caudal_vertido_m3_s', 'L' => 'yacyreta_cota_msnm', 'M' => 'yacyreta_rendimiento_mwh_hm3', 'N' => 'yacyreta_rendimiento_mw_por_m3_s',
            'P' => 'salto_grande_generacion_neta_mwh', 'Q' => 'salto_grande_recibido_argentina_mwh', 'R' => 'salto_grande_recibido_uruguay_mwh',
            'S' => 'salto_grande_participacion_argentina_fraccion', 'T' => 'salto_grande_participacion_uruguay_fraccion', 'U' => 'salto_grande_caudal_entrante_m3_s',
            'V' => 'salto_grande_caudal_turbinado_m3_s', 'W' => 'salto_grande_caudal_vertido_m3_s', 'X' => 'salto_grande_cota_msnm', 'Y' => 'salto_grande_rendimiento_mwh_hm3', 'Z' => 'salto_grande_rendimiento_mw_por_m3_s'];
        $payload = ['period_start' => $row['start'], 'period_end' => $row['end'], 'grain' => $row['grain'] ?? 'month'];
        foreach ($names as $column => $name) {
            $value = $row['cells'][$column] ?? '';
            $payload[$name] = is_numeric($value) ? (float) $value : $value;
        }
        return $payload + ['source_code' => 'CAMMESA', 'source_url' => CammesaBinationalWorkbook::URL, 'source_file' => $filename,
            'sheet' => CammesaBinationalWorkbook::SHEET, 'source_row' => $row['row'],
            'notes' => 'Energía en MWh; caudales y cotas son promedios del período. Participaciones conservadas como fracción 0–1 de las celdas originales.'];
    }
}
