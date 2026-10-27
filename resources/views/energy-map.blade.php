<section class="map-shell" aria-label="Mapa e indicadores"><div id="map" aria-label="Mapa energético de Paraguay"></div>
<div class="map-topline"><span class="eyebrow">SISTEMA ELÉCTRICO NACIONAL</span></div>
<section class="now-panel glass" aria-labelledby="now-title"><div class="panel-heading"><h1 id="now-title">PARAGUAY AHORA</h1></div>
<div class="demand-value"><span id="demand-value" class="unavailable">Cargando</span><span>MW</span></div><div class="missing-label" id="demand-state">Sin lectura actual verificada</div>
<div class="now-pair"><div><small>Energía hoy</small><strong id="energy-today">Cargando</strong></div><div><small>Pico de hoy</small><strong id="peak-today">Cargando</strong></div></div>
</section>
<div class="map-compass" aria-hidden="true">N<span>➤</span></div><div class="map-tools"><button class="icon-button" id="zoom-in" aria-label="Acercar mapa">+</button><button class="icon-button" id="zoom-out" aria-label="Alejar mapa">−</button><button class="icon-button" id="recenter" aria-label="Centrar Paraguay">⌖</button></div>
<button class="mobile-layers" id="layers-toggle" aria-expanded="false">▱ Capas y período</button>
<aside class="map-controls" aria-label="Controles del mapa"><section class="glass layer-panel"><div class="panel-heading"><h2><img src="/assets/icons/layers.svg" alt="">Capas del mapa</h2><button id="layers-close" class="icon-button" aria-label="Cerrar capas">✕</button></div>
@foreach(['flows'=>'Flujo energético','grid500'=>'Red 500 kV','grid220'=>'Red 220 kV','substations'=>'Subestaciones','plants'=>'Centrales de generación','territorial'=>'Consumo territorial'] as $key=>$label)
<label><input type="checkbox" data-layer="{{ $key }}" @checked(in_array($key,['grid500','grid220','substations','plants']))><span>{{ $label }}</span>@if($key==='territorial')<small>HIST.</small>@endif @if(in_array($key,['grid500','grid220']))<i class="line-key {{ $key==='grid500'?'blue':'green' }}"></i>@endif</label>
@endforeach</section>
<section class="glass period-panel"><h2>Modo de visualización</h2><div class="segmented" id="period-control"><button class="selected" data-period="now">AHORA</button><button data-period="today">HOY</button><button data-period="month">MES</button><button data-period="year">AÑO</button></div></section>
<section class="glass temporal-panel"><h2><img src="/assets/icons/calendar.svg" alt="">Control temporal</h2><label class="sr-only" for="replay-date">Fecha para reproducir</label><input type="date" id="replay-date"><button class="replay-button" id="replay-button"><span>▶</span> Reproducir día</button><input type="range" id="replay-time" min="0" max="23" value="0" aria-label="Hora de reproducción" disabled><div class="time-labels"><span>00:00</span><span id="replay-hour">Ahora</span><span>24:00</span></div><p id="replay-notice" class="muted">Requiere al menos 90% de cobertura horaria.</p></section></aside>
<div class="map-bottom-left"><div class="map-legend"><span><i class="central-red"></i>Itaipú</span><span><i class="central-red"></i>Acaray</span><span><i class="central-red"></i>Yacyretá</span></div><label class="basemap-label"><span class="sr-only">Mapa base</span><select id="basemap"><option value="dark">Esri · satélite</option><option value="osm">OpenStreetMap</option></select></label></div>
<div class="map-data-note" id="map-note" hidden><span class="status-dot amber"></span>Sin demanda actual · explorá las centrales y los datos históricos</div>
<section class="kpi-bar glass" aria-label="Resumen energético">
@foreach([['demand','Demanda PY','blue'],['itaipu','Itaipú → PY','blue'],['yacyreta','Yacyretá · generación','amber'],['acaray','Acaray · generación','green'],['brazil','Itaipú → Brasil','green'],['argentina','Yacyretá → Argentina','amber']] as $k)
<button class="kpi" data-detail="{{ $k[0] }}"><img src="/assets/icons/{{ in_array($k[0],['itaipu','yacyreta','acaray']) ? 'plant-'.$k[0] : ($k[0] === 'demand' ? 'demand' : 'export') }}.svg" alt="" class="{{ $k[2] }}"><span><small>{{ $k[1] }}</small><strong id="kpi-{{ $k[0] }}" class="unavailable">Cargando</strong><span class="kpi-note">Sin lectura verificada</span></span></button>
@endforeach
</section></section>
