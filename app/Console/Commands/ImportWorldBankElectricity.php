<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ImportWorldBankElectricity extends Command
{
    protected $signature = 'energy:import-worldbank-electricity {--refresh : Descargar las cuatro respuestas actuales de la API antes de importar}';
    protected $description = 'Importa series anuales de acceso y consumo eléctrico del Banco Mundial para Paraguay';

    private const SERIES = [
        'EG.ELC.ACCS.ZS' => ['PY_ELECTRICITY_ACCESS', 'percent', 'Porcentaje de población con acceso a electricidad'],
        'EG.ELC.ACCS.RU.ZS' => ['PY_ELECTRICITY_ACCESS_RURAL', 'percent', 'Porcentaje de población rural con acceso a electricidad'],
        'EG.ELC.ACCS.UR.ZS' => ['PY_ELECTRICITY_ACCESS_URBAN', 'percent', 'Porcentaje de población urbana con acceso a electricidad'],
        'EG.USE.ELEC.KH.PC' => ['PY_ELECTRICITY_CONSUMPTION_PER_CAPITA', 'kWh/person', 'Consumo eléctrico anual por habitante'],
    ];

    public function handle(): int
    {
        $directory = storage_path('app/energiapy/worldbank');
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('No se pudo crear el directorio de las respuestas del Banco Mundial.');
        }
        $snapshots = [];
        foreach (self::SERIES as $code => [$metric, $unit, $definition]) {
            $path = $directory.DIRECTORY_SEPARATOR.$code.'.json';
            $api = 'https://api.worldbank.org/v2/country/PRY/indicator/'.$code.'?format=json&per_page=100';
            if ($this->option('refresh')) {
                $response = Http::timeout(30)->acceptJson()->get('https://api.worldbank.org/v2/country/PRY/indicator/'.$code, ['format'=>'json', 'per_page'=>100]);
                if (! $response->successful()) throw new RuntimeException('No se pudo consultar la API del Banco Mundial: '.$code);
                $raw = $response->body();
                $collected = gmdate('Y-m-d H:i:s');
            } else {
                if (! is_file($path)) throw new RuntimeException('Falta la respuesta guardada: '.$code);
                $raw = file_get_contents($path);
                $collected = gmdate('Y-m-d H:i:s', filemtime($path));
            }
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($data) || count($data) !== 2 || ($data[0]['pages'] ?? null) !== 1 || ! is_array($data[1]) || count($data[1]) !== ($data[0]['total'] ?? null)) {
                throw new RuntimeException('Respuesta incompleta o no válida: '.$code);
            }
            $rows = []; $years = [];
            foreach ($data[1] as $item) {
                if (($item['countryiso3code'] ?? '') !== 'PRY' || ($item['indicator']['id'] ?? '') !== $code || ! preg_match('/^\d{4}$/', $item['date'] ?? '')) {
                    throw new RuntimeException('País, indicador o año inesperado: '.$code);
                }
                $year = (int) $item['date'];
                if (isset($years[$year])) throw new RuntimeException('Año duplicado en la respuesta: '.$code);
                $years[$year] = true;
                if ($item['value'] === null) continue;
                $value = $item['value'];
                if (! is_numeric($value) || $value < 0 || ($unit === 'percent' && $value > 100)) throw new RuntimeException('Valor fuera de rango: '.$code);
                $rows[] = [$year, (float) $value];
            }
            if (count($rows) < 2) throw new RuntimeException('La respuesta no contiene una serie útil: '.$code);
            $snapshots[] = compact('path', 'raw', 'api', 'metric', 'unit', 'definition', 'code', 'collected', 'rows') + ['updated' => $data[0]['lastupdated'] ?? null];
        }

        if ($this->option('refresh')) {
            foreach ($snapshots as $snapshot) {
                if (file_put_contents($snapshot['path'], $snapshot['raw'], LOCK_EX) === false) throw new RuntimeException('No se pudo guardar la respuesta de '.$snapshot['code']);
            }
        }
        $before = DB::table('observations')->where('parser_version', 'worldbank-electricity-1')->count();
        DB::transaction(function () use ($snapshots) {
            $identities = [];
            foreach ($snapshots as $snapshot) {
                foreach ($snapshot['rows'] as [$year, $value]) {
                    $identity = hash('sha256', 'WORLD_BANK|PRY|'.$snapshot['code'].'|'.$year);
                    $identities[] = $identity;
                    DB::table('observations')->updateOrInsert(['identity'=>$identity], [
                        'variable_code'=>$snapshot['metric'], 'asset_code'=>'PY', 'value'=>$value, 'unit'=>$snapshot['unit'],
                        'grain'=>'year', 'period_start'=>"$year-01-01 00:00:00", 'period_end'=>"$year-12-31 00:00:00",
                        'measured_at'=>null, 'published_at'=>null, 'collected_at'=>$snapshot['collected'],
                        'source_code'=>'WORLD_BANK', 'source_url'=>'https://data.worldbank.org/indicator/'.$snapshot['code'].'?locations=PY',
                        'quality'=>str_contains($snapshot['code'], 'ACCS')?'OFFICIAL_ESTIMATE':'OFFICIAL_AGGREGATE',
                        'authority'=>'OFFICIAL_SECONDARY', 'priority'=>100, 'parser_confidence'=>1,
                        'parser_version'=>'worldbank-electricity-1', 'raw_checksum'=>hash('sha256', $snapshot['raw']), 'fetch_id'=>null,
                        'provenance'=>json_encode([
                            'source_file'=>'app/energiapy/worldbank/'.$snapshot['code'].'.json', 'api_url'=>$snapshot['api'],
                            'indicator'=>$snapshot['code'], 'world_bank_last_updated'=>$snapshot['updated'], 'definition'=>$snapshot['definition'],
                            'method'=>str_contains($snapshot['code'], 'ACCS')?'Indicadores de desarrollo mundial; acceso estimado mediante fuentes nacionales e internacionales.':'Indicadores de desarrollo mundial; consumo eléctrico por habitante.',
                            'license'=>'CC BY 4.0',
                        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    ]);
                }
            }
            DB::table('observations')->where('parser_version', 'worldbank-electricity-1')->whereNotIn('identity', $identities)->delete();
        });
        $after = DB::table('observations')->where('parser_version', 'worldbank-electricity-1')->count();
        $this->info('Series del Banco Mundial: '.count($snapshots).'; observaciones: '.$after.' (antes: '.$before.').');
        return self::SUCCESS;
    }
}
