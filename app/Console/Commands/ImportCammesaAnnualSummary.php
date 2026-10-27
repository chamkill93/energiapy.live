<?php

namespace App\Console\Commands;

use App\Services\CammesaBinationalWorkbook;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ImportCammesaAnnualSummary extends Command
{
    protected $signature = 'energy:import-cammesa-annual {--file= : Ruta a la planilla XLSX original}';
    protected $description = 'Importa los resúmenes anuales validados de Yacyretá de la planilla Hidro Binacional';

    public function handle(CammesaBinationalWorkbook $reader): int
    {
        $path = $this->option('file') ?: storage_path('app/energiapy/hidro-binacional-cammesa.xlsx');
        if (! is_file($path)) {
            $this->error('No se encontró la planilla CAMMESA.');
            return self::FAILURE;
        }
        $data = $reader->read($path);
        $checksum = hash_file('sha256', $path);
        $collected = CarbonImmutable::createFromTimestampUTC(filemtime($path))->format('Y-m-d H:i:s');
        $definitions = [
            'D' => ['GENERATION_ENERGY', 'Energía neta generada por Yacyretá'],
            'E' => ['ENERGY_TO_AR', 'Energía recibida por Argentina'],
            'F' => ['ENERGY_TO_PY', 'Energía recibida por Paraguay'],
        ];
        foreach ($data['annual'] as $year) {
            $months = array_values(array_filter($data['monthly'], fn ($month) => substr($month['start'], 0, 4) === substr($year['start'], 0, 4)));
            if ($year['grain'] === 'year' && count($months) !== 12) throw new RuntimeException('No hay 12 meses para el total anual CAMMESA.');
            if (substr($months[0]['start'] ?? '', 5) !== '01-01' || end($months)['end'] !== $year['end']) throw new RuntimeException('Los meses no cubren el período informado por CAMMESA.');
            foreach ($definitions as $column => $definition) {
                if (! is_numeric($year['cells'][$column] ?? null) || (float) $year['cells'][$column] < 0) throw new RuntimeException('Total CAMMESA inválido.');
                $sum = array_sum(array_map(fn ($month) => (float) $month['cells'][$column], $months));
                if (abs($sum - (float) $year['cells'][$column]) > .02) throw new RuntimeException('El total anual no coincide con sus meses CAMMESA.');
            }
        }
        $before = DB::table('observations')->count();
        DB::transaction(function () use ($data, $checksum, $collected, $path, $definitions) {
            foreach ($data['annual'] as $year) {
                foreach ($definitions as $column => [$metric, $definition]) {
                    $cell = $column.$year['row'];
                    DB::table('observations')->insertOrIgnore([
                        'identity' => hash('sha256', 'CAMMESA-BINATIONAL-ANNUAL|'.$checksum.'|'.$cell),
                        'variable_code' => $metric, 'asset_code' => 'YACYRETA', 'value' => (float) $year['cells'][$column],
                        'unit' => 'MWh', 'grain' => $year['grain'], 'period_start' => $year['start'].' 00:00:00', 'period_end' => $year['end'].' 00:00:00',
                        'measured_at' => null, 'published_at' => null, 'collected_at' => $collected,
                        'source_code' => 'CAMMESA', 'source_url' => CammesaBinationalWorkbook::URL,
                        'quality' => 'OFFICIAL_AGGREGATE', 'authority' => 'OFFICIAL_PRIMARY', 'priority' => 160,
                        'parser_confidence' => 1, 'parser_version' => 'cammesa-binational-annual-1', 'raw_checksum' => $checksum, 'fetch_id' => null,
                        'provenance' => json_encode(['source_file' => basename($path), 'sheet' => CammesaBinationalWorkbook::SHEET, 'cell' => $cell,
                            'import_mode' => 'manual_workbook', 'source_unit' => 'MWh', 'definition' => $definition,
                            'note' => 'Resumen de la planilla, contrastado con sus meses. El acumulado de año parcial no es un total anual.'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    ]);
                }
            }
        });
        Cache::forget('energy-live');
        $this->info(count($data['annual']).' resúmenes validados; '.(DB::table('observations')->count() - $before).' observaciones nuevas.');
        return self::SUCCESS;
    }
}
