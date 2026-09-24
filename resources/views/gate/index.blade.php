<!doctype html>
<html lang="{{ $lang }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>{{ $site->code }} · 출퇴근</title>
<link rel="manifest" href="{{ route('gate.manifest', $site) }}">
<link rel="apple-touch-icon" href="{{ asset('images/attendance-apple-touch.png') }}">
<meta name="theme-color" content="#FEE500">
<meta name="apple-mobile-web-app-capable" content="yes">
<style>
*{box-sizing:border-box}body{margin:0;background:#f4f6f8;color:#19242e;font-family:system-ui,sans-serif;padding:20px}main{max-width:460px;margin:20px auto;background:white;border-radius:20px;padding:26px}small,p{color:#617184;line-height:1.6}h1{font-size:27px;margin:18px 0 8px}h2{font-size:24px}label{display:block;margin:18px 0 6px}input,select,button{font:inherit;border-radius:12px;padding:15px;border:1px solid #cbd5df}input{width:100%}button{cursor:pointer;width:100%;margin-top:16px;background:#fff;font-weight:700}button.primary{background:#fee500;border:0;min-height:62px;font-size:20px}button:disabled{opacity:.5}select{padding:8px}header{display:flex;justify-content:space-between;align-items:center;gap:8px}section[hidden]{display:none}.note{background:#eff5fa;border-radius:10px;padding:12px;font-size:14px}.error{color:#a62128;white-space:pre-line}.success{color:#147243;font-weight:700}#who{text-align:center;padding:22px 0}#notice{white-space:pre-line}a{color:#0877bd}
</style>
</head>
<body><main>
<header>@if(\App\Support\Org::hasLogo())<img src="{{ route('org.logo', ['v' => \App\Support\Org::logoVersion()]) }}" alt="{{ \App\Support\Org::name() }}" style="max-width:100px;max-height:35px">@endif<strong>{{ \App\Support\Org::name() }}</strong>{{-- 고른 언어는 서버가 기억한다 — 이 화면에서 고른 말로 다음 화면도 열린다. --}}
@include('partials.lang-switch')</header>
<h1 data-t="title">현장 출퇴근</h1><p>{{ $site->code }} · {{ $site->name }}</p>
@if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
<p id="notice" role="status" aria-live="polite"></p>
<section id="entry">
<p class="note" data-t="intro"></p>
{{-- 뒷 4자리 하나면 끝난다. 네 자리가 차는 순간 스스로 찾는다 — 확인 단추를 하나 더
     누르게 하지 않는다. 찾은 사람이 한 명이면 이름을 눌러 바로 출퇴근 화면으로 간다. --}}
<label for="last4" data-t="last4"></label>
<input id="last4" type="tel" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" autocomplete="off">
<div id="matches"></div>
<button id="new-worker" data-t="newWorker"></button>
<p class="note" data-t="forgot"></p>
</section>
<section id="register" hidden>
<h2 data-t="newWorker"></h2><p data-t="registerHint"></p>
<form method="POST" action="{{ route('worker-join.store', $site) }}" id="register-form">@csrf
<input type="hidden" name="preferred_language" id="registration-language" value="{{ $lang }}">
{{-- 이 휴대폰이 이미 다른 사람을 등록했는지 알린다 — 반장 폰이 마지막 팀원의
     출퇴근 열쇠가 되지 않도록, 그 판단에 쓸 재료를 서버에 넘긴다. --}}
<input type="hidden" name="device_owner" value="">
<label for="full-name" data-t="name"></label><input id="full-name" name="full_name" value="{{ old('full_name') }}" autocomplete="name" required maxlength="120">
<label for="new-phone" data-t="phone"></label><input id="new-phone" name="phone" value="{{ old('phone') }}" type="tel" autocomplete="tel" required maxlength="40">
<button class="primary" data-t="register"></button>
</form><button id="back" data-t="back"></button>
</section>
<section id="attendance" hidden><div id="who"><small data-t="recognized"></small><h2 id="worker-name"></h2><p id="status"></p></div>
<button id="punch" class="primary"></button><button id="switch-worker" data-t="switchWorker"></button>
<p class="note" data-t="dailyHint"></p></section>
</main>
{{-- 이 휴대폰을 누구의 것으로 볼지는 등록 화면 둘이 같이 쓰는 한 파일이 정한다. --}}
<script src="{{ asset('js/worker-device-remember.js') }}?v={{ filemtime(public_path('js/worker-device-remember.js')) }}"></script>
<script>
(() => {
 const copy = {
 ko:{title:'현장 출퇴근',intro:'등록한 전화번호 뒷 4자리를 넣고 본인 이름을 누르세요. 다음부터는 이 QR만 찍으면 바로 출근·퇴근 버튼이 뜹니다.',last4:'전화번호 뒷 4자리',noMatch:'찾지 못했습니다. 번호를 다시 확인하거나 «처음 온 작업자 등록» 을 눌러 주세요.',newWorker:'처음 온 작업자 등록',forgot:'번호가 바뀌었거나 이름이 안 보이면 인사담당자에게 말씀해 주세요.',registerHint:'이름과 전화번호만 적으면 이 휴대폰이 연결되어 바로 출근을 찍을 수 있습니다. 서류는 나중에 작성합니다.',name:'이름',register:'등록하고 출근하기',back:'돌아가기',recognized:'이 휴대폰의 작업자',switchWorker:'내가 아닙니다 · 연결 해제',dailyHint:'다음에도 같은 QR을 사용하세요. 출근·퇴근은 버튼을 눌러야 기록됩니다.',clock_in:'출근하기',clock_out:'퇴근하기',none:'오늘 기록 없음',last:'마지막 기록',done:'기록 완료',pending:'기록 저장됨 · 관리자 확인 필요',duplicate:'방금 기록되었습니다. 중복 입력하지 않았습니다.',busy:'처리 중…',network:'연결에 실패했습니다. 다시 시도하세요.'},
 en:{title:'Site attendance',intro:'Enter the last 4 digits of your registered phone and tap your name. Next time, just scan this QR — the clock in/out button is right there.',last4:'Last 4 digits of your phone',noMatch:'Not found. Check the digits, or tap "Register a new worker".',newWorker:'Register a new worker',forgot:'Number changed, or your name is missing? Tell HR.',registerHint:'Enter your name and phone. This phone is linked and you can clock in right away; paperwork comes later.',name:'Name',register:'Register and clock in',back:'Back',recognized:'Worker on this phone',switchWorker:'Not me · Disconnect',dailyHint:'Use this same QR next time. Tap the button to record attendance.',clock_in:'Clock in',clock_out:'Clock out',none:'No record today',last:'Last record',done:'Recorded',pending:'Saved · Administrator review needed',duplicate:'Already recorded. No duplicate added.',busy:'Working…',network:'Connection failed. Please try again.'},
 es:{title:'Asistencia en obra',intro:'Escriba los últimos 4 dígitos de su teléfono registrado y pulse su nombre. La próxima vez, solo escanee este QR: el botón de entrada/salida aparece directo.',last4:'Últimos 4 dígitos de su teléfono',noMatch:'No encontrado. Revise los dígitos o pulse "Registrar trabajador nuevo".',newWorker:'Registrar trabajador nuevo',forgot:'¿Cambió de número o no aparece su nombre? Avise a Recursos Humanos.',registerHint:'Escriba nombre y teléfono. Este teléfono queda vinculado y puede marcar entrada de inmediato; los documentos después.',name:'Nombre',register:'Registrarme y marcar entrada',back:'Volver',recognized:'Trabajador de este teléfono',switchWorker:'No soy yo · Desconectar',dailyHint:'Use el mismo QR la próxima vez. Pulse el botón para registrar asistencia.',clock_in:'Marcar entrada',clock_out:'Marcar salida',none:'Sin registros hoy',last:'Último registro',done:'Registrado',pending:'Guardado · Revisión necesaria',duplicate:'Ya registrado. No se agregó un duplicado.',busy:'Procesando…',network:'Error de conexión. Inténtelo otra vez.'}
 };
 const urls = {me: @json(route('gate.me',$site)), identify: @json(route('gate.identify',$site)), claim: @json(route('gate.claim',$site)), punch: @json(route('gate.punch',$site)), forget: @json(route('gate.forget',$site))};
 const el = id => document.getElementById(id);
 let lang = @json($lang), token = '', record = null, geo = {}, busy = false;
 try { token = localStorage.getItem('dasolWorkerDevice') || ''; } catch (_) {}
 const text = () => copy[lang];
 function paint(){ document.documentElement.lang=lang; el('registration-language').value=lang; document.querySelectorAll('[data-t]').forEach(e=>e.textContent=text()[e.dataset.t]); if(record){ el('worker-name').textContent=record.employee.name; el('status').textContent=record.lastEvent?text().last+' · '+(record.lastEvent==='clock_in'?text().clock_in:text().clock_out)+' '+(record.lastAt||''):text().none; el('punch').textContent=text()[record.next]; } }
 function show(id){ ['entry','register','attendance'].forEach(x=>el(x).hidden=x!==id); }
 function note(message,error=false){el('notice').textContent=message;el('notice').className=error?'error':'success';}
 function save(value){token=value;try{value?localStorage.setItem('dasolWorkerDevice',value):localStorage.removeItem('dasolWorkerDevice');}catch(_){} }
 async function post(kind,data){const r=await fetch(urls[kind],{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content},body:JSON.stringify(data)}); const d=await r.json();if(!r.ok)throw new Error(d.error||d.message||text().network);return d;}
 async function recognize(){const d=await post('me',{device_token:token}); if(!d.recognized){show('entry');return false;}record=d;paint();show('attendance');return true;}
 // 언어 단추는 공용 조각이 맡는다 — 서버에 알리고 화면을 다시 그린다(partials/lang-switch).
 el('new-worker').onclick=()=>{note('');show('register');}; el('back').onclick=()=>show('entry');
 // 이 휴대폰이 이미 다른 사람을 등록했다면 그 사실만 실어 보낸다 — 연결할지 말지는 서버가 정한다.
 el('register-form').addEventListener('submit',()=>window.markRegisteringPhone(el('register-form')));
 // 네 자리가 채워지면 스스로 찾는다. 찾은 사람의 이름을 누르면 그걸로 끝 —
 // 이 휴대폰이 기억되므로 다음부터는 이 단계도 없다.
 el('last4').addEventListener('input',async e=>{
   const v=e.target.value.replace(/\D/g,'').slice(0,4); if(v!==e.target.value)e.target.value=v;
   el('matches').innerHTML=''; note('');
   if(v.length!==4||busy)return;
   busy=true;note(text().busy);
   try{const d=await post('identify',{last4:v});const ws=(d&&d.workers)||[];
     if(!ws.length){note(text().noMatch,true);return;}
     note('');
     el('matches').innerHTML=ws.map(w=>'<button type="button" class="pick" data-id="'+w.id+'">'+
       (w.name||'')+(w.company?' <small>'+w.company+'</small>':'')+'</button>').join('');
     el('matches').querySelectorAll('.pick').forEach(btn=>btn.onclick=()=>pick(btn.dataset.id));
   }catch(err){note(err.message,true);}finally{busy=false;}
 });
 async function pick(id){if(busy)return;busy=true;note(text().busy);
   try{const d=await post('claim',{employee_id:Number(id)});
     document.querySelector('meta[name="csrf-token"]').content=d.csrf_token;
     save(d.device_token);el('last4').value='';el('matches').innerHTML='';
     await recognize();note('');
   }catch(err){note(err.message,true);}finally{busy=false;}}
 el('switch-worker').onclick=async()=>{if(busy)return;try{await post('forget',{device_token:token});save('');location.reload();}catch(err){note(err.message,true);}};
 el('punch').onclick=async()=>{if(busy)return;busy=true;el('punch').disabled=true;note(text().busy);try{const d=await post('punch',{device_token:token,...geo});if(!d.success)throw new Error(d.error||text().network);await recognize();note((d.ignored?text().duplicate:d.pending?text().pending:text().done)+' '+(d.date||'')+' '+(d.at||''));}catch(err){note(err.message,true);}finally{busy=false;el('punch').disabled=false;}};
 paint();if(token)recognize().catch(()=>note(text().network,true));
 if(navigator.geolocation)navigator.geolocation.getCurrentPosition(p=>{geo={lat:p.coords.latitude,lng:p.coords.longitude,accuracy:p.coords.accuracy};},()=>{},{timeout:8000,enableHighAccuracy:true});
})();
</script></body></html>
