<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SourceCollector
{
    use AdditionalParsers;

    public const VERSION = '1.1.0';

    public const ENABLED = ['ONS_ITAIPU_HOURLY', 'ONS_INTL_2026', 'DMH_EMAS', 'EBY_CURRENT_POWER', 'EBY_HYDRO_DAILY', 'BCP_FX_DAILY', 'NOAA_ENSO_PROB', 'AGENCIA_IP_ANDE'];

    public function run(string $code, ?string $file = null): array
    {
        $source = collect(app(EnergyData::class)->registry())->firstWhere('code', $code);
        if (! $source || ! in_array($code, self::ENABLED)) {
            throw new RuntimeException('Conector no validado: '.$code);
        }
        $time = $file ? gmdate('Y-m-d H:i:s', filemtime($file)) : now('UTC')->format('Y-m-d H:i:s');
        $fetch = DB::table('source_fetches')->insertGetId(['source_code' => $code, 'source_url' => $source['url'], 'collected_at' => $time, 'parser_version' => self::VERSION, 'status' => 'fetching']);
        $status = null;
        $last = DB::table('source_health')->where('source_code', $code)->first();
        try {
            if ($file) {
                $raw = file_get_contents($file);
                $status = 200;
            } else {
                $response = Http::withUserAgent(config('energy.user_agent'))->connectTimeout(15)->timeout(120)->retry([1000, 4000], throw: false)->get($source['url']);
                $status = $response->status();
                $response->throw();
                $raw = $response->body();
            }
            if ($code === 'EBY_HYDRO_DAILY' && ! $file) {
                $listingHash = hash('sha256', $raw);
                $listingPath = storage_path('app/energiapy/raw/EBY_HYDRO_DAILY');
                if (! is_dir($listingPath)) {
                    mkdir($listingPath, 0755, true);
                }file_put_contents($listingPath.'/'.$listingHash.'.html', $raw);
                $xp = $this->xpath($raw);
                $url = null;
                foreach ($xp->query('//a[@href]') as $a) {
                    $candidate = $a->getAttribute('href');
                    if (preg_match('~^https://(?:www\.)?eby\.gov\.py/cota-[^?#]+/$~', $candidate)) {
                        $url = $candidate;
                        break;
                    }
                }
                if (! $url) {
                    throw new RuntimeException('EBY: informe diario no localizado en el listado oficial.');
                }
                $response = Http::withUserAgent(config('energy.user_agent'))->connectTimeout(15)->timeout(45)->get($url);
                $response->throw();
                $raw = $response->body();
                $source['url'] = $url;
                DB::table('source_fetches')->where('id', $fetch)->update(['source_url' => $url]);
            }
            $hash = hash('sha256', $raw);
            $path = 'app/energiapy/raw/'.$code.'/'.$hash.'.txt';
            if (! is_dir(dirname(storage_path($path)))) {
                mkdir(dirname(storage_path($path)), 0755, true);
            }file_put_contents(storage_path($path), $raw);
            DB::table('source_fetches')->where('id', $fetch)->update(['http_status' => $status, 'checksum' => $hash, 'raw_path' => $path]);
            $rows = match ($code) {
                'ONS_ITAIPU_HOURLY' => $this->parseOns($raw),'ONS_INTL_2026' => $this->parseInternational($raw),'DMH_EMAS' => $this->parseDmh($raw),'EBY_CURRENT_POWER' => $this->parseEby($raw),'EBY_HYDRO_DAILY' => $this->parseEbyHydrology($raw),'BCP_FX_DAILY' => $this->parseBcp($raw),'NOAA_ENSO_PROB' => $this->parseNoaa($raw),'AGENCIA_IP_ANDE' => app(AgencyMonthlyParser::class)->parse($raw)
            };
            if (! $rows) {
                throw new RuntimeException('Sin observaciones válidas; se conserva la última captura válida.');
            }
            DB::transaction(function () use ($rows, $code, $source, $time, $hash, $fetch) {
                foreach ($rows as $r) {
                    $identity = hash('sha256', implode('|', [$code, $r['metric'], $r['asset'], $r['measured_at'] ?? $r['provenance']['source_local_time'] ?? $r['provenance']['issue_month'] ?? $r['period_start'] ?? $hash, (string) $r['value']]));
                    DB::table('observations')->updateOrInsert(['identity' => $identity], ['variable_code' => $r['metric'], 'asset_code' => $r['asset'], 'value' => $r['value'], 'unit' => $r['unit'], 'grain' => $r['grain'], 'period_start' => $r['period_start'] ?? $r['measured_at'], 'period_end' => $r['period_end'] ?? $r['measured_at'], 'measured_at' => $r['measured_at'], 'published_at' => null, 'collected_at' => $time, 'source_code' => $code, 'source_url' => $r['source_url'] ?? $source['url'], 'quality' => $r['quality'], 'authority' => $source['authority'], 'priority' => 100, 'parser_confidence' => $r['confidence'] ?? 1, 'parser_version' => self::VERSION, 'raw_checksum' => $hash, 'fetch_id' => $fetch, 'provenance' => json_encode($r['provenance'] + ['license' => $source['license'] ?? 'Ver términos de la fuente', 'source_grain' => $source['grain']])]);
                    if ($code === 'DMH_EMAS') {
                        $id = DB::table('observations')->where('identity', $identity)->value('id');
                        DB::table('weather_observations')->insertOrIgnore(['observation_id' => $id, 'context' => json_encode($r['provenance'])]);
                    }
                }
            });
            $measurement = collect($rows)->pluck('measured_at')->filter()->max();
            DB::table('source_health')->updateOrInsert(['source_code' => $code], ['status' => 'ok', 'last_attempt' => $time, 'last_success' => $time, 'last_measurement' => $measurement, 'parser_version' => self::VERSION, 'failures' => 0, 'message' => count($rows).' valores validados.']);
            DB::table('source_fetches')->where('id', $fetch)->update(['status' => 'ok']);
            Cache::forget('energy-live');

            return ['source' => $code, 'status' => 'ok', 'observations' => count($rows)];
        } catch (\Throwable $e) {
            DB::table('source_fetches')->where('id', $fetch)->update(['status' => 'error', 'http_status' => $status, 'error' => mb_substr($e->getMessage(), 0, 1500)]);
            DB::table('source_health')->updateOrInsert(['source_code' => $code], ['status' => 'error', 'last_attempt' => $time, 'last_success' => $last->last_success ?? null, 'last_measurement' => $last->last_measurement ?? null, 'parser_version' => self::VERSION, 'failures' => ($last->failures ?? 0) + 1, 'message' => mb_substr($e->getMessage(), 0, 500)]);

            return ['source' => $code, 'status' => 'error', 'message' => $e->getMessage()];
        }
    }

    public function parseOns(string $raw): array
    {
        $h = fopen('php://temp', 'r+');
        fwrite($h, $raw);
        rewind($h);
        $head = fgetcsv($h, 0, ';', '"', '');
        $required = ['din_instante', 'val_itaipu_total', 'val_itaipu_br'];
        foreach ($required as $key) {
            if (! in_array($key, $head)) {
                throw new RuntimeException('ONS: falta columna '.$key);
            }
        }
        $records = [];
        while (($v = fgetcsv($h, 0, ';', '"', '')) !== false) {
            if (count($head) !== count($v)) {
                continue;
            }$r = array_combine($head, $v);
            $records[] = $r;
        }fclose($h);
        $records = array_slice($records, -24 * 35);
        $out = [];
        foreach ($records as $r) {
            if (! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $r['din_instante'])) {
                throw new RuntimeException('ONS: fecha no reconocida');
            }
            $local = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $r['din_instante'], 'America/Sao_Paulo');
            if (! $local) {
                throw new RuntimeException('ONS: hora local inválida');
            }
            $measured = $local->utc();
            foreach (['val_itaipu_total' => 'GENERATION_MW', 'val_itaipu_br' => 'SUPPLY_BR_MW'] as $column => $metric) {
                if ($r[$column] === '') {
                    continue;
                }if (! is_numeric($r[$column]) || ! is_finite((float) $r[$column]) || $r[$column] < 0 || $r[$column] > 15000) {
                    throw new RuntimeException('ONS: MW fuera de rango');
                }$out[] = ['metric' => $metric, 'asset' => 'ITAIPU', 'value' => (float) $r[$column], 'unit' => 'MW', 'grain' => 'hour', 'period_start' => $measured->format('Y-m-d H:i:s'), 'period_end' => $measured->addHour()->format('Y-m-d H:i:s'), 'measured_at' => $measured->format('Y-m-d H:i:s'), 'quality' => 'OFFICIAL_HOURLY', 'confidence' => .95, 'provenance' => ['source_local_time' => $r['din_instante'], 'source_timezone' => 'America/Sao_Paulo', 'timezone_basis' => 'Portal ONS publica sus actividades y horarios operativos en BRT/horario oficial de Brasília.', 'column' => $column, 'interpretation' => 'Potencia activa media horaria medida por el sistema de supervisión y control del ONS.', 'raw_row' => $r]];
            }
        }

        return $out;
    }

    public function parseDmh(string $raw): array
    {
        $json = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (! isset($json['estaciones']) || ! is_array($json['estaciones'])) {
            throw new RuntimeException('DMH: esquema estaciones ausente');
        }$out = [];
        foreach ($json['estaciones'] as $s) {
            $meta = $s['metadatos'] ?? [];
            $obs = $s['ultima_observacion'] ?? [];
            $date = $obs['fecha']['utc'] ?? null;
            if (! $date) {
                continue;
            }$measured = CarbonImmutable::parse($date)->utc()->format('Y-m-d H:i:s');
            foreach (['temperatura_aire' => ['TEMPERATURE_C', '°C', -30, 60], 'humedad_relativa' => ['HUMIDITY_PCT', '%', 0, 100], 'precipitacion' => ['PRECIP_MM', 'mm', 0, 3000]] as $key => $def) {
                $value = $obs[$key]['valor'] ?? null;
                if ($value === null) {
                    continue;
                }if (! is_numeric($value) || $value < $def[2] || $value > $def[3]) {
                    throw new RuntimeException('DMH: valor fuera de rango');
                }$out[] = ['metric' => $def[0], 'asset' => 'DMH_'.($meta['codigo'] ?? $meta['id']), 'value' => (float) $value, 'unit' => $def[1], 'grain' => 'instant', 'measured_at' => $measured, 'quality' => 'OFFICIAL_ORIENTATIVE', 'provenance' => ['station' => $meta['nombre'] ?? null, 'lat' => $meta['latitud'] ?? null, 'lon' => $meta['longitud'] ?? null, 'source_timestamp' => $date, 'certification' => 'Orientativo; no documentación certificada.']];
            }
        }

        return $out;
    }

    public function parseEby(string $raw): array
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$raw);
        $xp = new \DOMXPath($dom);
        $nodes = $xp->query('//div[contains(concat(" ",normalize-space(@class)," ")," counter ")]');
        $values = [];
        foreach ($nodes as $n) {
            $text = preg_replace('/\s+/u', ' ', html_entity_decode($n->textContent));
            if (! str_contains(mb_strtolower($text), 'de potencia generada en la última hora')) {
                continue;
            }
            if (! preg_match('/([\d.,]+)\s*mw\s*de potencia/iu', $text, $m)) {
                throw new RuntimeException('EBY: texto no reconocido');
            }$visible = $this->number($m[1]);
            $animated = $xp->query('.//*[@data-to]', $n);
            $target = $animated->length ? $this->number($animated->item(0)->getAttribute('data-to')) : $visible;
            $values[] = ['value' => $target, 'visible_fallback' => $visible, 'counter_target' => $target];
        }
        if (count($values) !== 1) {
            throw new RuntimeException('EBY: lectura ausente o ambigua');
        }$counter = $values[0];
        $value = $counter['value'];
        if ($value < 0 || $value > 3500) {
            throw new RuntimeException('EBY: potencia fuera de rango');
        }

        return [['metric' => 'GENERATION_MW', 'asset' => 'YACYRETA', 'value' => $value, 'unit' => 'MW', 'grain' => 'last_hour', 'measured_at' => null, 'quality' => 'OFFICIAL_WEB_CURRENT', 'provenance' => ['label' => 'de potencia generada en la última hora', 'measurement_timestamp' => 'No publicado; no se infiere.', 'rendered_counter_target' => $counter['counter_target'], 'html_fallback_value' => $counter['visible_fallback'], 'selection_rule' => 'Se usa data-to, objetivo que Elementor anima y presenta al visitante; el texto HTML es el fallback previo al render.']]];
    }

    private function number(string $s): float
    {
        if (str_contains($s, ',')) {
            $s = str_replace(',', '.', str_replace('.', '', $s));
        } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $s)) {
            $s = str_replace('.', '', $s);
        }if (! is_numeric($s)) {
            throw new RuntimeException('Número inválido');
        }

        return (float) $s;
    }
}
