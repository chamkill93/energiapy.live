'use strict';

// El contorno es una unión visual de los departamentos INE, no una delimitación jurídica.
// Los rayos son símbolos de destino; no representan líneas de transmisión ni flujos medidos.
let destinationLayer, destinationSignature=null;
const mapInitWithLayers=initMap;
initMap=async function(){
 await mapInitWithLayers();
 map.createPane('paraguay-outline');
 map.getPane('paraguay-outline').style.zIndex='410';
 map.getPane('paraguay-outline').style.pointerEvents='none';
 fetch('/assets/paraguay-contorno.geojson').then(response=>{
  if(!response.ok)throw new Error('No se pudo cargar el contorno de Paraguay.');
  return response.json();
 }).then(boundary=>L.geoJSON(boundary,{pane:'paraguay-outline',interactive:false,style:{color:'#c4f5ee',weight:2.5,opacity:.92,fillColor:'#0b7866',fillOpacity:.035}}).addTo(map))
   .catch(()=>toast('No se pudo cargar el contorno de Paraguay.'));
 destinationLayer=L.layerGroup().addTo(map);
 destinationSignature=null;
};

function renderDestinations(data){
 if(!destinationLayer||!data?.plants)return;
 const br=data.plants.find(p=>p.code==='ITAIPU');
 const ar=data.plants.find(p=>p.code==='YACYRETA');
 const ac=data.plants.find(p=>p.code==='ACARAY');
 const signature=JSON.stringify([br?.lat,br?.lon,ac?.lat,ac?.lon,br?.to_br_mw,br?.supply_br?.measured_at,br?.supply_br?.stale,ar?.to_ar_monthly?.value,ar?.to_ar_monthly?.period_start]);
 if(signature===destinationSignature)return;
 destinationLayer.clearLayers();
 // Las salidas son aproximadas e ilustrativas, no trazados eléctricos.
 const destinations={brazil:{border:[-25.4209,-54.5950],target:[-24.35,-51.75]},argentina:{border:[-27.4878,-56.7245],target:[-29.55,-57.85]}};
 const path=(from,to,color,count,arrow=false)=>{
  if(!from||!to)return;
  if(arrow){
   L.polyline([from,to],{color,weight:7,opacity:.18,interactive:false,className:'destination-flow-glow'}).addTo(destinationLayer);
   L.polyline([from,to],{color,weight:2,opacity:.9,interactive:false,className:'destination-flow-current'}).addTo(destinationLayer);
  }
  for(let i=0;i<count;i++){
   const t=i/(count+1), lat=from[0]+(to[0]-from[0])*t, lng=from[1]+(to[1]-from[1])*t;
   const size=arrow?10:12;
   L.marker([lat,lng],{interactive:false,keyboard:false,icon:L.divIcon({className:`bolt-path${arrow?' destination-route-bolt':''}`,iconSize:[size,size],iconAnchor:[size/2,size/2],html:`<span style="--bolt-color:${color};--bolt-delay:${i*.16}s">ϟ</span>`})}).addTo(destinationLayer);
  }
  if(arrow){
   const t=.83, location=[from[0]+(to[0]-from[0])*t,from[1]+(to[1]-from[1])*t];
   const a=map.latLngToLayerPoint(from), b=map.latLngToLayerPoint(to), angle=Math.atan2(b.y-a.y,b.x-a.x)*180/Math.PI;
   L.marker(location,{interactive:false,keyboard:false,icon:L.divIcon({className:'destination-arrow',iconSize:[12,12],iconAnchor:[6,6],html:`<span style="--arrow-color:${color};transform:rotate(${angle}deg)">➤</span>`})}).addTo(destinationLayer);
  }
 };
 const point=p=>p?.lat!=null&&p?.lon!=null&&Number.isFinite(Number(p.lat))&&Number.isFinite(Number(p.lon))?[Number(p.lat),Number(p.lon)]:null;
 path(destinations.brazil.border,destinations.brazil.target,'#ffd54a',6,true);
 path(destinations.argentina.border,destinations.argentina.target,'#ffd54a',6,true);
 path(point(br),point(ac),'#8fdfff',3);
 const add=(coordinates,country,value,unit,description,detailKey)=>{
  const valueText=value==null?'Sin lectura verificada':`${num(value)} ${unit}`;
  const label=`${country} · ${valueText} · ${description}`;
  const marker=L.marker(coordinates,{title:label,keyboard:true,zIndexOffset:200,icon:L.divIcon({className:`destination-marker${detailKey==='argentina'?' destination-marker-below':''}`,iconSize:[20,20],iconAnchor:[10,10],html:`<span class="destination-bolt" aria-hidden="true">⚡</span><span class="destination-caption"><b>${esc(country)}</b><strong>${esc(valueText)}</strong><small>${esc(description)}</small></span>`})});
  marker.bindTooltip(esc(label));
  marker.on('click',()=>document.querySelector(`[data-detail="${detailKey}"]`)?.click());
  destinationLayer.addLayer(marker);
 };
 const brNote=br?.supply_br?.measured_at?'Medición '+dateText(br.supply_br.measured_at)+(br.supply_br.stale?' · atrasada':''):'Hora de medición no publicada';
 add(destinations.brazil.target,'Brasil',br?.to_br_mw,'MW',brNote,'brazil');
 const month=ar?.to_ar_monthly;
 add(destinations.argentina.target,'Argentina',month?.value,'MWh',month?.period_start?'Mensual '+month.period_start.slice(0,7):'Dato mensual no disponible','argentina');
 destinationSignature=signature;
}

const updateWithDestinations=updateLive;
updateLive=function(data){
 updateWithDestinations(data);
 renderDestinations(data);
};
