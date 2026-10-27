<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** The native observation series and every retained workbook table, without mixing their grains. */
class HistoryCatalog
{
    public const CATEGORIES = [
        'energy' => 'Generación, consumo y demanda',
        'tariffs' => 'Tarifas y hogares',
        'economics' => 'Economía y mercados',
        'infrastructure' => 'Red e infraestructura',
        'climate' => 'Hidrología y clima',
        'planning' => 'Planificación',
        'sources' => 'Fuentes y documentación',
    ];

    private const DATASETS = [
        'ande_customers_2025' => ['Clientes y consumo por categoría', 'tariffs'],
        'ande_retail_tariff_current' => ['Pliego tarifario ANDE', 'tariffs'],
        'ande_retail_tariff_history' => ['Histórico de tarifas ANDE', 'tariffs'],
        'assets' => ['Centrales y activos', 'infrastructure'],
        'canonical_measurements' => ['Mediciones canónicas del Excel', 'energy'],
        'climate_station_snapshot' => ['Temperaturas por estación', 'climate'],
        'coverage' => ['Cobertura de los conjuntos', 'sources'],
        'current_baselines' => ['Informes y referencias por indicador', 'energy'],
        'economic_terms' => ['Condiciones económicas', 'economics'],
        'economic_transactions' => ['Transferencias y compensaciones', 'economics'],
        'enso_outlook' => ['Pronósticos ENSO', 'climate'],
        'grid_quality_current' => ['Calidad del servicio y pérdidas', 'infrastructure'],
        'household_context' => ['Contexto de hogares', 'tariffs'],
        'hydrology_current' => ['Informes hidrológicos', 'climate'],
        'imaep_current' => ['Actividad económica IMAEP', 'economics'],
        'import_queue' => ['Cola de importación', 'sources'],
        'infrastructure' => ['Infraestructura publicada', 'infrastructure'],
        'macro_annual' => ['Inflación y cotización anual', 'economics'],
        'macro_current' => ['Indicadores macroeconómicos publicados', 'economics'],
        'metric_definitions' => ['Definiciones de indicadores', 'sources'],
        'news_references' => ['Referencias y comunicados', 'sources'],
        'planning_baselines' => ['Planes y horizontes ANDE', 'planning'],
        'precip_2026_09_01_10' => ['Precipitaciones por estación', 'climate'],
        'prices' => ['Precios y mercados', 'economics'],
        'quality_issues' => ['Observaciones de calidad', 'sources'],
        'retail_tariff_bands' => ['Franjas y descuentos tarifarios', 'tariffs'],
        'retail_tariff_current_normalized' => ['Tarifas equivalentes por categoría', 'tariffs'],
        'seed_measurements' => ['Todas las mediciones originales del Excel', 'energy'],
        'source_documents' => ['Documentos originales', 'sources'],
        'source_ingestion_status' => ['Estado de carga de fuentes', 'sources'],
        'sources' => ['Organismos y fuentes', 'sources'],
        'tariff_normalized' => ['Tarifas nominales, reales y en dólares', 'tariffs'],
        'cammesa_hidro_binacional_monthly' => ['CAMMESA · Hidro Binacional mensual', 'energy'],
    ];

    public function catalog(): array
    {
        $definitions = [];
        foreach (DB::table('v5_records')->where('dataset', 'energiapy_metric_definitions_v5')->get() as $row) {
            $p = json_decode($row->payload, true);
            if (! empty($p['metric_code']) && ! empty($p['name'])) {
                $definitions[$p['metric_code']] = $p['name'];
            }
        }
        $definitions['PY_CONSUMPTION_ENERGY'] ??= $definitions['PY_CONSUMPTION_TREND'] ?? 'Consumo de Paraguay';
        $definitions['PY_MAX_DEMAND'] ??= $definitions['PY_DEMAND_PEAK'] ?? 'Demanda máxima de Paraguay';
        $groups = DB::table('observations')->whereNotNull('variable_code')->where('variable_code', '!=', '')
            ->selectRaw('variable_code, asset_code, unit, grain, source_code, COUNT(*) AS records, MIN(period_start) AS first_period, MAX(period_end) AS last_period')
            ->groupBy('variable_code', 'asset_code', 'unit', 'grain', 'source_code')->get()->groupBy('variable_code');
        $metrics = [];
        foreach ($groups as $key => $rows) {
            $category = $this->metricCategory($key);
            $metrics[] = [
                'key' => $key, 'label' => $definitions[$key] ?? $this->humanize($key),
                'category' => $category, 'category_label' => self::CATEGORIES[$category],
                'count' => (int) $rows->sum('records'),
                'from' => $rows->pluck('first_period')->filter()->min(), 'to' => $rows->pluck('last_period')->filter()->max(),
                'grains' => $rows->pluck('grain')->unique()->sort()->values()->all(),
                'assets' => $rows->pluck('asset_code')->unique()->sort()->values()->all(),
                'units' => $rows->pluck('unit')->unique()->sort()->values()->all(),
                'sources' => $rows->pluck('source_code')->unique()->sort()->values()->all(),
                'series' => $rows->map(fn ($r) => ['asset' => $r->asset_code, 'unit' => $r->unit, 'grain' => $r->grain, 'source' => $r->source_code, 'count' => (int) $r->records])->values()->all(),
            ];
        }
        usort($metrics, fn ($a, $b) => strnatcasecmp($a['label'], $b['label']));
        $datasets = [];
        foreach (DB::table('v5_records')->orderBy('dataset')->orderBy('row_number')->get(['dataset', 'payload'])->groupBy('dataset') as $key => $records) {
            $columns = [];
            foreach ($records as $record) {
                $p = json_decode($record->payload, true);
                if (is_array($p)) {
                    $columns = array_values(array_unique(array_merge($columns, array_keys($p))));
                }
            }
            $short = preg_replace('/^energiapy_|_v5$/', '', $key);
            [$label, $category] = self::DATASETS[$short] ?? [$this->humanize($short), 'sources'];
            $datasets[] = ['key' => $key, 'label' => $label, 'category' => $category, 'category_label' => self::CATEGORIES[$category], 'count' => $records->count(), 'columns' => $columns, 'date_column' => $this->dateColumns($columns)[0]];
        }

        $csvRows = array_sum(array_map(fn ($d) => str_starts_with($d['key'], 'energiapy_') ? $d['count'] : 0, $datasets));
        $archivedSheets = array_values(array_filter($datasets, fn ($d) => str_starts_with($d['key'], 'workbook_v5_')));

        return [
            'categories' => array_map(fn ($key, $label) => ['key' => $key, 'label' => $label], array_keys(self::CATEGORIES), self::CATEGORIES),
            'metrics' => $metrics, 'datasets' => $datasets,
            'meta' => ['workbook' => 'EnergiaPY_Base_Maestra_v5.xlsx', 'dataset_count' => count($datasets), 'workbook_rows' => $csvRows,
                'archived_sheet_count' => count($archivedSheets), 'archived_sheet_rows' => array_sum(array_column($archivedSheets, 'count')),
                'observations' => array_sum(array_column($metrics, 'count'))],
        ];
    }

    public function dataset(array $filter): array
    {
        $key = $filter['dataset'];
        $q = DB::table('v5_records')->where('dataset', $key);
        abort_unless((clone $q)->exists(), 404, 'Conjunto histórico no disponible.');
        $records = $q->orderBy('row_number')->get();
        $columns = [];
        $rows = [];
        foreach ($records as $record) {
            $payload = json_decode($record->payload, true);
            if (! is_array($payload)) {
                continue;
            }
            $columns = array_values(array_unique(array_merge($columns, array_keys($payload))));
            $rows[] = $payload + ['_record_id' => $record->id, '_row_number' => $record->row_number];
        }
        [$startColumn, $endColumn] = $this->dateColumns($columns);
        $filtered = array_values(array_filter($rows, function ($row) use ($filter, $startColumn, $endColumn) {
            if (! empty($filter['q']) && mb_stripos(json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), trim($filter['q'])) === false) {
                return false;
            }
            if ($startColumn && (! empty($filter['from']) || ! empty($filter['to']))) {
                $start = $this->dateValue($row[$startColumn] ?? null, false);
                $end = $this->dateValue($row[$endColumn ?? $startColumn] ?? null, true) ?? $start;
                if (! $start || (! empty($filter['from']) && $end < substr($filter['from'], 0, 10)) || (! empty($filter['to']) && $start > substr($filter['to'], 0, 10))) {
                    return false;
                }
            }
            return true;
        }));
        $page = max(1, (int) ($filter['page'] ?? 1));

        return ['data' => array_slice($filtered, ($page - 1) * 500, 500), 'meta' => [
            'total' => count($filtered), 'page' => $page, 'per_page' => 500, 'dataset' => $key, 'columns' => $columns,
            'date_column' => $startColumn, 'date_filter_applied' => $startColumn !== null && (! empty($filter['from']) || ! empty($filter['to'])),
            'aggregation' => 'Filas originales del Excel; no se suman indicadores o unidades diferentes.',
        ]];
    }

    public function observationFilters(string $metric): array
    {
        $rows = DB::table('observations')->where('variable_code', $metric)->select('grain', 'asset_code', 'unit', 'source_code')->distinct()->get();
        return ['grains' => $rows->pluck('grain')->unique()->sort()->values()->all(), 'assets' => $rows->pluck('asset_code')->unique()->sort()->values()->all(), 'units' => $rows->pluck('unit')->unique()->sort()->values()->all(), 'sources' => $rows->pluck('source_code')->unique()->sort()->values()->all()];
    }

    private function dateColumns(array $columns): array
    {
        foreach (['period_start' => 'period_end', 'start_date' => 'end_date', 'effective_from' => 'effective_to', 'observed_at' => null, 'observed_at_local' => null, 'Fecha' => null, 'date' => null, 'issued' => null, 'year' => null, 'Año' => null, 'From year' => 'To year', 'fx_snapshot_date' => null, 'source_published_at' => null, 'collected_at' => null] as $start => $end) {
            if (in_array($start, $columns, true)) {
                return [$start, $end && in_array($end, $columns, true) ? $end : null];
            }
        }
        return [null, null];
    }

    private function dateValue($value, bool $end): ?string
    {
        $value = trim((string) $value);
        if (preg_match('/^\d{4}$/', $value)) {
            return $value.($end ? '-12-31' : '-01-01');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T].*)?$/', $value) && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
            return substr($value, 0, 10);
        }
        return null;
    }

    private function metricCategory(string $key): string
    {
        return match (true) {
            (bool) preg_match('/TARIFF|CUSTOMER|HOUSEHOLD|RESIDENTIAL/', $key) => 'tariffs',
            (bool) preg_match('/PRICE|COST|CESSION|COMPENSATION|ROYALT|FX_|IPC|INFLATION|IMAEP|ECONOMIC/', $key) => 'economics',
            (bool) preg_match('/RIVER|RESERVOIR|HYDRO|TEMP|PRECIP|ENSO|RONI|WIND|HUMID|RAIN/', $key) => 'climate',
            (bool) preg_match('/LINE|SUBSTATION|GRID|NETWORK|TRANSMISSION|SAIDI|SAIFI|LOSS/', $key) => 'infrastructure',
            (bool) preg_match('/PLAN|SCENARIO|FORECAST|PROJECTION/', $key) => 'planning',
            default => 'energy',
        };
    }

    private function humanize(string $key): string
    {
        return ucfirst(mb_strtolower(str_replace('_', ' ', $key)));
    }
}
