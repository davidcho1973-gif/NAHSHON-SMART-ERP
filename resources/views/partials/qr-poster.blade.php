{{--
    현장 부착용 QR 포스터 한 장.

    $site      Site
    $title     제목 (예: '작업자 간편 등록')
    $qrImage   data URI
    $url       QR 이 가리키는 주소(수동 입력용으로 함께 인쇄)
    $hint      안내 문구 (개발자 작성 HTML — 사용자 입력 아님)
    $steps     번호 목록 (개발자 작성 HTML)
    $badge     선택: ['label' => '협력사', 'class' => 'type-indirect']
    $tags      선택: [['label' => '출근 IN', 'class' => 'in'], ...]
--}}
<main class="sheet">
    <p class="brand">NAHSHON MEP</p>
    <h1>{{ $title }}</h1>
    @if (! empty($badge))
        <div class="type {{ $badge['class'] }}">{{ $badge['label'] }}</div>
    @endif
    <p class="site">{{ $site->code }} {{ $site->name }}</p>
    @if ($site->address)<p class="addr">{{ $site->address }}</p>@endif
    @if (! empty($tags))
        <div class="big-in-out">
            @foreach ($tags as $tag)<span class="tag {{ $tag['class'] }}">{{ $tag['label'] }}</span>@endforeach
        </div>
    @endif
    <p class="hint">{!! $hint !!}</p>
    <div><img class="qr" src="{{ $qrImage }}" alt="{{ $title }} QR"></div>
    <ol class="steps">
        @foreach ($steps as $step)<li>{!! $step !!}</li>@endforeach
    </ol>
    <p class="url">{{ $url }}</p>
</main>
