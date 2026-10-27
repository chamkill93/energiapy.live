<!doctype html><html lang="es" data-theme="dark"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}"><title>EnergiaPY.live · La energía de Paraguay, en datos</title>
<meta name="description" content="Explorá la energía de Paraguay: generación, demanda, historia, tarifas y fuentes oficiales en un mapa abierto y transparente."><meta name="theme-color" content="#04111d"><link rel="canonical" href="https://energiapy.live{{ request()->getPathInfo() }}"><link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="/assets/branding/theme-tokens.css"><link rel="stylesheet" href="/vendor/leaflet/leaflet.css"><link rel="stylesheet" href="/app.css"><link rel="stylesheet" href="/plant-markers.css"><link rel="stylesheet" href="/footer.css">
<script>try{document.documentElement.dataset.theme=localStorage.getItem('ep-theme')||'dark'}catch(e){}</script><script src="/vendor/leaflet/leaflet.js" defer></script><script src="/presentation.js" defer></script><script src="/app.js" defer></script><link rel="stylesheet" href="/energy-flow.css"><script src="/energy-flow.js" defer></script><script src="/map-complete.js" defer></script><script src="/map-visuals.js" defer></script></head>
<body data-page="{{ $page }}"><header class="header">
<a href="/" class="brand"><img src="/assets/branding/mark.svg" alt="" width="38" height="38"><span><strong>EnergiaPY<span class="dot-live">.live</span></strong><small>La energía de Paraguay, en datos</small></span></a>
<button class="icon-button mobile-menu" aria-label="Abrir navegación" aria-expanded="false" id="menu-toggle">☰</button>
<nav class="navigation" aria-label="Navegación principal">
@foreach(['mapa'=>['/monitoreo','Monitoreo','live'],'historico'=>['/historico','Histórico','calendar'],'proyecciones'=>['/proyecciones','Proyecciones','quality'],'ciudadania'=>['/ciudadania','Ciudadanía','substation'],'economia'=>['/economia','Economía','export'],'fuentes'=>['/fuentes','Fuentes','source']] as $key=>$item)
<a href="{{ $item[0] }}" class="nav-link {{ $page === $key ? 'active' : '' }}" @if($page === $key) aria-current="page" @endif><img src="/assets/icons/{{ $item[2] }}.svg" alt=""><span>{{ $item[1] }}</span></a>
@endforeach</nav>
<div class="utilities"><button id="theme-toggle" class="icon-button" aria-label="Cambiar tema">☼</button><span class="language">ES</span><a href="/fuentes" class="health-link" hidden><span class="status-dot amber"></span><span><strong id="global-status">Consultando fuentes</strong><small id="global-time">Hora de Paraguay · UTC−3</small></span></a></div>
</header>@yield('content')
<footer class="site-footer"><a href="/" class="footer-brand"><img src="/assets/branding/mark.svg" width="24" height="24" alt=""><span>EnergiaPY<span>.live</span></span></a><nav class="footer-links" aria-label="Información y contacto"><a href="/fuentes">Fuentes</a><a href="/metodologia">Metodología</a><a href="/sobre">Sobre el proyecto</a><a href="/contacto">Contacto</a></nav></footer>
<div id="toast" role="status" hidden></div><dialog id="detail-dialog"><button class="close-dialog icon-button" aria-label="Cerrar detalle">✕</button><div id="detail-content"></div></dialog></body></html>

