<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ImportOnsItaipuMonthly extends Command
{
    protected $signature = 'energy:import-ons-itaipu-monthly {--file= : Captura CSV oficial del ONS}';
    protected $description = 'Calcula medias mensuales de potencia a partir de las horas publicadas por ONS';

    public function handle(): int
    {
        $path = $this->option('file');
        if (! $path) {
            $files = glob(storage_path('app/energiapy/raw/ONS_ITAIPU_HOURLY/*.txt')) ?: [];
            $files = array_values(array_filter($files, fn ($f) => filesize($f) > 10000000));
            usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));
            $path = $files[0] ?? null;
        }
        if (! $path || ! is_file($path)) throw new RuntimeException('No hay una captura horaria completa de ONS.');
        $checksum = hash_file('sha256', $path);
        $handle = fopen($path, 'rb');
        $header = fgetcsv($handle, 0, ';', '"', '');
        $columns = [];
        foreach (['din_instante', 'val_itaipu_total', 'val_itaipu_br'] as $name) {
            $position = array_search($name, $header, true);
            if ($position === false) throw new RuntimeException('Falta la columna ONS '.$name);
            $columns[$name] = $position;
        }
        $months = []; $count = 0; $previous = '';
        while (($row = fgetcsv($handle, 0, ';', '"', '')) !== false) {
            if (count($row) !== count($header)) throw new RuntimeException('Fila horaria ONS incompleta.');
            $stamp = $row[$columns['din_instante']];
            if (! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:00:00$/', $stamp) || $stamp <= $previous) throw new RuntimeException('Hora ONS inválida o duplicada.');
            $previous = $stamp; $month = substr($stamp, 0, 7);
            $months[$month] ??= ['count' => 0, 'total' => 0, 'br' => 0];
            foreach (['val_itaipu_total' => 'total', 'val_itaipu_br' => 'br'] as $column => $key) {
                $value = $row[$columns[$column]];
                if (! is_numeric($value) || ! is_finite((float) $value) || $value < 0 || $value > 15000) throw new RuntimeException('Potencia ONS inválida.');
                $months[$month][$key] += (float) $value;
            }
            $months[$month]['count']++; $count++;
        }
        fclose($handle);
        if ($count < 200000 || count($months) < 300) throw new RuntimeException('La captura no contiene el histórico esperado.');
        $collected = gmdate('Y-m-d H:i:s', filemtime($path));
        $url = 'https://dados.ons.org.br/dataset/geracao_itaipu';
        $before = DB::table('observations')->where('parser_version', 'ons-monthly-means-1')->count();
        $accepted = 0;
        $sourceFile = basename($path);
        DB::transaction(function () use ($months, $checksum, $url, $collected, $sourceFile, &$accepted) {
            foreach ($months as $month => $data) {
                $start = $month.'-01';
                $hours = (int) date('t', strtotime($start)) * 24;
                if ($data['count'] < 0.95 * $hours) continue;
                $accepted++;
                foreach (['total' => 'ITAIPU_ONS_MONTHLY_MEAN_TOTAL', 'br' => 'ITAIPU_ONS_MONTHLY_MEAN_BR'] as $key => $metric) {
                    DB::table('observations')->updateOrInsert(['identity' => hash('sha256', "ONS-ITAIPU-MONTHLY|$metric|$month")], [
                        'variable_code' => $metric, 'asset_code' => 'ITAIPU', 'value' => $data[$key] / $data['count'],
                        'unit' => 'MW', 'grain' => 'month', 'period_start' => $start.' 00:00:00',
                        'period_end' => date('Y-m-t', strtotime($start)).' 00:00:00', 'measured_at' => null, 'published_at' => null,
                        'collected_at' => $collected, 'source_code' => 'ONS_ITAIPU_HOURLY', 'source_url' => $url,
                        'quality' => 'DERIVED_FROM_OFFICIAL_HOURLY', 'authority' => 'OFFICIAL_PRIMARY', 'priority' => 100,
                        'parser_confidence' => 1, 'parser_version' => 'ons-monthly-means-1', 'raw_checksum' => $checksum, 'fetch_id' => null,
                        'provenance' => json_encode([
                            'source_file' => $sourceFile,
                            'source_column' => $key === 'total' ? 'val_itaipu_total' : 'val_itaipu_br', 'license' => 'CC BY 4.0',
                            'source_timezone' => 'America/Sao_Paulo', 'hourly_records' => $data['count'], 'nominal_hours' => $hours,
                            'method' => 'Media aritmética de las potencias horarias disponibles; al menos 95% de horas nominales.',
                            'note' => 'MW de potencia media, no energía acumulada. Los cambios de horario y lagunas afectan la cantidad de registros.',
                        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    ]);
                }
            }
        });
        $after = DB::table('observations')->where('parser_version', 'ons-monthly-means-1')->count();
        $this->info("Procesadas $count horas ONS; $accepted meses con cobertura suficiente; observaciones: $after (antes: $before).");
        return self::SUCCESS;
    }
}
