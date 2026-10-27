-- EnergiaPY.live Base Maestra v4
-- MySQL/MariaDB seed architecture
CREATE TABLE sources (code VARCHAR(30) PRIMARY KEY, name VARCHAR(180), country VARCHAR(20), authority_class VARCHAR(40), base_url TEXT, scope TEXT);
CREATE TABLE assets (code VARCHAR(50) PRIMARY KEY, name VARCHAR(180), type VARCHAR(60), country VARCHAR(20), lat DECIMAL(10,6) NULL, lon DECIMAL(10,6) NULL, notes TEXT);
CREATE TABLE source_documents (document_code VARCHAR(80) PRIMARY KEY, source_code VARCHAR(30), title VARCHAR(255), document_type VARCHAR(60), period_start VARCHAR(30), period_end VARCHAR(30), url TEXT, authority_class VARCHAR(40), load_status VARCHAR(30), notes TEXT);
CREATE TABLE measurements (id BIGINT AUTO_INCREMENT PRIMARY KEY, record_id VARCHAR(20) UNIQUE, period_start DATETIME NULL, period_end DATETIME NULL, grain VARCHAR(30), variable_code VARCHAR(80), asset_code VARCHAR(50), origin_code VARCHAR(50), destination_code VARCHAR(80), value_source DECIMAL(24,8), unit_source VARCHAR(30), value_normalized DECIMAL(24,8), unit_normalized VARCHAR(30), source_code VARCHAR(30), authority_class VARCHAR(40), quality_class VARCHAR(40), canonical_flag TINYINT(1) DEFAULT 1, revision_priority INT DEFAULT 50, source_url TEXT, source_file_code VARCHAR(80), source_published_at DATETIME NULL, collected_at DATETIME NULL, notes TEXT, INDEX idx_metric_time(variable_code,asset_code,period_start,canonical_flag), INDEX idx_source(source_code,source_file_code));
CREATE TABLE prices (id BIGINT AUTO_INCREMENT PRIMARY KEY, price_id VARCHAR(20) UNIQUE, period_start DATETIME NULL, period_end DATETIME NULL, grain VARCHAR(30), price_code VARCHAR(80), asset_code VARCHAR(50), market_counterparty VARCHAR(100), value DECIMAL(20,8), unit VARCHAR(30), currency VARCHAR(10), source_code VARCHAR(30), authority_class VARCHAR(40), quality_class VARCHAR(40), source_url TEXT, revision_priority INT, notes TEXT, INDEX idx_price_time(price_code,asset_code,period_start));
CREATE TABLE economic_terms (id BIGINT AUTO_INCREMENT PRIMARY KEY, term_id VARCHAR(20) UNIQUE, term_code VARCHAR(80), asset_code VARCHAR(50), effective_from DATE NULL, effective_to DATE NULL, concept TEXT, value DECIMAL(24,8) NULL, unit VARCHAR(40), currency VARCHAR(10), source_code VARCHAR(30), authority_class VARCHAR(40), source_url TEXT, notes TEXT);
CREATE TABLE economic_transactions (id BIGINT AUTO_INCREMENT PRIMARY KEY, transaction_id VARCHAR(20) UNIQUE, period_start DATETIME NULL, period_end DATETIME NULL, grain VARCHAR(30), transaction_code VARCHAR(100), asset_code VARCHAR(50), payer VARCHAR(80), payee VARCHAR(80), value DECIMAL(24,2), currency VARCHAR(10), source_code VARCHAR(30), authority_class VARCHAR(40), quality_class VARCHAR(40), source_url TEXT, notes TEXT, INDEX idx_econ_time(transaction_code,period_start));
CREATE TABLE infrastructure (id BIGINT AUTO_INCREMENT PRIMARY KEY, year SMALLINT, metric_code VARCHAR(80), value DECIMAL(24,4), unit VARCHAR(20), scope VARCHAR(40), source_code VARCHAR(30), authority_class VARCHAR(40), source_url TEXT, notes TEXT, INDEX idx_infra(metric_code,year));
CREATE TABLE quality_issues (issue_id VARCHAR(20) PRIMARY KEY, severity VARCHAR(20), area VARCHAR(60), period VARCHAR(60), issue TEXT, evidence TEXT, risk TEXT, recommended_action TEXT);
CREATE TABLE metric_definitions (metric_code VARCHAR(80) PRIMARY KEY, name VARCHAR(180), unit VARCHAR(30), definition TEXT, source TEXT, grain VARCHAR(50), class VARCHAR(40));
CREATE TABLE import_queue (job_code VARCHAR(80) PRIMARY KEY, source_code VARCHAR(30), grain VARCHAR(40), url TEXT, priority VARCHAR(20), status VARCHAR(30), notes TEXT);
CREATE TABLE coverage (dataset VARCHAR(120), source_start VARCHAR(30), source_end VARCHAR(30), loaded_start VARCHAR(30), loaded_end VARCHAR(30), status VARCHAR(30), notes TEXT);

CREATE TABLE retail_tariff_bands (
  tariff_id VARCHAR(20) PRIMARY KEY, effective_from DATE, effective_to DATE NULL, category_code VARCHAR(20), category_name VARCHAR(120),
  record_type VARCHAR(30), min_kwh_month DECIMAL(12,2) NULL, max_kwh_month DECIMAL(12,2) NULL, price_gs_kwh DECIMAL(16,4) NULL, discount_pct DECIMAL(8,4) NULL,
  source_code VARCHAR(30), quality_class VARCHAR(40), source_url TEXT, notes TEXT, INDEX idx_retail_tariff(category_code,effective_from,record_type)
);


CREATE TABLE macro_annual (
  year SMALLINT PRIMARY KEY,
  cpi_index_2010_100 DECIMAL(20,8) NOT NULL,
  inflation_pct DECIMAL(12,8) NULL,
  fx_pyg_per_usd_avg DECIMAL(20,8) NOT NULL,
  cpi_source_code VARCHAR(30),
  fx_source_code VARCHAR(30),
  authority_class VARCHAR(40),
  quality_class VARCHAR(40),
  bcp_reconcile_pending TINYINT(1) DEFAULT 1,
  cpi_source_url TEXT,
  fx_source_url TEXT,
  notes TEXT
);

CREATE TABLE macro_observations (
  observation_id VARCHAR(30) PRIMARY KEY,
  observed_at DATE NOT NULL,
  series_code VARCHAR(80) NOT NULL,
  value DECIMAL(24,8) NOT NULL,
  unit VARCHAR(30),
  source_code VARCHAR(30),
  quality_class VARCHAR(50),
  source_url TEXT,
  notes TEXT,
  INDEX idx_macro_series_date(series_code, observed_at)
);

CREATE TABLE tariff_normalized (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  year SMALLINT NOT NULL,
  price_code VARCHAR(80) NOT NULL,
  nominal_gs_kwh DECIMAL(20,8) NOT NULL,
  cpi_index_2010_100 DECIMAL(20,8) NOT NULL,
  fx_pyg_per_usd_avg DECIMAL(20,8) NOT NULL,
  real_2025_gs_kwh DECIMAL(20,8) NOT NULL,
  usd_mwh DECIMAL(20,8) NOT NULL,
  tariff_quality VARCHAR(40),
  macro_quality VARCHAR(40),
  tariff_source_url TEXT,
  cpi_source_url TEXT,
  fx_source_url TEXT,
  UNIQUE KEY uq_tariff_norm(year, price_code),
  INDEX idx_tariff_norm_code(price_code, year)
);


-- EnergiaPY V5 extensions: current baselines and predictive context
CREATE TABLE IF NOT EXISTS current_baselines (
 baseline_id VARCHAR(32) PRIMARY KEY, period_start VARCHAR(32), period_end VARCHAR(32), grain VARCHAR(32), domain VARCHAR(64), metric_code VARCHAR(128), asset_code VARCHAR(64), value DECIMAL(24,6), unit VARCHAR(64), source_code VARCHAR(32), authority_class VARCHAR(64), quality_class VARCHAR(64), baseline_type VARCHAR(64), source_url TEXT, collected_at VARCHAR(64), notes TEXT,
 INDEX idx_cb_metric_period(metric_code, period_start), INDEX idx_cb_domain(domain)
);
CREATE TABLE IF NOT EXISTS ande_customers_2025 (year SMALLINT, category VARCHAR(128), billed_kwh DECIMAL(24,3), customers BIGINT NULL, kwh_per_customer_year DECIMAL(24,6) NULL, kwh_per_customer_month DECIMAL(24,6) NULL, share_of_national_subtotal_pct DECIMAL(12,6) NULL, source_url TEXT, quality_class VARCHAR(64));
CREATE TABLE IF NOT EXISTS hydrology_current (observed_at VARCHAR(64), asset VARCHAR(64), variable VARCHAR(128), value DECIMAL(24,6), unit VARCHAR(32), source_code VARCHAR(32), quality_class VARCHAR(64), source_url TEXT, notes TEXT);
CREATE TABLE IF NOT EXISTS enso_outlook (issued VARCHAR(16), season VARCHAR(8), season_label VARCHAR(32), la_nina_pct DECIMAL(8,3), neutral_pct DECIMAL(8,3), el_nino_pct DECIMAL(8,3), roni_median_c DECIMAL(8,3) NULL, source_code VARCHAR(32), quality_class VARCHAR(64), prob_source_url TEXT, strength_source_url TEXT, notes TEXT);
CREATE TABLE IF NOT EXISTS climate_station_snapshot (station VARCHAR(255), department VARCHAR(64), observed_at_local VARCHAR(64), temperature_max_so_far DECIMAL(8,3), unit VARCHAR(16), source_code VARCHAR(32), quality_class VARCHAR(64), source_url TEXT, notes TEXT);
CREATE TABLE IF NOT EXISTS household_context (metric_code VARCHAR(128), year SMALLINT, value DECIMAL(24,6), unit VARCHAR(64), description TEXT, source_code VARCHAR(32), quality_class VARCHAR(64), source_url TEXT, INDEX idx_hh_metric_year(metric_code,year));
CREATE TABLE IF NOT EXISTS imaep_current (metric_code VARCHAR(128), period_start DATE, period_end DATE, value DECIMAL(16,6), unit VARCHAR(32), description TEXT, source_code VARCHAR(32), quality_class VARCHAR(64), source_url TEXT);
CREATE TABLE IF NOT EXISTS grid_quality_current (period VARCHAR(32), metric VARCHAR(128), value DECIMAL(16,6), unit VARCHAR(64), scope VARCHAR(128), source_code VARCHAR(32), quality_class VARCHAR(64), source_url TEXT, notes TEXT);
CREATE TABLE IF NOT EXISTS planning_baselines (metric_code VARCHAR(128), from_year SMALLINT, to_year SMALLINT, value DECIMAL(24,6), unit VARCHAR(64), description TEXT, source_code VARCHAR(32), quality_class VARCHAR(64), source_url TEXT);
CREATE TABLE IF NOT EXISTS source_ingestion_status (dataset_code VARCHAR(128) PRIMARY KEY, source_code VARCHAR(32), dataset TEXT, grain VARCHAR(64), delivery VARCHAR(128), status VARCHAR(64), catalog_url TEXT, direct_url TEXT, notes TEXT);
