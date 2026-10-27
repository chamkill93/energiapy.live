(function(global){
  function clamp(v,a,b){ return Math.max(a,Math.min(b,v)); }
  function weightFromMW(mw){ return clamp(2 + Math.sqrt(Math.abs(Number(mw)||0))/18, 2, 10); }
  function durationFromMW(mw){ return clamp(2.2 - Math.sqrt(Math.abs(Number(mw)||0))/70, .65, 2.2); }
  function addEnergyFlow(map, opts){
    if(!global.L) throw new Error('Leaflet (L) is required');
    if(!Array.isArray(opts.coordinates) || opts.coordinates.length<2) return null;
    var coords = opts.direction === 'reverse' ? opts.coordinates.slice().reverse() : opts.coordinates;
    var stale = !!opts.stale;
    var line = L.polyline(coords,{color:opts.color||'#6ceaff',weight:weightFromMW(opts.mw),opacity:stale?.35:.9,className:'ep-flow ep-flow-animated'+(stale?' is-stale':'')}).addTo(map);
    var el=line.getElement();
    if(el){ el.style.setProperty('--ep-flow-duration', durationFromMW(opts.mw)+'s'); }
    line.bindTooltip((opts.label||'Flujo')+' · '+(opts.mw==null?'S/D':Number(opts.mw).toLocaleString('es-PY')+' MW')+(stale?' · STALE':''));
    return line;
  }
  global.EnergiaPYMap={addEnergyFlow:addEnergyFlow,weightFromMW:weightFromMW,durationFromMW:durationFromMW};
})(window);
