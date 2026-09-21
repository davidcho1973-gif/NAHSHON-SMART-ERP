<!DOCTYPE html>
<html lang="{{ $lang }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>직원 추가정보 · {{ $employee->name }}</title>
    <style>
        :root{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Malgun Gothic",Arial,sans-serif;background:#f3f6fa;color:#10233f}*{box-sizing:border-box}body{margin:0;padding:24px 16px;display:flex;justify-content:center}.card{width:min(100%,520px);background:#fff;border:1px solid #dbe4ef;border-radius:20px;padding:26px 22px;box-shadow:0 14px 36px rgba(16,35,63,.08)}h1{margin:0 0 8px;font-size:1.55rem}.sub{color:#607188;line-height:1.55;margin:0 0 20px}label{display:block;margin:15px 0 6px;font-weight:800;color:#334a67;font-size:.88rem}input{width:100%;min-height:52px;padding:13px;border:1px solid #cbd7e5;border-radius:11px;background:#f8fafc;font:inherit}.btn{display:block;width:100%;margin-top:22px;padding:16px;border:0;border-radius:12px;background:#0877bd;color:#fff;font:inherit;font-weight:900;text-align:center;text-decoration:none}.secondary{background:#0f766e}.error{padding:12px;background:#fff0f0;color:#a12828;border-radius:10px}.ok{padding:14px;background:#e9f9ef;color:#166534;border-radius:12px;font-weight:800}.note{font-size:.78rem;color:#718198;line-height:1.5}
    </style>
</head>
<body><main class="card">
    <h1>{{ $lang === 'es' ? 'Complete su información' : ($lang === 'en' ? 'Complete your information' : '추가정보 작성') }}</h1>
    <p class="sub"><strong>{{ $employee->name }}</strong><br>{{ $lang === 'es' ? 'Este enlace privado corresponde únicamente a usted.' : ($lang === 'en' ? 'This private link belongs only to you.' : '이 개인 보안 링크는 본인 정보에만 연결됩니다.') }}</p>
    @if($errors->any())<div class="error"><ul>@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif
    @if($saved)
        <div class="ok">{{ $lang === 'es' ? 'Información guardada.' : ($lang === 'en' ? 'Information saved.' : '추가정보를 저장했습니다.') }}</div>
        <a class="btn secondary" href="{{ $w9Url }}">W-9 {{ $lang === 'es' ? 'completar' : ($lang === 'en' ? 'Continue' : '이어서 작성') }} →</a>
        <p class="note">{{ $lang === 'ko' ? 'W-9에는 납세자번호가 포함되므로 공용 휴대폰에서 작성하지 마세요.' : 'Do not complete W-9 on a shared phone.' }}</p>
    @else
        <form method="POST" action="{{ url()->full() }}">@csrf
            <label>Email ({{ $lang === 'ko' ? '선택' : 'optional' }})</label><input name="email" type="email" autocomplete="email" value="{{ old('email', $registration->email ?: $employee->email) }}">
            <label>{{ $lang === 'es' ? 'Dirección' : ($lang === 'en' ? 'Address' : '주소') }}</label><input name="address" autocomplete="street-address" required value="{{ old('address', $registration->address) }}">
            <label>{{ $lang === 'es' ? 'Contacto de emergencia' : ($lang === 'en' ? 'Emergency contact' : '비상연락처 이름') }}</label><input name="emergency_contact_name" required value="{{ old('emergency_contact_name', $registration->emergency_contact_name) }}">
            <label>{{ $lang === 'es' ? 'Teléfono de emergencia' : ($lang === 'en' ? 'Emergency phone' : '비상연락처 전화번호') }}</label><input name="emergency_contact_phone" inputmode="tel" required value="{{ old('emergency_contact_phone', $registration->emergency_contact_phone) }}">
            <button class="btn" type="submit">{{ $lang === 'es' ? 'Guardar y continuar' : ($lang === 'en' ? 'Save and continue' : '저장하고 W-9 작성하기') }}</button>
        </form>
    @endif
</main></body></html>
