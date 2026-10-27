<?php

namespace App\Console\Commands;

use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ImportMendeleyDemand extends Command
{
    protected $signature = 'energy:import-mendeley-demand';
    protected $description = 'Calcula promedios mensuales de demanda SIN desde el conjunto académico horario';

    public function handle(): int
    {
        $path = storage_path('app/energiapy/mendeley-consumption-v3.csv');
        $checksum = is_file($path) ? hash_file('sha256', $path) : null;
        if ($checksum !== '45f6a4915cb79fe3eb777dce706034720599832e521436104605c3a4f663ddea') {
            throw new RuntimeException('El archivo no coincide con la versión 3 verificada del conjunto de datos.');
        }
        $handle = fopen($path, 'rb');
        $header = fgetcsv($handle, 0, ',', '"', '');
        if ($header !== ['DATETIME', 'SIN']) throw new RuntimeException('Cabecera de demanda académica no válida.');
        $months = []; $count = 0; $previous = null; $first = null; $last = null;
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            if (count($row) !== 2 || ! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:00:00[+-]\d{2}:\d{2}$/', $row[0]) || ! is_numeric($row[1])) {
                throw new RuntimeException('Registro horario de demanda no válido.');
            }
            $value = (float) $row[1];
            if (! is_finite($value) || $value < 0 || $value > 15000) throw new RuntimeException('Potencia horaria fuera de rango.');
            $instant = new DateTimeImmutable($row[0]);
            $epoch = $instant->getTimestamp();
            if ($previous !== null && $epoch <= $previous) throw new RuntimeException('Horas duplicadas o fuera de orden.');
            $previous = $epoch; $first ??= $row[0]; $last = $row[0]; $count++;
            $month = substr($row[0], 0, 7);
            $months[$month] ??= ['sum' => 0, 'count' => 0];
            $months[$month]['sum'] += $value;
            $months[$month]['count']++;
        }
        fclose($handle);
        if ($count !== 131481 || count($months) !== 180 || substr($first, 0, 10) !== '2008-01-01' || substr($last, 0, 10) !== '2022-12-31') {
            throw new RuntimeException('Cobertura de la serie horaria distinta a la versión revisada.');
        }
        $url = 'https://data.mendeley.com/datasets/dm78h95ghk/3';
        $collected = gmdate('Y-m-d H:i:s', filemtime($path));
        $before = DB::table('observations')->count();
        DB::transaction(function () use ($months, $checksum, $url, $collected) {
            foreach ($months as $month => $data) {
                $start = $month.'-01';
                $identity = hash('sha256', 'MENDELEY-DEMAND-V3|'.$month);
                DB::table('observations')->updateOrInsert(['identity' => $identity], [
                    'variable_code' => 'PY_RESEARCH_MEAN_HOURLY_DEMAND', 'asset_code' => 'PY_SIN',
                    'value' => $data['sum'] / $data['count'], 'unit' => 'MW', 'grain' => 'month',
                    'period_start' => $start.' 00:00:00', 'period_end' => date('Y-m-t', strtotime($start)).' 00:00:00',
                    'measured_at' => null, 'published_at' => '2025-02-03 00:00:00', 'collected_at' => $collected,
                    'source_code' => 'UNA_DEMAND_RESEARCH', 'source_url' => $url, 'quality' => 'RESEARCH_PROCESSED_AGGREGATE',
                    'authority' => 'RESEARCH_SECONDARY', 'priority' => 100, 'parser_confidence' => 1,
                    'parser_version' => 'mendeley-demand-v3-1', 'raw_checksum' => $checksum, 'fetch_id' => null,
                    'provenance' => json_encode([
                        'source_file' => 'app/energiapy/mendeley-consumption-v3.csv', 'doi' => '10.17632/dm78h95ghk.3',
                        'authors' => 'Félix Morales-Mareco, Diego Stalder, Carlos Sauer Ayala, Sebastián A. Grillo y Matías Verdún',
                        'license' => 'CC BY 4.0', 'source_timezone' => 'Offset de cada registro publicado',
                        'hourly_records' => $data['count'], 'method' => 'Media aritmética de las potencias horarias disponibles por mes local',
                        'note' => 'Serie procesada por los autores a partir de datos ANDE; incluye tratamiento de faltantes y valores atípicos. No es potencia en vivo ni suma de energía.',
                    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ]);
            }
        });
        $this->info("Procesadas $count horas; ".count($months).' medias mensuales; nuevas observaciones: '.(DB::table('observations')->count() - $before).'.');
        return self::SUCCESS;
    }
}
