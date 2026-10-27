# EnergiaPY.live

Aplicación Laravel 12, PHP 8.2+, Blade, CSS compilado y JavaScript/Leaflet local. No requiere Node en producción. Datos originales del pack V5 conservados en `storage/app/energiapy/base_v5`; los CSV se importan íntegros a `v5_records` y las observaciones normalizadas mantienen alternativas y procedencia. El esquema SQL original queda archivado; las migraciones Laravel son el esquema operativo.

## Instalación limpia con SQLite

En una copia nueva, instalar PHP 8.2 o superior con las extensiones de Composer, PDO SQLite, DOM/XML, mbstring y ZIP, y ejecutar `composer install` dentro de la carpeta de la aplicación. Crear `.env` a partir de `.env.example` solo si todavía no existe. Para desarrollo local, configurar `APP_ENV=local`, `APP_DEBUG=true`, `APP_URL=http://127.0.0.1:8080`, `DB_CONNECTION=sqlite`, `DB_DATABASE` con la ruta absoluta a `database/database.sqlite`, y `SESSION_SECURE_COOKIE=false`. En producción conservar la configuración segura y completar la conexión de la base elegida.

Crear el archivo SQLite vacío solamente para una instalación nueva; no reemplazar una base existente. En PowerShell, desde la carpeta de la aplicación:

```powershell
Copy-Item .env.example .env
New-Item database/database.sqlite -ItemType File
php artisan key:generate
php artisan migrate --force
php artisan energy:bootstrap-data
php artisan serve --host=127.0.0.1 --port=8080
```

Antes de la migración, editar `.env` como se indica arriba. El comando `energy:bootstrap-data` importa el Excel, las referencias históricas y los archivos de cartografía incluidos; no elimina la base y puede repetirse. Necesita la captura histórica ONS incluida en `storage/app/energiapy/raw/ONS_ITAIPU_HOURLY` y los demás archivos de referencia de `storage/app/energiapy` y `storage/app/gis`. Si falta una fuente, informa el comando que falló y se puede volver a ejecutar tras reponerla. Las lecturas en vivo no se incluyen en el bootstrap: para actualizarlas ejecutar el colector y mantener activo el programador descrito abajo; requieren acceso a sus fuentes externas.

Con `.env` y la base ya configurados, `composer run setup` también instala dependencias, ejecuta las migraciones y reconstruye todos los datos mediante `energy:bootstrap-data`.

## Desarrollo local en este equipo

Desde la carpeta superior:

```powershell
php -c tools/php.ini -S 127.0.0.1:8080 -t app/public
```

Abrir http://127.0.0.1:8080. La base SQLite local contiene las importaciones realizadas. El archivo `tools/php.ini` es específico de Windows; no subirlo al hosting.

El servidor web no ejecuta el programador por sí solo. Para actualizar periódicamente las fuentes mientras se trabaja en local, abrir otra consola desde `app` y ejecutar:

```powershell
$env:PHPRC = (Resolve-Path ../tools/php.ini).Path
php -c ../tools/php.ini artisan schedule:work
```

El programador revisa las fuentes cada 15 minutos y cada colector respeta su propia cadencia y retroceso ante errores. La variable `PHPRC` también configura los procesos PHP que inicia el programador. Mantener esa consola abierta; al detenerla o reiniciar el equipo deja de actualizarse. La fecha de cada lectura depende de la publicación de su fuente.

Desde `app`:

```powershell
php -c ../tools/php.ini vendor/phpunit/phpunit/phpunit --testdox
php -c ../tools/php.ini artisan energy:import-v5
php -c ../tools/php.ini artisan energy:archive-workbook-v5
php -c ../tools/php.ini artisan energy:import-workbook-history
php -c ../tools/php.ini artisan energy:import-workbook-context
php -c ../tools/php.ini artisan energy:import-cammesa-binational
php -c ../tools/php.ini artisan energy:import-cammesa-annual
php -c ../tools/php.ini artisan energy:import-ande-customer-capita-history
php -c ../tools/php.ini artisan energy:import-worldbank-electricity
php -c ../tools/php.ini artisan energy:import-ande-august-2026
php -c ../tools/php.ini artisan energy:import-ande-distribution-losses
php -c ../tools/php.ini artisan energy:import-ande-consumption-origin-2025
php -c ../tools/php.ini artisan energy:import-ande-balance-2024
php -c ../tools/php.ini artisan energy:import-itaipu-annual-reports
php -c ../tools/php.ini artisan energy:import-mendeley-demand
php -c ../tools/php.ini artisan energy:import-ons-itaipu-monthly
php -c ../tools/php.ini artisan energy:import-itaipu-historical-generation
php -c ../tools/php.ini artisan energy:import-vmme-monthly-sin
php -c ../tools/php.ini artisan energy:import-agency-june-2026
php -c ../tools/php.ini artisan energy:sync-transmission-gis
php -c ../tools/php.ini artisan energy:collect
php -c ../tools/php.ini artisan energy:health-check
```

Los collectors respetan cadencia y backoff. `energy:collect CODIGO --force` permite una captura manual; `--file=/ruta/captura` sirve para reprocesar un archivo conservando su fecha de modificación como captura. No usar fixtures de tests como datos de producción.

Los indicadores del Banco Mundial se importan desde respuestas oficiales guardadas. Para descargar respuestas nuevas y volver a importar: `php -c ../tools/php.ini artisan energy:import-worldbank-electricity --refresh`. El acceso a electricidad es una estimación del Banco Mundial. La serie anual de Yacyretá proviene de la planilla CAMMESA y se valida contra los meses completos. Las series de ANDE de clientes y facturación por habitante tienen cambios de metodología indicados en el gráfico.

`energy:import-mendeley-demand` conserva el CSV completo con 131.481 valores horarios de 2008–2022 y calcula las medias mensuales. Fuente: Morales-Mareco, Stalder, Sauer Ayala, Grillo y Verdún, [Electricity consumption and meteorological data from Paraguay, versión 3](https://data.mendeley.com/datasets/dm78h95ghk/3), DOI 10.17632/dm78h95ghk.3, licencia CC BY 4.0. Se trata de datos ANDE procesados por investigadores, que incluyen imputaciones; no son mediciones en vivo. `energy:import-ons-itaipu-monthly` calcula medias de potencia de la última captura local horaria del ONS, omitiendo meses con menos del 95 % de horas nominales. Conservar las capturas bajo `storage/app/energiapy/raw/ONS_ITAIPU_HOURLY`; ambas agregaciones se ejecutan manualmente y no modifican la demanda en vivo.

Los comandos `energy:import-vmme-monthly-sin` y `energy:import-agency-june-2026` cargan transcripciones contrastadas manualmente, no descargas automáticas. Los informes VMME presentan consumo mensual preliminar del SIN y pueden diferir de las cifras definitivas de ANDE; la fuente y la calidad quedan guardadas en cada observación. En el informe de noviembre de 2025, la URL oficial contiene la palabra «Octubre», aunque el contenido corresponde a noviembre.

El comando `energy:import-ande-august-2026` incorpora cifras mensuales y acumuladas publicadas por ANDE, conservando la URL, checksum y el extracto consultado. `energy:import-ande-distribution-losses` incorpora las pérdidas anuales de distribución de 2023 a 2025 publicadas por ANDE. `energy:import-ande-consumption-origin-2025` incorpora la composición del consumo nacional de 2025 transcrita de la publicación ANDE, con cifras publicadas en GWh enteros. `energy:import-ande-balance-2024` incorpora las categorías de energía total bruta del resumen estadístico ANDE 2024; son importaciones puntuales, con alcances distintos. El colector `AGENCIA_IP_ANDE` consulta diariamente la API pública de Agencia IP para encontrar comunicados que atribuyan a ANDE cifras mensuales inequívocas de consumo y aporte de Acaray; preserva la procedencia secundaria y no sustituye una referencia más reciente por otra antigua. Si cambia el formato, la consulta falla o los datos no se pueden identificar con certeza, conserva el último dato válido. El sitio de ANDE ha respondido con una página de protección a consultas automáticas, por lo que no se depende de su acceso directo.

La demanda modelada multiplica el promedio de consumo mensual por el peso correspondiente a la hora local, usando el perfil de planificación del Plan Maestro ANDE 2024–2043 y normalizando los pesos para conservar la energía mensual. Es un modelo orientativo, no una medición ni un pronóstico validado: no incorpora variaciones por clima, día de semana o estación. El aporte mensual de Acaray está expresado en MWh y no es potencia instantánea.

## Instalar en Hostinger

1. Crear base MariaDB/MySQL y usuario dedicado en hPanel. Usar PHP 8.2 o superior con curl, DOM/XML, fileinfo, mbstring, openssl, PDO MySQL, tokenizer, ctype y zip. Activar certificado HTTPS del dominio y redirección de www a energiapy.live.
2. Subir el contenido de `app` fuera de la raíz pública, por ejemplo `/home/USUARIO/energiapy`. Configurar document root en `/home/USUARIO/energiapy/public`. Si el plan obliga a `public_html`, copiar solo el contenido de `public` allí y ajustar en su `index.php` las rutas `../vendor`, `../bootstrap` y `../storage` para que apunten a la aplicación privada. Nunca exponer `.env`, `vendor`, `database`, `storage/app` ni los originales.
3. Copiar `.env.example` a `.env` y completar DB_DATABASE, DB_USERNAME y DB_PASSWORD del hosting. Mantener APP_DEBUG=false, APP_ENV=production y APP_URL=https://energiapy.live. No reutilizar la clave local.
4. Por SSH, desde la aplicación:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
php artisan key:generate --force
php artisan migrate --force
php artisan energy:bootstrap-data
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

5. El usuario PHP necesita escritura en `storage` y `bootstrap/cache`; usar permisos del hosting, nunca 777. No crear enlace público a los datos raw. Si se sube el paquete con vendor incluido, comprobar `composer check-platform-reqs --no-dev` en el servidor.
6. Configurar cron cada minuto en hPanel, usando la ruta PHP CLI que indique el plan:

```cron
* * * * * cd /home/USUARIO/energiapy && /usr/bin/php artisan schedule:run >> storage/logs/scheduler.log 2>&1
```

El scheduler evalúa los collectors cada 15 minutos; cada fuente aplica su propia cadencia. Ejecutar `php artisan schedule:list` y una captura manual para verificar. La salida no cero de una fuente inválida es intencional y preserva el último dato bueno.
7. Comprobar `/`, `/api/live` y `/fuentes`, certificado, cron, logs y denegación HTTP de `.env`. El cambio de DNS y la publicación no se realizaron desde este equipo.

## Datos, GIS y operación

- `energy:import-v5` es idempotente y no convierte acumulados anuales a valores actuales.
- `energy:archive-workbook-v5` conserva las celdas y fórmulas de las 36 hojas del Excel; las fórmulas sin resultado guardado no se presentan como valores calculados. `energy:import-workbook-history` y `energy:import-workbook-context` incorporan referencias numéricas fechadas y mantienen unidad, calidad y procedencia. Las series macroeconómicas WDI/IMF quedan marcadas como provisionales y los planes o pronósticos conservan su calidad original.
- `energy:import-cammesa-binational` conserva la planilla entregada y sus meses de Yacyretá con identificación de celda. Estas importaciones son idempotentes y requieren que el Excel y los CSV originales estén presentes en `storage/app/energiapy`.
- `energy:sync-transmission-gis` importa la copia trazable incluida de la cartografía latinoamericana pública de UPME: 179 líneas y 124 subestaciones de Paraguay. `--online` solicita una copia actual al servicio ArcGIS antes de importarla.
- `energy:import-gis archivo.geojson --kind=grid|substations|plants` valida EPSG:4326 regional, código, URL, fecha y calidad OFFICIAL_GIS, OFFICIAL_REGIONAL_GIS o DIGITIZED_FROM_OFFICIAL_MAP. Requiere revisión humana de procedencia antes de importar; la etiqueta por sí sola no prueba autoridad.
- `storage/app/gis/raw` conserva originales; `web/departments.geojson` está simplificado exclusivamente para visualización.
- Sin una medición actual, la capa de flujo dibuja los corredores verificados en forma neutra y discontinua, sin dirección ni MW. Las horas de ONS se normalizan desde el horario oficial de Brasília indicado por el portal; una publicación atrasada conserva su valor y aparece rotulada como atrasada.
- En Itaipú, el suministro horario a Paraguay se calcula únicamente cuando generación total y potencia destinada a Brasil pertenecen a la misma fuente y al mismo intervalo ONS. La fórmula y los identificadores de entrada viajan en la procedencia.
- Consumo territorial vincula el último dato anual ANDE del Excel con los sistemas regionales definidos por el Plan Maestro. El mapa lo rotula como histórico y aclara que el valor es regional, no departamental.
- Los precios del V5 son referencias fechadas. Las calculadoras muestran estimaciones sin impuestos/otros conceptos y no aseguran vigencia del pliego.
- Ver `IMPLEMENTATION_REPORT.md` para estado de fuentes y restricciones conocidas.

## Copias de seguridad y recuperación

Antes de cada despliegue: respaldar MariaDB con la herramienta de hPanel o `mariadb-dump --single-transaction` usando un archivo de credenciales privado, además de `.env` cifrado y `storage/app`. Conservar la versión anterior del código y su composer.lock. Evitar contraseñas en comandos/logs. Verificar semanalmente la restauración en una base separada y comparar conteos, sumas de comprobación raw y respuestas API. No ejecutar migraciones destructivas para revertir un despliegue; restaurar código compatible y copia validada. Activar backups externos del hosting y política de retención. La restauración MariaDB debe probarse en el entorno destino; este equipo solo dispone de SQLite.

## API

GET: `/api/live`, `/api/live/plants`, `/api/live/flows`, `/api/data/summary`, `/api/history`, `/api/history/replay`, `/api/projections`, `/api/citizenship/tariffs`, `/api/citizenship/history`, `/api/economics/summary`, `/api/gis/departments`, `/api/gis/grid`, `/api/gis/substations`, `/api/sources/status`, `/api/weather/current`, `/api/hydrology/current`, `/api/climate/enso`, `/api/observations/latest`, `/api/data/period`, `/api/data/territorial-demand`.

`history`: metric, asset, from, to, grain=raw|hour|day|month|year, page (500 registros/página). El grano selecciona observaciones nativas, no remuestrea. `history/replay`: date=AAAA-MM-DD; exige 90% de horas observadas. `data/period`: metric, asset, period=today|month|year, date opcional; acumulados exigen 100% de horas completas, una misma fuente y límites exactos. `gis/grid`: voltage=66|220|500. `observations/latest`: source opcional.

POST `/api/projections`: sesión y token CSRF, growth en porcentaje, capacity en GWh/año y scenario. Persiste versión, insumos y resultado; límite 20 solicitudes/minuto. No es una API de escritura anónima para terceros.

Valores observados incluyen fuente/URL, measured_at nullable, collected_at, quality, stale, parser_version y provenance/checksum. Fechas de publicación no se convierten en mediciones. null es ausencia, no cero.
