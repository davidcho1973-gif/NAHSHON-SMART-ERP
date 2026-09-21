{{--
    현장 부착용 QR 포스터 한 장 — 한국어·English·Español 을 함께 찍는다.
    (벽에 붙는 종이라 언어를 고를 수 없으므로 전부 인쇄한다.)

    $site      Site
    $langs     [언어 => ['title' => ..., 'hint' => ..., 'steps' => [...]]]
    $qrImage   data URI
    $url       QR 이 가리키는 주소(수동 입력용으로 함께 인쇄)
    $tags      선택: [['label' => '출근 · IN · ENTRADA', 'class' => 'in'], ...]
    $accent    선택: ['bg' => ..., 'ink' => ...] — 이 포스터가 <b>여는 화면과 같은 색</b>.
               포스터마다 다르므로 판 안쪽에 걸어 둔다(모아 인쇄 화면에 여러 장이 함께 선다).
--}}
@php($primary = \App\Support\WorkerLang::DEFAULT)
@php($accent = $accent ?? \App\Support\QrPosters::ACCENTS[\App\Support\QrPosters::GATE])
<main class="sheet" style="--kakao: {{ $accent['bg'] }}; --label: {{ $accent['ink'] }}">
    <p class="brand">{{ \App\Support\Org::name() }}</p>
    <h1>{{ $langs[$primary]['title'] }}</h1>
    <p class="alt-titles">
        @foreach ($langs as $code => $t)@if ($code !== $primary)<span>{{ $t['title'] }}</span>@endif @endforeach
    </p>
    <p class="site">{{ $site->code }} {{ $site->name }}</p>
    @if ($site->address)<p class="addr">{{ $site->address }}</p>@endif
    @if (! empty($tags))
        <div class="big-in-out">
            @foreach ($tags as $tag)<span class="tag {{ $tag['class'] }}">{{ $tag['label'] }}</span>@endforeach
        </div>
    @endif
    <div><img class="qr" src="{{ $qrImage }}" alt="{{ $langs[$primary]['title'] }} QR"></div>

    <div class="lang-blocks">
        @foreach ($langs as $code => $t)
            <section class="lang-block">
                <p class="lang-chip">{{ \App\Support\WorkerLang::OPTIONS[$code] ?? $code }}</p>
                <p class="lang-hint">{{ $t['hint'] }}</p>
                <ol class="steps">
                    @foreach ($t['steps'] as $step)<li>{{ $step }}</li>@endforeach
                </ol>
            </section>
        @endforeach
    </div>

    <p class="url">{{ $url }}</p>
</main>
