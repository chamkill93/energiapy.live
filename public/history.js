'use strict';

async function initHistory(){
 const host=$('#history-dashboard');if(!host)return;
 const colors=['#55c7ff','#ffd469','#75e7ab','#dba0ff','#ff90a4','#8de4e4','#ffad6d','#adbaea'];
 const definitions=[
  {id:'consumo',category:'Paraguay',title:'Consumo eléctrico',subtitle:'Energía anual consumida en el sistema paraguayo',unit:'MWh',display:'GWh',grain:'year',series:[{metric:'PY_CONSUMPTION_ENERGY',asset:'PY_SIN',source:'ANDE',label:'Consumo'}]},
  {id:'consumo-mensual',category:'Paraguay',title:'Consumo eléctrico mensual',subtitle:'Consumo nacional publicado; los informes preliminares VMME corresponden al SIN sin exportación',unit:'MWh',display:'GWh',grain:'month',series:[{metric:'PY_CONSUMPTION_ENERGY',asset:'PY_SIN',label:'Consumo mensual'}]},
  {id:'consumo-acumulado',category:'Paraguay',title:'Consumo acumulado de enero a agosto',subtitle:'Energía nacional de los primeros ocho meses de cada año',unit:'MWh',display:'GWh',grain:'YTD',series:[{metric:'PY_CONSUMPTION_ENERGY',asset:'PY_SIN',source:'ANDE',label:'Enero a agosto'}]},
  {id:'demanda',category:'Paraguay',title:'Demanda máxima',subtitle:'Mayor demanda registrada cada año',unit:'MW',display:'MW',grain:'year',series:[{metric:'PY_MAX_DEMAND',asset:'PY_SIN',source:'ANDE',label:'Demanda máxima'}]},
  {id:'demanda-mensual',category:'Paraguay',title:'Demanda máxima mensual',subtitle:'Mayor demanda de potencia registrada en cada mes',unit:'MW',display:'MW',grain:'month',series:[{metric:'PY_MAX_DEMAND',asset:'PY_SIN',source:'ANDE',label:'Demanda máxima'}]},
  {id:'demanda-horaria-investigacion',category:'Paraguay',title:'Demanda media del sistema',subtitle:'Promedio mensual de registros horarios 2008–2022 · datos ANDE procesados por investigadores, con imputaciones',unit:'MW',display:'MW',grain:'month',defaultRange:'all',series:[{metric:'PY_RESEARCH_MEAN_HOURLY_DEMAND',asset:'PY_SIN',source:'UNA_DEMAND_RESEARCH',label:'Potencia media'}]},
  {id:'sectores',category:'Consumo',title:'Consumo facturado por sector',subtitle:'Energía facturada por ANDE según tipo de usuario',unit:'MWh',display:'GWh',grain:'year',series:[{metric:'ANDE_BILLED_RESIDENTIAL_ENERGY',asset:'PY_SIN',source:'ANDE',label:'Residencial'},{metric:'ANDE_BILLED_INDUSTRIAL_ENERGY',asset:'PY_SIN',source:'ANDE',label:'Industrial'},{metric:'ANDE_BILLED_GOV_ENERGY',asset:'PY_SIN',source:'ANDE',label:'Sector público'},{metric:'ANDE_BILLED_PUBLIC_LIGHT_ENERGY',asset:'PY_SIN',source:'ANDE',label:'Alumbrado público'},{metric:'ANDE_BILLED_OTHER_ENERGY',asset:'PY_SIN',source:'ANDE',label:'Otros usuarios'}]},
  {id:'regiones',category:'Consumo',title:'Consumo por sistema regional',subtitle:'Energía anual por región de la red ANDE',unit:'MWh',display:'GWh',grain:'year',series:[{metric:'PY_REGION_ENERGY',source:'ANDE',label:'Regiones',byAsset:true}]},
  {id:'clientes',category:'Consumo',title:'Energía facturada por categoría',subtitle:'Subtotal nacional facturado por ANDE, sin alumbrado público ni exportación',unit:'kWh',display:'GWh',scale:1000000,grain:'year',dataset:'energiapy_ande_customers_2025_v5',field:'billed_kwh',kind:'bars'},
  {id:'clientes-historico',category:'Consumo',title:'Clientes facturados por ANDE',subtitle:'Hasta 2016 al cierre del año; desde 2017 promedio anual · cambio de facturación en 2023',unit:'count',display:'clientes',grain:'year',defaultRange:'all',breakYears:[2017],series:[{metric:'ANDE_BILLED_CUSTOMERS_NATIONAL',asset:'PY_SIN',source:'ANDE',label:'Clientes facturados'}]},
  {id:'facturacion-habitante',category:'Consumo',title:'Electricidad facturada por habitante',subtitle:'Desde 2022 cambia la base poblacional: excluye Boquerón, Alto Paraguay, comunidades indígenas y viviendas colectivas',unit:'kWh/person/year',display:'kWh por habitante',grain:'year',defaultRange:'all',breakYears:[2022],series:[{metric:'ANDE_BILLED_KWH_PER_CAPITA',asset:'PY_SIN',source:'ANDE',label:'Energía por habitante'}]},
  {id:'consumo-habitante-bm',category:'Consumo',title:'Consumo eléctrico por habitante',subtitle:'Consumo anual por persona del Banco Mundial · Paraguay',unit:'kWh/person',display:'kWh por habitante',grain:'year',defaultRange:'all',series:[{metric:'PY_ELECTRICITY_CONSUMPTION_PER_CAPITA',asset:'PY',source:'WORLD_BANK',label:'Consumo por habitante'}]},
  {id:'acceso-electricidad',category:'Paraguay',title:'Acceso a electricidad',subtitle:'Porcentaje de población con acceso · estimaciones del Banco Mundial',unit:'percent',display:'%',grain:'year',defaultRange:'all',series:[{metric:'PY_ELECTRICITY_ACCESS',asset:'PY',source:'WORLD_BANK',label:'Total nacional'},{metric:'PY_ELECTRICITY_ACCESS_URBAN',asset:'PY',source:'WORLD_BANK',label:'Urbano'},{metric:'PY_ELECTRICITY_ACCESS_RURAL',asset:'PY',source:'WORLD_BANK',label:'Rural'}]},
  {id:'balance',category:'Paraguay',title:'Origen de la energía de ANDE',subtitle:'ANDE registra por separado los aportes de sus centrales, las compras a binacionales y las importaciones',unit:'MWh',display:'GWh',grain:'year',series:[{metric:'PY_GROSS_OWN_GENERATION',asset:'PY_SIN',source:'ANDE',label:'Centrales propias de ANDE'},{metric:'PY_PURCHASED_ENERGY',asset:'PY_SIN',source:'ANDE',label:'Compras a Itaipú y Yacyretá'},{metric:'PY_IMPORT_ENERGY',asset:'PY_SIN',source:'ANDE',label:'Importaciones'}]},
  {id:'aporte-centrales',category:'Generación',title:'Origen del consumo nacional en 2025',subtitle:'Abastecimiento por central publicado por ANDE · cifras en GWh enteros',unit:'MWh',display:'GWh',grain:'year',kind:'pie',fixedYear:2025,series:[{metric:'ENERGY_TO_PY',asset:'ITAIPU',source:'ANDE',label:'Itaipú'},{metric:'ENERGY_TO_PY',asset:'YACYRETA',source:'ANDE',label:'Yacyretá'},{metric:'ENERGY_TO_PY',asset:'ACARAY',source:'ANDE',label:'Acaray'}]},
  {id:'factor',category:'Paraguay',title:'Factor de carga',subtitle:'Relación anual entre demanda media y demanda máxima',unit:'percent',display:'%',grain:'year',series:[{metric:'PY_LOAD_FACTOR',asset:'PY_SIN',source:'ANDE',label:'Factor de carga'}]},
  {id:'exportacion',category:'Intercambios',title:'Exportación de energía de ANDE',subtitle:'Energía exportada por ANDE en cada año',unit:'MWh',display:'GWh',grain:'year',series:[{metric:'PY_ANDE_EXPORT_ENERGY',asset:'PY_SIN',source:'ANDE',label:'Exportación'}]},
  {id:'itaipu',category:'Generación',title:'Generación de Itaipú',subtitle:'Generación bruta anual de la central',unit:'MWh',display:'GWh',grain:'year',defaultRange:'all',series:[{metric:'ITAIPU_GROSS_GENERATION',asset:'ITAIPU',source:'ITAIPU',label:'Generación'}]},
  {id:'itaipu-horario-ons',category:'Generación',title:'Itaipú: potencia media mensual',subtitle:'Promedio de registros horarios ONS · meses con al menos 95% de horas nominales',unit:'MW',display:'MW',grain:'month',defaultRange:'all',series:[{metric:'ITAIPU_ONS_MONTHLY_MEAN_TOTAL',asset:'ITAIPU',source:'ONS_ITAIPU_HOURLY',label:'Generación total'},{metric:'ITAIPU_ONS_MONTHLY_MEAN_BR',asset:'ITAIPU',source:'ONS_ITAIPU_HOURLY',label:'Destinada a Brasil'}]},
  {id:'yacyreta',category:'Generación',title:'Yacyretá: generación y energía recibida',subtitle:'Energía mensual neta y entregas publicadas por EBY y CAMMESA',unit:'MWh',display:'GWh',grain:'month',series:[{metric:'YACYRETA_NET_ENERGY',asset:'YACYRETA',source:'EBY',label:'Generación neta'},{metric:'GENERATION_ENERGY',asset:'YACYRETA',source:'CAMMESA',label:'Generación neta'},{metric:'ENERGY_TO_ARGENTINA',asset:'YACYRETA',source:'EBY',label:'Recibida por Argentina'},{metric:'ENERGY_TO_AR',asset:'YACYRETA',source:'CAMMESA',label:'Recibida por Argentina'},{metric:'ENERGY_TO_PY',asset:'YACYRETA',source:'EBY',label:'Recibida por Paraguay'},{metric:'ENERGY_TO_PY',asset:'YACYRETA',source:'CAMMESA',label:'Recibida por Paraguay'}]},
  {id:'yacyreta-anual',category:'Generación',title:'Yacyretá: generación y entregas anuales',subtitle:'Energía neta y entregas a Argentina y Paraguay · años completos de CAMMESA',unit:'MWh',display:'GWh',grain:'year',defaultRange:'all',series:[{metric:'GENERATION_ENERGY',asset:'YACYRETA',source:'CAMMESA',label:'Generación neta'},{metric:'ENERGY_TO_AR',asset:'YACYRETA',source:'CAMMESA',label:'Argentina'},{metric:'ENERGY_TO_PY',asset:'YACYRETA',source:'CAMMESA',label:'Paraguay'}]},
  {id:'acaray',category:'Generación',title:'Acaray: energía para Paraguay',subtitle:'Aporte mensual de la central al sistema ANDE',unit:'MWh',display:'GWh',grain:'month',series:[{metric:'ENERGY_TO_PY',asset:'ACARAY',label:'Aporte a Paraguay'}]},
  {id:'itaipu-py',category:'Generación',title:'Itaipú: energía para Paraguay',subtitle:'Suministro mensual de Itaipú a ANDE',unit:'MWh',display:'GWh',grain:'month',series:[{metric:'ENERGY_TO_PY',asset:'ITAIPU',label:'Suministro a Paraguay'}]},
  {id:'itaipu-py-anual',category:'Generación',title:'Itaipú: suministro anual a Paraguay',subtitle:'Energía suministrada a ANDE durante cada año',unit:'MWh',display:'GWh',grain:'year',series:[{metric:'ENERGY_TO_PY',asset:'ITAIPU',source:'ITAIPU',label:'Suministro a Paraguay'}]},
  {id:'solar',category:'Generación',title:'Puerto Esperanza: energía solar',subtitle:'Aporte mensual de la central fotovoltaica a ANDE',unit:'MWh',display:'MWh',grain:'month',series:[{metric:'ENERGY_TO_PY',asset:'SOLAR_PTO_ESPERANZA',source:'ANDE',label:'Energía entregada'}]},
  {id:'perdidas',category:'Red eléctrica',title:'Pérdidas de distribución',subtitle:'Porcentaje anual de pérdidas técnicas y comerciales publicado por ANDE',unit:'percent',display:'%',grain:'year',series:[{metric:'DISTRIBUTION_LOSS_RATE',asset:'PY_GRID',source:'ANDE',label:'Pérdidas de distribución'}]},
  {id:'tarifas',category:'Tarifas',title:'Tarifas medias de electricidad',subtitle:'Tarifa anual media por energía sin impuestos, en guaraníes corrientes',unit:'Gs/kWh',display:'Gs/kWh',grain:'year',series:[{metric:'ANDE_TARIFF_RESIDENTIAL',asset:'PY_SIN',source:'ANDE',label:'Residencial'},{metric:'ANDE_TARIFF_INDUSTRIAL',asset:'PY_SIN',source:'ANDE',label:'Industrial'},{metric:'ANDE_TARIFF_NATIONAL_AVG',asset:'PY_SIN',source:'ANDE',label:'Media nacional'}]},
  {id:'transmision',category:'Infraestructura',title:'Red de transmisión',subtitle:'Longitud de líneas por nivel de tensión',unit:'km',display:'km',grain:'year',series:[{metric:'TX_LINE_500KV',asset:'PY_GRID',source:'ANDE',label:'500 kV'},{metric:'TX_LINE_220KV',asset:'PY_GRID',source:'ANDE',label:'220 kV'},{metric:'TX_LINE_66KV',asset:'PY_GRID',source:'ANDE',label:'66 kV'}]},
  {id:'distribucion',category:'Infraestructura',title:'Red de distribución de media tensión',subtitle:'Longitud anual de la red de distribución de ANDE',unit:'km',display:'km',grain:'year',series:[{metric:'DIST_MEDIUM_VOLTAGE_NETWORK',asset:'PY_GRID',source:'ANDE',label:'Media tensión'}]},
  {id:'transformadores-distribucion',category:'Infraestructura',title:'Transformadores de distribución',subtitle:'Capacidad instalada de los transformadores de distribución de ANDE',unit:'MVA',display:'MVA',grain:'year',series:[{metric:'DIST_TRANSFORMERS_CAPACITY',asset:'PY_GRID',source:'ANDE',label:'Capacidad'}]},
  {id:'capacidad',category:'Infraestructura',title:'Capacidad de transformación',subtitle:'Capacidad de transformación del sistema de transmisión',unit:'MVA',display:'MVA',grain:'year',series:[{metric:'TX_TRANSFORMATION_CAPACITY',asset:'PY_GRID',source:'ANDE',label:'Capacidad instalada'}]},
  {id:'regalias',category:'Economía',title:'Regalías de Itaipú',subtitle:'Pagos anuales al Tesoro paraguayo, registrados en régimen de caja',unit:'USD',display:'millones de USD',scale:1000000,grain:'year',series:[{metric:'ITAIPU_ROYALTIES_CASH',asset:'ITAIPU',source:'ITAIPU',label:'Regalías cobradas'}]}
 ];
 const rangeLabel={year:'Anual',month:'Mensual',YTD:'Enero–agosto'};
 const periodText=row=>row.period_start?calendarDate(row.period_start)+(row.period_end&&row.period_end.slice(0,10)!==row.period_start.slice(0,10)?' – '+calendarDate(row.period_end):''):row.measured_at?dateText(row.measured_at):'Sin fecha';
 const yearOf=row=>Number(String(row.period_start||row.measured_at||'').slice(0,4));
 const displayValue=(row,def)=>Number(row.value)/(def.scale||(def.unit==='MWh'&&def.display==='GWh'?1000:1));
 const pointValue=(row,def,decimals=2)=>(String(row.quality||'').includes('APPROXIMATE')?'≈ ':'')+num(displayValue(row,def),decimals);
 const htmlOption=(value,text)=>`<option value="${esc(value)}">${esc(text)}</option>`;
 const views=new Map();
 function card(def){
  const el=document.createElement('article');el.className='history-card';el.id='historico-'+def.id;
  el.innerHTML=`<div class="history-card-head"><div class="history-card-title"><span class="history-category">${esc(def.category)}</span><h2 data-title>${esc(def.title)}</h2><p class="history-subtitle">${esc(def.subtitle)}</p></div><label class="history-period">${def.kind==='pie'?'Año':'Años'} <select aria-label="Años de ${esc(def.title)}" data-range><option value="5">Últimos 5 años</option><option value="10">Últimos 10 años</option><option value="20">Últimos 20 años</option><option value="all">Todos</option></select></label></div><div class="history-chart" data-chart><div class="history-loading">Consultando información…</div></div><div class="history-legend" data-legend></div><p class="history-meta" data-meta></p>`;
  host.append(el);return {def,el,rows:[],range:def.defaultRange||'10',latestYear:null};
 }
 async function fetchSeries(series,def){
  const q=new URLSearchParams({metric:series.metric,grain:def.grain,unit:def.unit,page:'1'});if(series.asset)q.set('asset',series.asset);if(series.source)q.set('source',series.source);
  let page=1,out=[],total=0,perPage=500;
  do{q.set('page',String(page));const result=await api('history?'+q);total=result.meta.total;perPage=result.meta.per_page||500;out.push(...result.data);page++;}while((page-1)*perPage<total);
  return out.map(row=>({...row,seriesLabel:series.byAsset?labelOf(row.asset_code):series.label,seriesMetric:series.metric}));
 }
 async function fetchDataset(def){
  const categories={'Residential':'Residencial','Industrial':'Industrial','Other':'Otros usuarios','Government':'Sector público','Differential':'Diferencial','High Voltage':'Alta tensión','Very High Voltage':'Muy alta tensión','Special Intensive Consumers':'Consumo intensivo'};
  const result=await api('history?dataset='+encodeURIComponent(def.dataset));
  return result.data.filter(row=>categories[row.category]&&/^\d{4}$/.test(String(row.year))&&row[def.field]!==''&&Number.isFinite(Number(row[def.field]))).map(row=>({period_start:row.year+'-01-01',period_end:row.year+'-12-31',value:Number(row[def.field]),unit:def.unit,source:'ANDE',source_url:row.source_url,seriesLabel:categories[row.category]}));
 }
 function filtered(view){
  if(view.def.kind==='pie')return view.rows.filter(row=>yearOf(row)===Number(view.range));
  const end=view.latestYear,start=view.range==='all'?0:end-Number(view.range)+1;
  return view.rows.filter(row=>{const y=yearOf(row);return y>=start&&y<=end;});
 }
 function renderChart(view,rows){
  const el=view.el.querySelector('[data-chart]'),legend=view.el.querySelector('[data-legend]');legend.innerHTML='';
  const groups=new Map();for(const row of rows){if(!groups.has(row.seriesLabel))groups.set(row.seriesLabel,[]);groups.get(row.seriesLabel).push(row);}
  if(!rows.length){el.innerHTML='<div class="history-empty">No hay datos para estos años. Elegí otro período.</div>';return;}
  if(view.def.kind==='pie'){
   const total=rows.reduce((sum,row)=>sum+displayValue(row,view.def),0);if(total<=0||groups.size!==view.def.series.length||rows.length!==view.def.series.length){el.innerHTML='<div class="history-empty">No está disponible la composición completa para este año.</div>';return;}
   const cx=150,cy=130,r=90,circ=2*Math.PI*r;let offset=0;
   let svg=`<svg viewBox="0 0 300 260" role="img" aria-label="${esc(view.def.title)}"><title>${esc(view.def.title)}</title>`;
   rows.forEach((row,i)=>{const value=displayValue(row,view.def),fraction=value/total,color=colors[i%colors.length];svg+=`<circle cx="${cx}" cy="${cy}" r="${r}" fill="none" stroke="${color}" stroke-width="55" stroke-dasharray="${fraction*circ} ${circ}" stroke-dashoffset="${-offset*circ}" transform="rotate(-90 ${cx} ${cy})"><title>${esc(row.seriesLabel)}: ${esc(pointValue(row,view.def,0))} ${esc(view.def.display)} · ${esc(num(fraction*100,1))}%</title></circle>`;offset+=fraction;const item=document.createElement('span');item.style.setProperty('--series-color',color);item.innerHTML=`<i></i>${esc(row.seriesLabel)} · ${esc(num(fraction*100,1))}%`;legend.append(item);});
   svg+=`<text x="${cx}" y="${cy-2}" text-anchor="middle" fill="currentColor" font-size="21">${esc(num(total,0))}</text><text x="${cx}" y="${cy+20}" text-anchor="middle" fill="currentColor" font-size="12">${esc(view.def.display)}</text></svg>`;el.innerHTML=svg;return;
  }
  if(view.def.kind==='bars'){
   const sorted=[...rows].sort((a,b)=>displayValue(b,view.def)-displayValue(a,view.def));const max=Math.max(...sorted.map(r=>displayValue(r,view.def)),1),height=Math.max(240,sorted.length*32+32),left=175,width=Math.max(420,Math.min(1000,el.clientWidth||1000)),space=width-left-75;
   let svg=`<svg viewBox="0 0 ${width} ${height}" role="img" aria-label="${esc(view.def.title)} en ${esc(view.def.display)}"><title>${esc(view.def.title)} · ${esc(view.def.display)}</title>`;
   sorted.forEach((row,i)=>{const value=displayValue(row,view.def),y=12+i*32,w=value/max*space;svg+=`<text x="${left-12}" y="${y+15}" text-anchor="end" fill="currentColor" font-size="11">${esc(row.seriesLabel)}</text><rect x="${left}" y="${y}" width="${w}" height="20" rx="3" fill="${colors[i%colors.length]}"><title>${esc(row.seriesLabel)}: ${esc(num(value,2))} ${esc(view.def.display)}</title></rect><text x="${left+w+8}" y="${y+15}" fill="currentColor" font-size="11">${esc(num(value,1))}</text>`;});
   el.innerHTML=svg+'</svg>';return;
  }
  const width=Math.max(380,Math.min(1000,el.clientWidth||1000)),height=260,left=82,right=25,top=18,bottom=38,all=rows.map(r=>displayValue(r,view.def)),high=Math.max(0,...all),low=Math.min(0,...all),span=high-low||1;
  const time=r=>Date.parse(String(r.period_start).slice(0,10)+'T12:00:00Z'),times=rows.map(time).filter(Number.isFinite),minT=Math.min(...times),maxT=Math.max(...times),x=t=>left+(t-minT)/(maxT-minT||1)*(width-left-right),y=v=>height-bottom-(v-low)/span*(height-top-bottom);
  let svg=`<svg viewBox="0 0 ${width} ${height}" role="img" aria-label="${esc(view.def.title)} en ${esc(view.def.display)}"><title>${esc(view.def.title)} · ${esc(view.def.display)}</title>`;
  for(let i=0;i<5;i++){const value=low+i*span/4;svg+=`<line x1="${left}" x2="${width-right}" y1="${y(value)}" y2="${y(value)}" stroke="currentColor" opacity=".16"/><text x="${left-10}" y="${y(value)+4}" text-anchor="end" fill="currentColor" font-size="11">${esc(num(value,1))}</text>`;}
  let index=0;for(const [name,list] of groups){list.sort((a,b)=>time(a)-time(b));const color=colors[index++%colors.length];let segment=[];const flush=()=>{if(segment.length>1)svg+=`<polyline points="${segment.map(p=>x(time(p))+','+y(displayValue(p,view.def))).join(' ')}" fill="none" stroke="${color}" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>`;segment=[];};
   for(const row of list){if(segment.length){const prev=segment[segment.length-1], gap=time(row)-time(prev),maxGap=['year','YTD'].includes(view.def.grain)?370*86400000:40*86400000;if(gap<=0||gap>maxGap||(view.def.breakYears||[]).some(boundary=>yearOf(prev)<boundary&&yearOf(row)>=boundary))flush();}segment.push(row);}flush();
   for(const row of list){const source=/^https?:\/\//i.test(row.source_url||'')?row.source_url:null;svg+=`${source?`<a href="${esc(source)}" target="_blank" rel="noopener noreferrer">`:''}<circle cx="${x(time(row))}" cy="${y(displayValue(row,view.def))}" r="3" fill="${color}"><title>${esc(name)} · ${esc(periodText(row))}: ${esc(pointValue(row,view.def))} ${esc(view.def.display)} · ${esc(sourceLabel(row.source))}</title></circle>${source?'</a>':''}`;}
   const item=document.createElement('span');item.style.setProperty('--series-color',color);item.innerHTML=`<i></i>${esc(name)}`;legend.append(item);
  }
  const first=new Date(minT).getUTCFullYear(),last=new Date(maxT).getUTCFullYear(),step=Math.max(1,Math.ceil((last-first)/6));for(let year=first;year<=last;year+=step){const t=Date.UTC(year,0,1,12);svg+=`<text x="${Math.max(left,Math.min(width-right,x(t)))}" y="${height-9}" fill="currentColor" text-anchor="middle" font-size="10">${year}</text>`;}
  svg+='</svg>';el.innerHTML=svg;
 }
 function draw(view){
  const rows=filtered(view);renderChart(view,rows);
  const latest=rows.reduce((a,b)=>!a||String(b.period_end||b.period_start)>String(a.period_end||a.period_start)?b:a,null);
  view.el.querySelector('[data-meta]').textContent=latest?`${rangeLabel[view.def.grain]} · ${view.def.display}`:'';
  view.el.querySelector('[data-title]').innerHTML=latest&&/^https?:\/\//i.test(latest.source_url||'')?`<a href="${esc(latest.source_url)}" target="_blank" rel="noopener noreferrer" aria-label="${esc(view.def.title)}: abrir fuente">${esc(view.def.title)} <span aria-hidden="true">↗</span></a>`:esc(view.def.title);
 }
 function prepareControls(view){
  const years=[...new Set(view.rows.map(yearOf).filter(Number.isFinite))].sort((a,b)=>a-b);view.latestYear=years.at(-1);const select=view.el.querySelector('[data-range]');
  if(view.def.kind==='pie'){select.innerHTML=years.map(y=>htmlOption(y,y)).join('');view.range=String(view.latestYear);select.disabled=years.length===1;}
  select.value=view.range;select.onchange=e=>{view.range=e.target.value;draw(view);};
 }
 host.innerHTML='';
 for(const def of definitions){const view=card(def);views.set(def.id,view);}
 await Promise.all([...views.values()].map(async view=>{
  try{const chunks=view.def.dataset?[await fetchDataset(view.def)]:await Promise.all(view.def.series.map(series=>fetchSeries(series,view.def)));view.rows=chunks.flat().filter(row=>row.period_start&&Number.isFinite(yearOf(row))&&(!view.def.fixedYear||yearOf(row)===view.def.fixedYear)&&row.value!==null&&Number.isFinite(Number(row.value)));if(!view.rows.length){view.el.querySelector('[data-chart]').innerHTML='<div class="history-empty">No hay una serie publicada para este indicador.</div>';view.el.querySelector('[data-range]').disabled=true;return;}prepareControls(view);draw(view);}
  catch(error){view.el.querySelector('[data-chart]').innerHTML='<div class="history-empty history-error">No se pudo cargar este gráfico. Recargá la página para volver a consultar.</div>';view.el.querySelector('[data-range]').disabled=true;}
 }));
 const summary=$('#history-summary');
 if(summary){
  const entries=[{id:'consumo-mensual',label:'Consumo nacional'},{id:'itaipu-py',label:'Itaipú → Paraguay'},{id:'yacyreta',label:'Yacyretá → Argentina',series:'Recibida por Argentina'},{id:'perdidas',label:'Pérdidas de distribución'}];
  summary.innerHTML=entries.map(entry=>{const view=views.get(entry.id),rows=view?.rows.filter(row=>!entry.series||row.seriesLabel===entry.series)||[];const latest=rows.reduce((a,b)=>!a||String(b.period_end||b.period_start)>String(a.period_end||a.period_start)?b:a,null);if(!latest)return '';return `<article class="history-summary-card"><h2>${esc(entry.label)}</h2><strong>${esc(pointValue(latest,view.def))} <small>${esc(view.def.display)}</small></strong><p>${esc(periodText(latest))}<br>${link(latest.source_url,sourceLabel(latest.source)+' ↗')}</p></article>`;}).join('');
 }
}
