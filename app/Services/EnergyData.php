<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class EnergyData
{
    public function csv(string $name): array
    {
        $path = storage_path('app/energiapy/base_v5/energiapy_'.$name.'_v5.csv');
        if (! is_file($path)) {
            return [];
        }
        $h = fopen($path, 'r');
        $keys = fgetcsv($h, 0, ',', '"', '');
        $keys[0] = ltrim($keys[0], "\xEF\xBB\xBF");
        $rows = [];
        while (($r = fgetcsv($h, 0, ',', '"', '')) !== false) {
            if (count($r) === count($keys)) {
                $rows[] = array_combine($keys, $r);
            }
        }
        fclose($h);
        $checksum = hash_file('sha256', $path);

        return array_map(function ($r) use ($name, $checksum) {
            return $r + ['source' => $r['source_code'] ?? $r['Source'] ?? $r['Fuente'] ?? null, 'source_url' => $r['source_url'] ?? $r['Source URL'] ?? $r['URL'] ?? null, 'measured_at' => null, 'published_at' => $r['source_published_at'] ?? null, 'collected_at' => $r['collected_at'] ?? null, 'quality' => $r['quality_class'] ?? $r['Quality'] ?? $r['Calidad'] ?? 'PACKAGE_REFERENCE', 'state' => 'HISTORICAL', 'stale' => true, 'parser_version' => 'v5-import-1.0', 'provenance' => ['dataset' => $name, 'raw_checksum' => $checksum, 'timestamp_notice' => 'Fechas originales conservadas; no se infiere instante de medición.']];
        }, $rows);
    }

    public function registry(): array
    {
        return json_decode(file_get_contents(storage_path('app/energiapy/endpoint-manifest.json')), true)['sources'];
    }

    public function sources(): array
    {
        $health = DB::table('source_health')->get()->keyBy('source_code');
        $coverage = DB::table('observations')->selectRaw('source_code, MIN(period_start) AS first_period, MAX(period_end) AS last_period, COUNT(*) AS observations')->groupBy('source_code')->get()->keyBy('source_code');
        $checks = is_file(storage_path('app/energiapy/endpoint-health.json')) ? collect(json_decode(file_get_contents(storage_path('app/energiapy/endpoint-health.json')), true))->keyBy('source') : collect();

        return array_map(function ($s) use ($health, $coverage, $checks) {
            $h = $health->get($s['code']);
            $c = $coverage->get($s['code']);

            return $s + ['status' => $h->status ?? 'not_connected', 'last_attempt' => $h->last_attempt ?? null, 'last_success' => $h->last_success ?? null, 'last_measurement' => $h->last_measurement ?? null, 'parser_version' => $h->parser_version ?? null, 'message' => $h->message ?? 'Fuente catalogada; sin conector validado en operación.', 'failures' => $h->failures ?? 0, 'coverage' => $c, 'http_check' => $checks->get($s['code']), 'stale' => ! $h?->last_success || CarbonImmutable::parse($h->last_success, 'UTC')->addSeconds(config('energy.cadences.'.$s['code'], 86400) * 2)->isPast()];
        }, $this->registry());
    }

    public function envelope($r): array
    {
        $r = (array) $r;
        $instant = $r['measured_at'] ?? $r['collected_at'];
        $sla = str_starts_with($r['source_code'], 'ONS') ? 129600 : ($r['source_code'] === 'DMH_EMAS' ? 1800 : 7200);
        $age = CarbonImmutable::parse($instant, 'UTC')->diffInSeconds(CarbonImmutable::now('UTC'), false);
        $historical = ! in_array($r['grain'], ['hour', 'instant', 'last_hour']);
        $unverified = str_contains($r['quality'], 'UNVERIFIED');
        $stale = $unverified || $age > $sla || $age < -300;

        return ['id' => $r['id'] ?? null, 'metric' => $r['variable_code'], 'asset_code' => $r['asset_code'], 'value' => (float) $r['value'], 'unit' => $r['unit'], 'grain' => $r['grain'], 'period_start' => $r['period_start'], 'period_end' => $r['period_end'], 'source' => $r['source_code'], 'source_url' => $r['source_url'], 'measured_at' => $r['measured_at'], 'published_at' => $r['published_at'], 'collected_at' => $r['collected_at'], 'quality' => $r['quality'], 'stale' => $stale, 'state' => str_contains($r['quality'], 'FORECAST') ? 'FORECAST' : ($historical ? 'HISTORICAL' : ($stale ? 'STALE' : ($r['measured_at'] ? 'RECENT' : 'RECENT'))), 'parser_version' => $r['parser_version'], 'provenance' => is_string($r['provenance']) ? json_decode($r['provenance'], true) : $r['provenance'], 'raw_checksum' => $r['raw_checksum'], 'fetch_id' => $r['fetch_id']];
    }

    public function latest(string $metric, string $asset): ?array
    {
        $rows = DB::table('observations')->where('variable_code', $metric)->where('asset_code', $asset)->where('quality', 'not like', '%UNVERIFIED%')->whereIn('grain', ['instant', 'hour', 'last_hour'])->get();
        if ($rows->isEmpty()) {
            return null;
        }
        $rank = ['OFFICIAL_PRIMARY' => 100, 'OFFICIAL_ORIENTATIVE' => 80, 'OFFICIAL_RESEARCH' => 70];
        $sorted = $rows->sort(function ($a, $b) use ($rank) {
            return [($rank[$b->authority] ?? 0), $b->measured_at ?? $b->collected_at, $b->priority, $b->parser_confidence, $b->id] <=> [($rank[$a->authority] ?? 0), $a->measured_at ?? $a->collected_at, $a->priority, $a->parser_confidence, $a->id];
        });

        return $this->envelope($sorted->first());
    }

    /** Return a completed official calendar-month aggregate, never an hourly reading. */
    public function latestMonthly(string $metric, string $asset): ?array
    {
        $rows = DB::table('observations')->where('variable_code', $metric)->where('asset_code', $asset)
            ->where(function ($q) {
                $q->where(fn ($s) => $s->where('source_code', 'ANDE')->where('authority', 'OFFICIAL_PRIMARY'))
                    ->orWhere(fn ($s) => $s->where('source_code', 'AGENCIA_IP_ANDE')->where('authority', 'OFFICIAL_ORIENTATIVE'));
            })
            ->where('quality', 'OFFICIAL_AGGREGATE')->where('unit', 'MWh')->where('grain', 'month')
            ->where('value', '>=', 0)->whereNotNull('period_start')->whereNotNull('period_end')
            ->where('period_end', '<', now('America/Asuncion')->startOfMonth()->format('Y-m-d H:i:s'))
            ->orderByDesc('period_start')->orderByRaw("CASE WHEN source_code = 'ANDE' THEN 0 ELSE 1 END")->orderByDesc('priority')->orderByDesc('id')->get();
        foreach ($rows as $row) {
            $start = CarbonImmutable::parse($row->period_start, 'America/Asuncion');
            $end = CarbonImmutable::parse($row->period_end, 'America/Asuncion');
            if ($start->day !== 1 || ! $end->isSameDay($start->endOfMonth()) || ! $row->source_url) {
                continue;
            }
            return $this->envelope($row);
        }
        return null;
    }

    /** Constant historical reference; not a demand measurement or an hourly profile. */
    public function demandMonthlyReference(): ?array
    {
        $source = $this->latestMonthly('PY_CONSUMPTION_ENERGY', 'PY_SIN');
        if (! $source) {
            return null;
        }
        $start = CarbonImmutable::parse($source['period_start'], 'America/Asuncion')->startOfMonth();
        $end = $start->addMonth();
        $hours = ($end->getTimestamp() - $start->getTimestamp()) / 3600;
        if ($hours <= 0) {
            return null;
        }
        return ['value' => $source['value'] / $hours, 'unit' => 'MW', 'kind' => 'HISTORICAL_MONTHLY_AVERAGE',
            'source_energy_mwh' => $source['value'], 'duration_hours' => $hours,
            'period_start' => $start->format('Y-m-d'), 'period_end' => $start->endOfMonth()->format('Y-m-d'),
            'source' => $source['source'], 'source_url' => $source['source_url'], 'observation_id' => $source['id'],
            'formula' => 'Energía mensual abastecida (MWh) / horas del mes',
            'notice' => 'Promedio histórico del mes completo; igual para cualquier hora. No es medición ni estimación de la demanda de esta hora.'];
    }

    /** A planning profile scaled to a published month; never an observed hourly demand. */
    public function demandHourlyEstimate(?CarbonImmutable $when = null): ?array
    {
        $base = $this->demandMonthlyReference();
        if (! $base) {
            return null;
        }

        $timezone = 'America/Asuncion';
        $start = CarbonImmutable::parse($base['period_start'], $timezone)->startOfMonth();
        $end = $start->addMonth();
        $weight = static function (int $hour): float {
            if ($hour < 8 || $hour >= 22) {
                return 0.70;
            }
            return ($hour < 11 || ($hour >= 15 && $hour < 18)) ? 0.85 : 1.0;
        };

        // Iterate actual UTC hours so historical DST changes are reflected in the
        // reference month's normalization rather than assuming every day has 24 hours.
        $sumWeights = 0.0;
        $hours = 0;
        for ($t = $start->utc(); $t->lessThan($end->utc()); $t = $t->addHour()) {
            $sumWeights += $weight((int) $t->setTimezone($timezone)->format('G'));
            $hours++;
        }
        if ($hours === 0 || $sumWeights <= 0) {
            return null;
        }

        $local = ($when ?? CarbonImmutable::now('UTC'))->setTimezone($timezone);
        $currentWeight = $weight((int) $local->format('G'));
        $normalization = $sumWeights / $hours;
        return [
            'value' => $base['value'] * $currentWeight / $normalization,
            'unit' => 'MW', 'kind' => 'MODELED_HOURLY_REFERENCE',
            'model' => 'Perfil diario de planificación ANDE 2024–2043',
            'model_version' => 'ande-planning-profile-1',
            'profile_source_url' => 'https://www.ande.gov.py/documentos/plan_maestro/PLAN%20MAESTRO%20DE%20GENERACION%202024-2043.pdf',
            'profile_source_page' => 6,
            'as_of' => $local->toIso8601String(), 'timezone' => $timezone,
            'hour_start' => $local->startOfHour()->format('H:i'),
            'hour_end' => $local->startOfHour()->addHour()->format('H:i'),
            'weight' => $currentWeight, 'normalization_factor' => $normalization,
            'monthly_average_mw' => $base['value'],
            'source_energy_mwh' => $base['source_energy_mwh'], 'duration_hours' => $base['duration_hours'],
            'period_start' => $base['period_start'], 'period_end' => $base['period_end'],
            'source' => $base['source'], 'source_url' => $base['source_url'], 'observation_id' => $base['observation_id'],
            'formula' => 'Promedio mensual (MWh / horas del mes) × peso horario / promedio ponderado de pesos del mes',
            'notice' => 'Modelo orientativo de planificación normalizado al consumo histórico; no es medición ni promedio observado de esta hora. No fue validado como pronóstico actual.',
        ];
    }

    public function plants(): array
    {
        $locations = json_decode(file_get_contents(storage_path('app/energiapy/plant-locations.json')), true)['features'];
        $monthlyPath = storage_path('app/energiapy/yacyreta-argentina-monthly.json');
        $argentinaMonthly = is_file($monthlyPath) ? json_decode(file_get_contents($monthlyPath), true) : null;

        return array_map(function ($p) use ($argentinaMonthly) {
            $official = DB::table('grid_assets')->where('code', $p['code'])->where('kind', 'plants')->whereIn('quality', GisService::QUALITIES)->first();
            if ($official) {
                $geo = json_decode($official->geometry, true);
                $p['lon'] = $geo['coordinates'][0];
                $p['lat'] = $geo['coordinates'][1];
                $p['quality'] = $official->quality;
                $p['location_source_url'] = $official->source_url;
            }$o = $this->latest('GENERATION_MW', $p['code']);
            $br = $this->latest('SUPPLY_BR_MW', $p['code']);
            $toPy = null;
            $toPyProvenance = null;
            if ($p['code'] === 'ITAIPU' && $o && $br && $o['source'] === $br['source'] && $o['measured_at'] === $br['measured_at'] && $o['value'] >= $br['value']) {
                $toPy = $o['value'] - $br['value'];
                $toPyProvenance = ['formula' => 'generación total Itaipú − potencia destinada a Brasil', 'input_observation_ids' => [$o['id'], $br['id']], 'measured_at' => $o['measured_at'], 'source_url' => $o['source_url'], 'quality' => 'DERIVED_FROM_OFFICIAL_SAME_INTERVAL'];
            }

            return $p + ['total_mw' => $o['value'] ?? null, 'to_py_mw' => $toPy, 'to_py_provenance' => $toPyProvenance, 'to_br_mw' => $br['value'] ?? null, 'to_ar_mw' => null, 'to_ar_monthly' => $p['code'] === 'YACYRETA' ? $argentinaMonthly : null, 'to_py_monthly' => $p['code'] === 'ACARAY' ? $this->latestMonthly('ENERGY_TO_PY', 'ACARAY') : null, 'observation' => $o, 'supply_br' => $br, 'source' => $o['source'] ?? null, 'source_url' => $o['source_url'] ?? null, 'measured_at' => $o['measured_at'] ?? null, 'published_at' => $o['published_at'] ?? null, 'collected_at' => $o['collected_at'] ?? null, 'data_quality' => $o['quality'] ?? 'UNAVAILABLE', 'stale' => $o['stale'] ?? true, 'parser_version' => $o['parser_version'] ?? null, 'provenance' => $o['provenance'] ?? null];
        }, $locations);
    }

    public function live(): array
    {
        $plants = $this->plants();
        $d = $this->latest('DEMAND_MW', 'PY_SIN');
        $period = app(OperationalMetrics::class)->period('DEMAND_MW', 'PY_SIN', 'today');
        $available = count(array_filter($plants, fn ($p) => $p['total_mw'] !== null));

        return ['meta' => ['generated_at' => now('UTC')->toIso8601String(), 'timezone' => 'America/Asuncion', 'status' => $available || $d ? 'partial' : 'no_data', 'source_freshness_seconds' => null], 'system' => ['demand_mw' => $d['value'] ?? null, 'demand_reference' => $this->demandMonthlyReference(), 'demand_estimate' => $this->demandHourlyEstimate(), 'energy_today_mwh' => $period['energy_mwh'], 'peak_today_mw' => $period['peak_mw'], 'load_factor' => $period['load_factor'], 'period_provenance' => $period, 'hourly_normal_delta_pct' => null, 'status' => 'unknown', 'measured_at' => $d['measured_at'] ?? null, 'collected_at' => $d['collected_at'] ?? null, 'source' => $d['source'] ?? null, 'source_url' => $d['source_url'] ?? null, 'quality' => $d['quality'] ?? 'UNAVAILABLE', 'stale' => $d['stale'] ?? true, 'parser_version' => $d['parser_version'] ?? null, 'provenance' => $d['provenance'] ?? null], 'plants' => $plants, 'flows' => app(GisService::class)->flows(), 'notice' => 'S/D indica ausencia de dato verificable. Potencia horaria no equivale a lectura instantánea.'];
    }

    public function history(array $filter): array
    {
        if (! empty($filter['dataset'])) {
            return app(HistoryCatalog::class)->dataset($filter);
        }
        $metric = $filter['metric'] ?? 'PY_CONSUMPTION_ENERGY';
        $q = DB::table('observations')->where('variable_code', $metric);
        if (! empty($filter['asset'])) {
            $q->where('asset_code', $filter['asset']);
        }
        if (! empty($filter['unit'])) {
            $q->where('unit', $filter['unit']);
        }
        if (! empty($filter['source'])) {
            $q->where('source_code', $filter['source']);
        }
        if (! empty($filter['from'])) {
            $q->where('period_start', '>=', $filter['from']);
        }
        if (! empty($filter['to'])) {
            $q->where('period_end', '<=', substr($filter['to'], 0, 10).' 23:59:59');
        }
        if (($filter['grain'] ?? 'raw') !== 'raw') {
            $q->where('grain', $filter['grain']);
        }
        $sourceTime = DB::getDriverName() === 'sqlite'
            ? "json_extract(provenance, '$.source_local_time')"
            : "JSON_UNQUOTE(JSON_EXTRACT(provenance, '$.source_local_time'))";
        $q->select('observations.*')->selectRaw("ROW_NUMBER() OVER (PARTITION BY asset_code, COALESCE(period_start, {$sourceTime}, CAST(id AS CHAR)), period_end, grain, unit ORDER BY priority DESC, id DESC) AS canonical_rank");
        $canonical = DB::query()->fromSub($q, 'ranked')->where('canonical_rank', 1);
        $total = (clone $canonical)->count();
        $page = max(1, (int) ($filter['page'] ?? 1));
        $data = $canonical->orderBy('period_start')->orderBy('id')->offset(($page - 1) * 500)->limit(500)->get()->map(fn ($r) => $this->envelope($r))->all();

        return ['data' => $data, 'meta' => ['total' => $total, 'page' => $page, 'per_page' => 500, 'filters' => app(HistoryCatalog::class)->observationFilters($metric), 'selection' => 'Prioridad V5, revisión e identidad; alternativas conservadas. Paginación SQL.', 'aggregation' => 'Grano nativo; no se suman potencias ni períodos incompatibles.']];
    }

    public function replay(string $date): array
    {
        $start = CarbonImmutable::parse($date, 'America/Asuncion')->startOfDay();
        $end = $start->addDay();
        $r = DB::table('observations')->where('variable_code', 'DEMAND_MW')->where('asset_code', 'PY_SIN')->where('grain', 'hour')->where('unit', 'MW')->where('quality', 'not like', '%UNVERIFIED%')->where('measured_at', '>=', $start->utc())->where('measured_at', '<', $end->utc())->orderBy('measured_at')->get()->unique('measured_at');
        $hours = $r->map(fn ($o) => CarbonImmutable::parse($o->measured_at, 'UTC')->setTimezone('America/Asuncion')->format('H'))->unique()->count();
        $eligible = $hours / 24 >= .9;

        return ['date' => $date, 'eligible' => $eligible, 'coverage' => $hours / 24, 'threshold' => .9, 'data' => $eligible ? $r->map(fn ($o) => $this->envelope($o))->values()->all() : [], 'notice' => $eligible ? 'Reproducción de observaciones históricas.' : 'No hay cobertura suficiente de demanda PY para reproducir este día.'];
    }

    public function gis(string $layer): array
    {
        $path = storage_path('app/gis/web/'.$layer.'.geojson');
        if (is_file($path)) {
            return json_decode(file_get_contents($path), true);
        }

        return ['type' => 'FeatureCollection', 'features' => [], 'meta' => ['status' => 'unavailable', 'source_url' => null, 'quality' => 'UNAVAILABLE', 'notice' => 'No se dispone de geometría oficial validada para esta capa.']];
    }
}
