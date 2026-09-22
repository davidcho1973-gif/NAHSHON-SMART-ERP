{{--
    「홈 화면에 추가」 안내서.

    현장에서 막히는 사람은 «버튼을 못 찾는» 사람이 아니라 <b>그 버튼이 자기 폰에 없는</b>
    사람이다(카카오톡으로 열었거나, 아이폰인데 크롬이거나). 그래서 이 화면은 단계를
    나열하기 전에 <b>지금 이 폰이 어느 경우인지</b> 먼저 말한다.

    글씨를 크게 둔다 — 한 손에 폰을 들고 다른 손으로 따라 누르는 사람이 읽는다.
--}}
@php($t = $dict[$lang] ?? $dict['ko'])
@php($mine = $t['cases'][$case])
<!DOCTYPE html>
<html lang="{{ $lang }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $t['title'] }}</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/variable/pretendardvariable-dynamic-subset.min.css">
    <style>
        :root {
            color-scheme: light;
            font-family: 'Pretendard Variable', Pretendard, -apple-system, BlinkMacSystemFont, 'Apple SD Gothic Neo', Arial, sans-serif;
            --blue: #0877BD; --ink: #10233F; --ink-2: #5A6270; --paper: #F3F6FA; --rule: #E3E6EA;
            background: var(--paper); color: var(--ink);
        }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 18px 16px calc(40px + env(safe-area-inset-bottom)); display: flex; justify-content: center; }
        .wrap { width: min(100%, 560px); }
        h1, h2, h3, p, li, summary { word-break: keep-all; }
        h1 { margin: 0 0 6px; font-size: 1.6rem; font-weight: 800; letter-spacing: -.02em; }
        .lead { margin: 0 0 16px; color: var(--ink-2); font-size: 1rem; line-height: 1.6; }
        .langs { display: flex; gap: 8px; margin: 0 0 18px; }
        .langs a { flex: 1; text-align: center; text-decoration: none; padding: 11px 0; border-radius: 999px;
                   border: 1px solid var(--rule); background: #fff; color: var(--ink-2); font-weight: 700; font-size: .92rem; }
        .langs a.on { background: var(--ink); border-color: var(--ink); color: #fff; }

        .card { background: #fff; border-radius: 18px; padding: 22px 20px; margin-bottom: 16px; }
        .tag { display: inline-block; padding: 5px 13px; border-radius: 999px; background: var(--blue); color: #fff;
               font-size: .76rem; font-weight: 800; letter-spacing: .03em; margin-bottom: 12px; }
        h2 { margin: 0 0 4px; font-size: 1.25rem; font-weight: 800; }
        .note { margin: 10px 0 0; padding: 12px 14px; border-radius: 12px; background: #FFF7ED; color: #9A3412;
                font-size: .95rem; line-height: 1.6; font-weight: 700; }

        ol.steps { margin: 18px 0 0; padding: 0; list-style: none; counter-reset: s; }
        ol.steps li { counter-increment: s; position: relative; padding: 0 0 18px 48px; font-size: 1.05rem; line-height: 1.65; }
        ol.steps li::before { content: counter(s); position: absolute; left: 0; top: -2px; width: 34px; height: 34px;
                              border-radius: 50%; background: var(--blue); color: #fff; display: grid; place-items: center;
                              font-weight: 800; font-size: 1rem; }
        ol.steps li:last-child { padding-bottom: 0; }
        ol.steps b { color: var(--ink); background: #E8F1FA; border-radius: 6px; padding: 1px 6px; }

        details { background: #fff; border-radius: 14px; margin-bottom: 10px; overflow: hidden; }
        summary { cursor: pointer; padding: 16px 18px; font-weight: 800; font-size: 1rem; list-style: none; }
        summary::-webkit-details-marker { display: none; }
        summary::after { content: '＋'; float: right; color: var(--ink-2); font-weight: 800; }
        details[open] summary::after { content: '－'; }
        details .inner { padding: 0 18px 18px; border-top: 1px solid var(--rule); }
        details .inner ol.steps { margin-top: 14px; }
        details .inner ol.steps li { font-size: .98rem; }

        .why { background: #fff; border-radius: 14px; padding: 18px 20px; margin-bottom: 14px; }
        .why h3 { margin: 0 0 6px; font-size: 1rem; font-weight: 800; }
        .why p { margin: 0; color: var(--ink-2); font-size: .97rem; line-height: 1.65; }
        .ask { text-align: center; color: var(--ink-2); font-size: .93rem; line-height: 1.6; margin: 20px 4px 0; }
        h3.others { margin: 24px 2px 12px; font-size: .95rem; color: var(--ink-2); font-weight: 800; }
    </style>
</head>
<body>
<div class="wrap">
    <h1>{{ $t['title'] }}</h1>
    <p class="lead">{{ $t['lead'] }}</p>

    <div class="langs">
        @foreach ($langOptions as $code => $label)
            <a class="{{ $code === $lang ? 'on' : '' }}"
               href="{{ route('install-guide', ['lang' => $code] + ($detected ? [] : ['phone' => $case])) }}">{{ $label }}</a>
        @endforeach
    </div>

    {{-- 지금 이 폰의 방법을 맨 위에, 펼친 채로. 찾아 들어가게 하지 않는다. --}}
    <div class="card">
        <span class="tag">{{ $t['yours'] }}</span>
        <h2>{{ $mine['name'] }}</h2>
        @if ($mine['note'])
            <p class="note">{!! $mine['note'] !!}</p>
        @endif
        <ol class="steps">
            @foreach ($mine['steps'] as $step)<li>{!! $step !!}</li>@endforeach
        </ol>
    </div>

    <div class="why">
        <h3>{{ $t['why'] }}</h3>
        <p>{{ $t['whyBody'] }}</p>
    </div>

    {{-- 판정이 틀릴 수 있다. 다른 경우도 전부 접어서 둔다 — 사람이 자기 것을 고를 수 있게. --}}
    <h3 class="others">{{ $t['others'] }}</h3>
    @foreach ($order as $key)
        @continue($key === $case)
        @php($other = $t['cases'][$key])
        <details>
            <summary>{{ $other['name'] }}</summary>
            <div class="inner">
                @if ($other['note'])<p class="note">{!! $other['note'] !!}</p>@endif
                <ol class="steps">
                    @foreach ($other['steps'] as $step)<li>{!! $step !!}</li>@endforeach
                </ol>
            </div>
        </details>
    @endforeach

    <p class="ask">{{ $t['ask'] }}</p>
</div>
</body>
</html>
