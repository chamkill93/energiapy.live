<?php
return [
 'user_agent'=>env('ENERGIAPY_SOURCE_USER_AGENT','EnergiaPY.live/1.1 (+https://energiapy.live/metodologia)'),
 'raw_retention_days'=>90,
 'cadences'=>['ONS_ITAIPU_HOURLY'=>43200,'ONS_INTL_2026'=>43200,'DMH_EMAS'=>900,'EBY_CURRENT_POWER'=>3600,'EBY_HYDRO_DAILY'=>86400,'BCP_FX_DAILY'=>86400,'NOAA_ENSO_PROB'=>86400,'AGENCIA_IP_ANDE'=>86400],
];
