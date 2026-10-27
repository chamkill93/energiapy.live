# Informe de implementación — EnergiaPY.live

Fecha de auditoría: 12/09/2026. Referencia: `ASTRA_ONE_SHOT_MASTER_PROMPT.md` del ZIP original.

## Resultado y alcance

Aplicación Laravel funcional creada desde cero en `app`, con los siete módulos, APIs, importación V5, collectors, GIS real de INE y UPME, cálculos, escenarios persistidos, controles temporales, estados de disponibilidad y guía Hostinger. La entrega es una versión local verificable; **no está publicada y no equivale a un servicio de telemetría nacional completo**. La falta de observaciones oficiales actuales se muestra como S/D, sin sustituirlas por cifras ilustrativas.

No se afirma cumplimiento total del finish gate de producción: quedan validaciones externas relevantes (detalladas abajo). La ausencia de esos datos no impide navegar y utilizar los módulos históricos, las calculadoras y las fuentes implementadas.

## Implementación

- Laravel 12 con composer.lock y objetivo PHP 8.2+, Blade, CSS listo y JavaScript vanilla. Leaflet 1.9.4 local; ninguna compilación Node necesaria en el servidor.
- Migraciones para observations, source_fetches, source_health, v5_records, grid_assets, flows, weather_observations, hydrology_observations y forecast_runs. Índices por variable/fecha, activo/fecha y fuente/captura.
- V5: 4.321 filas originales preservadas, 1.524 observaciones seed idempotentes; todas las alternativas y archivos originales conservados. No se ejecuta el esquema SQL original sobre las tablas Laravel ni se evalúan fórmulas Excel como código.
- Base local auditada: 3.512 observaciones (incluyendo capturas oficiales adicionales). Ningún fixture sintético fue importado en esta base.
- Selección canónica, fecha de medición nullable, calidad, frescura, parser, fuente, URL, checksum y raw. Histórico con ranking/paginación SQL de 500 filas; exportación CSV neutraliza fórmulas.
- Acumulados por día/mes/año: suma de MW medios horarios por una hora únicamente con cobertura completa, alineación exacta y misma fuente. Replay con umbral del 90%, autoplay/pausa y protección frente al polling actual.
- Mapa satelital con atribución y fallback OSM; 18 divisiones INE 2022 descargadas, originales conservados y GeoJSON web simplificado a unos 230 KB. No se dibujan líneas inventadas.
- Importador de GIS con procedencia y validación geométrica. La copia pública regional de UPME incorpora 179 líneas paraguayas (9 de 500 kV, 85 de 220 kV y 85 de 66 kV) y 124 subestaciones. Sin una lectura de flujo verificable, los corredores aparecen neutros y sin dirección ni MW.
- Detalle por central: lectura disponible, destinos, cobertura y acumulados, últimas observaciones horarias fechadas, hidrología y procedencia. Los KPI permiten acceder a centrales que aún no tienen ubicación verificada.
- Monitoreo: sistema, fuentes generadoras, clima, ENSO, hidrología e infraestructura histórica. Indicadores sin fuente operativa no se estiman.
- Ciudadanía: hogar normal/social por faja sobre todo el consumo, empresa con precios de contrato aportados, MW×horas, asequibilidad e histórico nominal/constante2025/USD. El histórico usa macro V5 provisional con fórmula y fuente, no implica precios vigentes.
- Economía separa doméstico, venta ANDE/Acaray, cesión, royalties/compensaciones, mercados externos y BCP.
- Proyecciones: escenarios exploratorios, rango de sensibilidad ±1 punto porcentual, base observada, comparación con referencias ANDE y registro persistente de inputs, versión y corrida. No es un pronóstico probabilístico ni un modelo de despacho; “nueva generación” requiere ajustar manualmente la disponibilidad anual.
- Fuentes: última captura/medición, licencia, cadencia, cobertura, versión, error y accesibilidad HTTP. HTTP 200 no se confunde con parser válido.
- Timeout, reintentos, backoff, locks, raw inmutable y último dato bueno conservado. Caché live de 60 s; scheduler con cadencia específica por fuente. HTTPS canónico en producción, CSRF en escritura, validación y límites.

## Fuentes y confianza de parsers

La confianza describe validación de estructura/semántica, no una probabilidad estadística.

| Fuente | Implementación y evidencia | Confianza / restricción |
|---|---|---|
| ONS Itaipú CSV | 1.680 valores horarios importados; total y suministro BR | Estructura alta; hora normalizada desde BRT/horario de Brasília documentado por ONS. El suministro PY se deriva solo como total menos Brasil en el mismo intervalo y conserva los IDs de entrada. |
| ONS intercambio 2026 | Parser y rechazo explícito de países no PY | Alta para esquema/destino; captura solo Argentina/Uruguay, ninguna lectura PY. |
| DMH EMAS JSON | 276 valores, 92 estaciones; temperatura, humedad, precipitación y UTC | Alta de parser, calidad oficial orientativa. Captura vence según cadencia. |
| EBY potencia HTML | Parser del contador que Elementor presenta al visitante | Media, HTML frágil; usa `data-to`, objetivo de la animación, y conserva como evidencia el texto fallback cuando difiere. La fuente no publica la hora exacta de medición. |
| EBY hidrología | Informe diario y cota extraídos; captura remota validada el 12/09 | Alta para fecha/cota del título; sin hora de medición ni caudal observado deducido. |
| BCP FX | 4 monedas y fecha publicada | Alta; referencia diaria, no cotización intradía. |
| NOAA ENSO | 27 probabilidades de 9 temporadas, año y mes de emisión | Alta; FORECAST explícito y suma/rangos validados; no implica pronóstico local paraguayo. |
| INE GIS | 18 geometrías oficiales y checksum | Alta de procedencia; simplificación solo visual, no catastral. |
| UPME GIS LATAM | 179 líneas y 124 subestaciones filtradas por Paraguay, con tensión, nombre, estado y checksum | Fuente pública de organismo gubernamental regional; requiere contraste con ANDE para decisiones operativas. |
| ANDE/EBY/Itaipú/INE/BCP históricos | Importación V5 y enlaces originales | Calidad original preservada; no revalidación completa de cada publicación V5. |
| Itaipú YRATO | Documentación oficial consultada; servicio de precipitación probado devolvió [] | Sin conector de observaciones activado: respuesta vacía no prueba disponibilidad operativa. |
| CAMMESA | Documentación pública consultada | Sin conector de mercado validado; no se usaron credenciales ni endpoints restringidos. |
| ANDE operación/red, INE ingreso actualizado, VMME y restantes | Catálogo y referencias según manifest | No conectados como series nuevas. No se presenta accesibilidad HTTP como integración completada. |

Fuentes de verificación: [EBY complejo y ubicación](https://www.eby.org.ar/complejo-hidroelectrico), [NOAA ENSO](https://cpc.ncep.noaa.gov/products/analysis_monitoring/enso/roni/probabilities/), [ANDE](https://www.ande.gov.py/), [YRATO](https://hidroinformatica.itaipu.gov.py/). URLs exactas por captura y checksums están en source_fetches y los payloads.

## QA ejecutado

- PHPUnit: **24 pruebas, 249 assertions, todas aprobadas**. Incluye render de rutas, ausencia de cifras inventadas, importación idempotente, pérdida de intervalos, preservación de último dato, fechas/monedas, probabilidades, coordenadas inválidas, rango de escenarios, GIS regional y procedencia territorial.
- PHP ejecutado localmente: 8.5.8; dependencias resueltas para PHP 8.2.30. Sintaxis y rutas/Blade compiladas correctamente. JavaScript app/modules/map-complete/extended-modules comprobado con `node --check`.
- Auditoría visual comparada con mockup: mapa dominante, un solo panel “Paraguay ahora”, navegación de siete módulos, controles a la derecha, seis KPI y detalle lateral. Se corrigió solapamiento vertical de controles con la barra inferior mediante scroll del panel, y ubicación de herramientas.
- Navegador real: mapa satelital, controles móviles, HOY con unidad MWh y cobertura 0% sin inventar energía, calculadora residencial/social y evolución tarifaria. Consola sin errores JS en las vistas inspeccionadas.
- Responsive: vistas móvil 390×844 y tablet 768×1024 inspeccionadas visualmente; ancho 1024×768 y escritorio 1440×900 comprobados sin desbordamiento de documento. Los indicadores móviles tienen desplazamiento horizontal intencional. Ver complemento QA final para otros tamaños.
- Salud HTTP: comprobación de 19 fuentes guardada en endpoint-health.json; 14 accesibles en esa ejecución, las restantes con errores/timeout. No es garantía permanente de accesibilidad.
- Backup SQLite: copia mediante API de SQLite, `integrity_check=ok` y mismo conteo de observaciones. No se alteró la base original durante la prueba.

## Pendientes que requieren validación externa

1. Contrastar las coordenadas públicas de Itaipú y Acaray con una capa GIS propia del operador. Se muestran como referencias públicas identificadas; Yacyretá usa coordenadas oficiales EBY.
2. Obtener demanda nacional PY, generación Acaray y destino paraguayo de Yacyretá mediante fuentes operativas públicas. Esos KPI permanecen S/D y la curva de demanda queda vacía, sin trazado artificial.
3. Obtener consumo territorial en tiempo real y nuevas integraciones ANDE/YRATO/CAMMESA según disponibilidad. La referencia territorial ANDE 2024 está separada y rotulada como histórica.
4. Probar migraciones/consultas y restauración en MariaDB 10.6+ o MySQL 8+, PHP del hosting, permisos, cron y dominio HTTPS. No hay instancia MariaDB ni cuenta Hostinger configurada en este workspace.
5. Revalidar vigencia tarifaria y reconciliar macro V5 antes de presentar conversiones como definitivas. Configurar un canal real de contacto del proyecto antes de publicarlo como tal.

Estas limitaciones están documentadas y representadas en la interfaz. No son datos que puedan completarse copiando cifras del mockup. El paquete de entrega excluye `.env`, base local, logs, cachés, credenciales y vendor; conserva fuentes y composer.lock para instalación reproducible.

## Complemento QA final

También se comprobaron 360×800 y 1920×1080 sin desbordamiento horizontal del documento. Navegación móvil a Monitoreo y sección ENSO cargadas sin errores de consola. Lint PHP: 39 archivos aprobados. El viewport temporal fue restablecido y la vista previa quedó en la landing. Archivo ZIP verificado con CRC; no contiene `.env` ni base local. El paquete requiere `composer install` según README y vuelve a importar V5; las capturas raw completas y el respaldo local permanecen en este workspace, fuera del ZIP de despliegue.

## Revisión de legibilidad y landing — 12/09/2026

La landing ahora ofrece un selector entre informes publicados y lecturas actuales. Si no hay cobertura actual, abre los informes: consumo y demanda máxima anual, generación/suministro de Itaipú, pérdidas y factor de carga, con período, unidad y publicación original. No se reutilizan como potencia en vivo. Se mantienen los contratos API y los datos originales.

Se agregó un diccionario de presentación compartido para nombres de indicadores, centrales, organismos, unidades, frecuencia y calidad. Los códigos y diagnósticos técnicos de fuentes quedan dentro de un desplegable. Se retiraron referencias V5 y versiones de lectura del contenido principal. Se verificaron sintaxis JavaScript, formatos, plantillas Blade y navegación en landing, Monitoreo, Histórico, Ciudadanía, Economía y Fuentes.

## Separación definitiva de vistas — 12/09/2026

Por corrección del usuario, se retiró el selector de informes históricos de la landing. `/` conserva exclusivamente indicadores operativos y no carga publications.js. La réplica del mapa con informes históricos se encuentra en `/monitoreo`, alimentada por `/api/monitoring/summary` desde v5_records, con procedencia y fechas. Los controles de períodos/replay no aparecen en la landing. Verificación: prueba de separación aprobada (12 assertions), navegación real comprobada en ambas vistas, mapa histórico con consumo anual y cota del embalse visibles. Esta decisión reemplaza la revisión anterior del selector en landing.

## Capas cartográficas completas — 12/09/2026

La landing carga por defecto las redes de 500 y 220 kV, 124 subestaciones y las tres centrales. El panel permite activar corredores de flujo, hidrología EBY, límites INE, consumo por los seis sistemas regionales ANDE y 90 estaciones climáticas con coordenadas. Los nombres técnicos internos fueron sustituidos por nombre, tensión, estado, período y alcance comprensibles. El consumo territorial 2024 permanece explícitamente histórico y no alimenta los KPI en vivo.

La cartografía UPME filtrada por Paraguay se conserva como GeoJSON de origen en `storage/app/gis/seed` y puede importarse sin conexión con `energy:sync-transmission-gis`; la opción `--online` solicita una copia vigente. La validación final cubrió sintaxis JavaScript y revisión visual móvil de todas las superposiciones.

## Lecturas operativas en la landing — 12/09/2026

Se corrigió la selección de datos actuales. La landing muestra 11.112,317 MW de generación horaria Itaipú, 7.750,013 MW destinados a Brasil, 3.362,304 MW destinados a Paraguay calculados con ambos valores del mismo intervalo ONS, y 1.999 MW publicados por EBY para la última hora de Yacyretá. Cada KPI indica si la publicación es reciente o atrasada y muestra la hora paraguaya. La demanda nacional, Acaray y el destino argentino siguen en S/D porque no existe una fuente pública actual integrada que los sustente.

La captura DMH se refrescó a 90 estaciones recientes. QA: 24 pruebas y 254 assertions aprobadas; la landing se verificó en navegador con los valores, estados y horas visibles.
