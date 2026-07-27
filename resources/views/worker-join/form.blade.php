<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>작업자 간편 등록 — {{ $site->code }} {{ $site->name }}</title>
    <style>
        :root { color-scheme: light; font-family: 'Malgun Gothic', Arial, Helvetica, sans-serif; background: #f1f5f9; color: #0f172a; }
        body { margin: 0; padding: 20px; display: flex; justify-content: center; }
        .card { width: min(100%, 460px); background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; box-shadow: 0 12px 40px rgba(15,23,42,.08); padding: 26px 22px; box-sizing: border-box; }
        .brand { font-size: .75rem; letter-spacing: .1em; text-transform: uppercase; color: #4f46e5; font-weight: 800; margin: 0 0 4px; }
        h1 { margin: 0 0 4px; font-size: 1.5rem; }
        .site { color: #475569; font-size: .95rem; margin: 0 0 18px; }
        label { display: block; font-size: .85rem; font-weight: 700; color: #334155; margin: 14px 0 6px; }
        input, select { width: 100%; box-sizing: border-box; padding: 13px 14px; font-size: 1rem; border: 1px solid #cbd5e1; border-radius: 10px; background: #fff; color: #0f172a; font-family: inherit; }
        input:focus, select:focus { outline: none; border-color: #4f46e5; box-shadow: 0 0 0 3px rgba(79,70,229,.15); }
        .req { color: #ef4444; }
        button { width: 100%; margin-top: 22px; padding: 15px; font-size: 1.05rem; font-weight: 800; color: #fff; background: #4f46e5; border: none; border-radius: 12px; cursor: pointer; }
        button:hover { background: #4338ca; }
        .err { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; border-radius: 10px; padding: 10px 12px; font-size: .85rem; margin-bottom: 14px; }
        .err ul { margin: 4px 0 0; padding-left: 18px; }
        .done { text-align: center; padding: 12px 0; }
        .check { width: 64px; height: 64px; border-radius: 50%; background: #16a34a; color: #fff; display: grid; place-items: center; font-size: 34px; margin: 0 auto 16px; }
        .done h1 { font-size: 1.5rem; }
        .done p { color: #475569; line-height: 1.6; }
        .badge { display: inline-block; background: #eef2ff; color: #4338ca; font-weight: 700; border-radius: 8px; padding: 6px 12px; margin-top: 6px; font-family: monospace; }
        .type { display: inline-block; border-radius: 999px; padding: 5px 14px; font-size: .82rem; font-weight: 800; margin-bottom: 12px; }
        .type-direct { background: #eef2ff; color: #4338ca; }
        .type-indirect { background: #ecfdf5; color: #047857; }
    </style>
</head>
<body>
    <div class="card">
        @if ($done)
            <div class="done">
                <div class="check">✓</div>
                <p class="brand">NAHSHON MEP · {{ $site->code }} {{ $site->name }}</p>
                <h1>등록 완료!</h1>
                <div class="type type-{{ $employmentType }}">{{ $typeLabel }}</div>
                <p><b>{{ $workerName }}</b> 님, 작업자로 등록되었습니다.<br>이제 현장 출퇴근을 시작할 수 있습니다.</p>
                @if (!empty($employee?->employee_number))
                    <div class="badge">사번 {{ $employee->employee_number }}</div>
                @endif
            </div>
        @else
            <p class="brand">NAHSHON MEP · 작업자 간편 등록</p>
            <h1>작업자 등록</h1>
            <div class="type type-{{ $employmentType }}">{{ $typeLabel }}</div>
            <p class="site">{{ $site->code }} {{ $site->name }}</p>

            @if ($errors->any())
                <div class="err">입력을 확인해 주세요:<ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
            @endif

            <form method="POST" action="{{ route('worker-join.store', ['site' => $site]) }}">
                @csrf
                {{-- 고용 형태는 QR 별로 다르다 — 폼에서 바꿀 수 없게 hidden 으로 넘긴다. --}}
                <input type="hidden" name="employment_type" value="{{ $employmentType }}">
                <label>이름 <span class="req">*</span></label>
                <input type="text" name="full_name" value="{{ old('full_name') }}" placeholder="홍길동" required>

                <label>소속회사 <span class="req">*</span></label>
                <select name="company_id" required>
                    <option value="">선택하세요</option>
                    @foreach ($companies as $c)
                        <option value="{{ $c->id }}" @selected(old('company_id') == $c->id)>{{ $c->name }}</option>
                    @endforeach
                </select>

                <label>공정 (Trade) <span class="req">*</span></label>
                <input type="text" name="role" list="trade-list" value="{{ old('role') }}" placeholder="목록에서 선택하거나 직접 입력" required autocomplete="off">
                <datalist id="trade-list">
                    @foreach ($roles as $t)
                        <option value="{{ $t }}"></option>
                    @endforeach
                </datalist>
                <div style="font-size:.78rem;color:#64748b;margin-top:5px">공정관리(WBS)의 공종 목록이며, 없으면 직접 입력하세요.</div>

                <label>이메일 <span class="req">*</span></label>
                <input type="email" name="email" value="{{ old('email') }}" placeholder="name@example.com" required>

                <label>전화번호 <span class="req">*</span></label>
                <input type="tel" name="phone" value="{{ old('phone') }}" placeholder="480-555-0100" required>

                <button type="submit">작업자로 등록하기</button>
            </form>
        @endif
    </div>
</body>
</html>
