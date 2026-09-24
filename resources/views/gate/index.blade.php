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
<header>@if(\App\Support\Org::hasLogo())<img src="{{ route('org.logo', ['v' => \App\Support\Org::logoVersion()]) }}" alt="{{ \App\Support\Org::name() }}" style="max-width:100px;max-height:35px">@endif<strong>{{ \App\Support\Org::name() }}</strong><select id="language" aria-label="Language">@foreach($langOptions as $code => $label)<option value="{{ $code }}" @selected($lang === $code)>{{ $label }}</option>@endforeach</select></header>
<h1 data-t="title">현장 출퇴근</h1><p>{{ $site->code }} · {{ $site->name }}</p>
@if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
<p id="notice" role="status" aria-live="polite"></p>
<section id="entry">
<p class="note" data-t="intro"></p>
<form id="login-form">
<label for="phone" data-t="phone"></label><input id="phone" type="tel" autocomplete="tel" required maxlength="40">
<label for="pin" data-t="pin"></label><input id="pin" type="password" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" autocomplete="current-password" required>
<button class="primary" data-t="connect"></button>
</form>
<button id="new-worker" data-t="newWorker"></button>
<p data-t="forgot"></p>
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
 ko:{title:'현장 출퇴근',intro:'처음 한 번만 연결하세요. 다음부터는 이 QR을 찍고 출근·퇴근 버튼만 누르면 됩니다.',phone:'전화번호 (미국 10자리 / 그 외 국가번호 포함)',pin:'개인 PIN 4자리',connect:'내 출퇴근 열기',newWorker:'처음 온 작업자 등록',forgot:'PIN을 잊었거나 기존 직원인데 PIN이 없으면 인사담당자에게 연결 링크를 요청하세요.',registerHint:'이름과 전화번호만 적으면 이 휴대폰이 연결되어 바로 출근을 찍을 수 있습니다. 서류는 나중에 작성합니다.',name:'이름',register:'등록하고 출근하기',back:'돌아가기',recognized:'이 휴대폰의 작업자',switchWorker:'내가 아닙니다 · 연결 해제',dailyHint:'다음에도 같은 QR을 사용하세요. 출근·퇴근은 버튼을 눌러야 기록됩니다.',clock_in:'출근하기',clock_out:'퇴근하기',none:'오늘 기록 없음',last:'마지막 기록',done:'기록 완료',pending:'기록 저장됨 · 관리자 확인 필요',duplicate:'방금 기록되었습니다. 중복 입력하지 않았습니다.',busy:'처리 중…',network:'연결에 실패했습니다. 다시 시도하세요.'},
 en:{title:'Site attendance',intro:'Connect once. Next time, scan this same QR and tap Clock in or Clock out.',phone:'Phone (10 US digits / include country code otherwise)',pin:'Your 4-digit PIN',connect:'Open my attendance',newWorker:'Register a new worker',forgot:'Forgot your PIN, or already registered without a PIN? Ask HR for a setup link.',registerHint:'Enter your name and phone. This phone is linked and you can clock in right away; paperwork comes later.',name:'Name',register:'Register and clock in',back:'Back',recognized:'Worker on this phone',switchWorker:'Not me · Disconnect',dailyHint:'Use this same QR next time. Tap the button to record attendance.',clock_in:'Clock in',clock_out:'Clock out',none:'No record today',last:'Last record',done:'Recorded',pending:'Saved · Administrator review needed',duplicate:'Already recorded. No duplicate added.',busy:'Working…',network:'Connection failed. Please try again.'},
 es:{title:'Asistencia en obra',intro:'Conecte una vez. Después, escanee este mismo QR y marque entrada o salida.',phone:'Teléfono (10 dígitos EE. UU. / otro país con código)',pin:'Su PIN de 4 dígitos',connect:'Abrir mi asistencia',newWorker:'Registrar trabajador nuevo',forgot:'¿Olvidó su PIN o aún no tiene uno? Pida a Recursos Humanos un enlace.',registerHint:'Escriba nombre y teléfono. Este teléfono queda vinculado y puede marcar entrada de inmediato; los documentos después.',name:'Nombre',register:'Registrarme y marcar entrada',back:'Volver',recognized:'Trabajador de este teléfono',switchWorker:'No soy yo · Desconectar',dailyHint:'Use el mismo QR la próxima vez. Pulse el botón para registrar asistencia.',clock_in:'Marcar entrada',clock_out:'Marcar salida',none:'Sin registros hoy',last:'Último registro',done:'Registrado',pending:'Guardado · Revisión necesaria',duplicate:'Ya registrado. No se agregó un duplicado.',busy:'Procesando…',network:'Error de conexión. Inténtelo otra vez.'}
 };
 const urls = {me: @json(route('gate.me',$site)), login: @json(route('gate.login',$site)), punch: @json(route('gate.punch',$site)), forget: @json(route('gate.forget',$site))};
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
 el('language').onchange=()=>{lang=el('language').value;paint();};
 el('new-worker').onclick=()=>{note('');show('register');}; el('back').onclick=()=>show('entry');
 // 이 휴대폰이 이미 다른 사람을 등록했다면 그 사실만 실어 보낸다 — 연결할지 말지는 서버가 정한다.
 el('register-form').addEventListener('submit',()=>window.markRegisteringPhone(el('register-form')));
 el('login-form').onsubmit=async e=>{e.preventDefault();if(busy)return;busy=true;const b=e.target.querySelector('button');b.disabled=true;note(text().busy);try{const d=await post('login',{phone:el('phone').value,pin:el('pin').value});document.querySelector('meta[name="csrf-token"]').content=d.csrf_token;save(d.device_token);el('pin').value='';await recognize();note('');}catch(err){note(err.message,true);}finally{busy=false;b.disabled=false;}};
 el('switch-worker').onclick=async()=>{if(busy)return;try{await post('forget',{device_token:token});save('');location.reload();}catch(err){note(err.message,true);}};
 el('punch').onclick=async()=>{if(busy)return;busy=true;el('punch').disabled=true;note(text().busy);try{const d=await post('punch',{device_token:token,...geo});if(!d.success)throw new Error(d.error||text().network);await recognize();note((d.ignored?text().duplicate:d.pending?text().pending:text().done)+' '+(d.date||'')+' '+(d.at||''));}catch(err){note(err.message,true);}finally{busy=false;el('punch').disabled=false;}};
 paint();if(token)recognize().catch(()=>note(text().network,true));
 if(navigator.geolocation)navigator.geolocation.getCurrentPosition(p=>{geo={lat:p.coords.latitude,lng:p.coords.longitude,accuracy:p.coords.accuracy};},()=>{},{timeout:8000,enableHighAccuracy:true});
})();
</script></body></html>
