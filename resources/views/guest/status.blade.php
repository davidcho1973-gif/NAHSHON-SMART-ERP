<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>{{ \App\Support\Org::name() }} — @if($expired) Link Expired @else {{ $snapshot['siteName'] }} @endif</title>
<link rel="preconnect" href="https://cdn.jsdelivr.net">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/variable/pretendardvariable-dynamic-subset.min.css">
<style>
  :root { --accent: {{ \App\Support\Org::color() }}; --accent-dim: {{ \App\Support\Org::colorDim(0.12) }}; }
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family:'Pretendard Variable',Pretendard,-apple-system,'Apple SD Gothic Neo','Malgun Gothic',sans-serif; background:#f4f5f7; color:#1a1d21;
         min-height:100vh; padding-bottom:40px; }
  .wrap { max-width:560px; margin:0 auto; padding:0 16px; }
  .top { background:#fff; border-bottom:1px solid #e8eaed; padding:14px 0; margin-bottom:16px; }
  .top-inner { max-width:560px; margin:0 auto; padding:0 16px; display:flex; align-items:center; gap:10px; }
  .org-logo { height:28px; border-radius:6px; }
  .org-badge { width:32px; height:32px; border-radius:8px; background:var(--accent); color:#fff; font-weight:800;
               font-size:13px; display:flex; align-items:center; justify-content:center; }
  .org-name { font-size:15px; font-weight:700; flex:1; }
  .lang-btn { border:1px solid #d7dade; background:#fff; border-radius:8px; padding:6px 10px; font-size:12px;
              font-weight:600; color:#4b5563; cursor:pointer; font-family:inherit; }
  .card { background:#fff; border:1px solid #e8eaed; border-radius:16px; padding:18px; margin-bottom:14px; }
  .site-name { font-size:20px; font-weight:800; margin-bottom:2px; }
  .site-sub { font-size:12px; color:#8b919a; margin-bottom:14px; }
  .big-row { display:flex; align-items:baseline; gap:8px; margin-bottom:8px; }
  .big-pct { font-size:38px; font-weight:800; color:var(--accent); line-height:1; }
  .big-label { font-size:13px; color:#6b7280; font-weight:600; }
  .bar { height:10px; background:#eef0f3; border-radius:5px; overflow:hidden; }
  .bar > i { display:block; height:100%; background:var(--accent); border-radius:5px; }
  .meta { display:flex; gap:16px; margin-top:12px; font-size:12.5px; color:#4b5563; flex-wrap:wrap; }
  .meta b { color:#1a1d21; }
  .proj-title { font-size:15px; font-weight:700; margin-bottom:2px; }
  .proj-sub { font-size:12px; color:#8b919a; margin-bottom:12px; }
  .stage { display:grid; grid-template-columns:1fr auto; gap:4px 10px; margin-bottom:10px; }
  .stage-name { font-size:13px; color:#374151; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  .stage-pct { font-size:12.5px; font-weight:700; }
  .stage .bar { grid-column:1 / -1; height:6px; }
  .done  { color:#059669; } .done  ~ .bar > i { background:#059669; }
  .active{ color:#d97706; } .active~ .bar > i { background:#d97706; }
  .planned{ color:#9ca3af; } .planned~ .bar > i { background:#c3c8cf; }
  .empty { text-align:center; padding:48px 20px; color:#8b919a; font-size:13.5px; line-height:1.7; }
  .empty .big { font-size:40px; margin-bottom:10px; }
  .foot { text-align:center; font-size:11.5px; color:#9ca3af; margin-top:22px; line-height:1.8; }
</style>
</head>
<body>

<div class="top"><div class="top-inner">
  @if(\App\Support\Org::hasLogo())
    <img class="org-logo" src="{{ route('org.logo') }}?v={{ \App\Support\Org::logoVersion() }}" alt="">
  @else
    <div class="org-badge">{{ \App\Support\Org::initials() }}</div>
  @endif
  <div class="org-name">{{ \App\Support\Org::name() }}</div>
  <button class="lang-btn" id="lang-btn" onclick="toggleLang()">EN</button>
</div></div>

<div class="wrap">
@if($expired)
  <div class="card"><div class="empty">
    <div class="big">⏳</div>
    <div style="font-size:16px;font-weight:700;color:#1a1d21;margin-bottom:6px" data-t="expiredTitle">링크가 만료되었습니다</div>
    <span data-t="expiredBody">이 열람 링크는 회수되었거나 유효기간이 지났습니다.<br>담당자에게 새 링크를 요청해 주세요.</span>
  </div></div>
@else
  <div class="card">
    <div class="site-name">{{ $snapshot['siteName'] }}</div>
    <div class="site-sub">{{ $snapshot['siteCode'] }} · <span data-t="statusAsOf">공정 현황</span>@if($snapshot['updatedAt']) · {{ $snapshot['updatedAt'] }}@endif</div>
    <div class="big-row">
      <div class="big-pct">{{ $snapshot['progress'] }}%</div>
      <div class="big-label" data-t="overall">전체 진척률</div>
    </div>
    <div class="bar"><i style="width:{{ max(0, min(100, $snapshot['progress'])) }}%"></i></div>
    <div class="meta">
      @if($snapshot['projectedEnd'])
        <span><span data-t="projected">예상 준공</span> · <b>{{ $snapshot['projectedEnd'] }}</b></span>
      @endif
      <span><span data-t="projects">프로젝트</span> · <b>{{ count($snapshot['projects']) }}</b></span>
    </div>
  </div>

  @forelse($snapshot['projects'] as $project)
    <div class="card">
      <div class="proj-title">{{ $project['name'] }}</div>
      <div class="proj-sub">
        <span data-t="progressWord">진척률</span> {{ $project['progress'] }}%
        · {{ $project['doneCount'] }}/{{ $project['totalCount'] }} <span data-t="doneWord">완료</span>
        @if($project['projectedEnd']) · <span data-t="projected">예상 준공</span> {{ $project['projectedEnd'] }}@endif
      </div>
      @foreach($project['stages'] as $stage)
        <div class="stage">
          <span class="stage-name">{{ $stage['name'] }}</span>
          <span class="stage-pct {{ $stage['state'] }}">{{ $stage['progress'] }}%</span>
          <div class="bar"><i style="width:{{ max(0, min(100, $stage['progress'])) }}%"></i></div>
        </div>
      @endforeach
    </div>
  @empty
    <div class="card"><div class="empty">
      <div class="big">🏗️</div>
      <span data-t="noData">공정 데이터를 준비하고 있습니다.<br>잠시 후 다시 확인해 주세요.</span>
    </div></div>
  @endforelse
@endif

  <div class="foot">
    <span data-t="readOnly">열람 전용 화면입니다 — 이 링크로는 어떤 데이터도 변경할 수 없습니다.</span><br>
    © {{ now()->format('Y') }} {{ \App\Support\Org::legalName() }}
  </div>
</div>

<script>
// 손님이 누구인지 모르는 화면이라 두 언어를 다 싣는다. 기본은 브라우저 언어.
var DICT = {
  ko: {
    expiredTitle: '링크가 만료되었습니다',
    expiredBody: '이 열람 링크는 회수되었거나 유효기간이 지났습니다.<br>담당자에게 새 링크를 요청해 주세요.',
    statusAsOf: '공정 현황', overall: '전체 진척률', projected: '예상 준공', projects: '프로젝트',
    progressWord: '진척률', doneWord: '완료',
    noData: '공정 데이터를 준비하고 있습니다.<br>잠시 후 다시 확인해 주세요.',
    readOnly: '열람 전용 화면입니다 — 이 링크로는 어떤 데이터도 변경할 수 없습니다.'
  },
  en: {
    expiredTitle: 'This link has expired',
    expiredBody: 'This viewing link was revoked or has passed its expiry date.<br>Please ask your contact for a new link.',
    statusAsOf: 'Progress Status', overall: 'Overall Progress', projected: 'Projected Completion', projects: 'Projects',
    progressWord: 'Progress', doneWord: 'done',
    noData: 'Schedule data is being prepared.<br>Please check back soon.',
    readOnly: 'View-only page — nothing can be changed through this link.'
  }
};
var lang = 'ko';
try {
  lang = localStorage.getItem('guestViewLang')
    || ((navigator.language || '').toLowerCase().indexOf('ko') === 0 ? 'ko' : 'en');
} catch (e) {}
function applyLang() {
  var d = DICT[lang] || DICT.ko;
  document.querySelectorAll('[data-t]').forEach(function (el) {
    var key = el.getAttribute('data-t');
    if (d[key]) el.innerHTML = d[key];
  });
  var btn = document.getElementById('lang-btn');
  if (btn) btn.textContent = lang === 'ko' ? 'EN' : '한국어';
  document.documentElement.lang = lang;
}
function toggleLang() {
  lang = lang === 'ko' ? 'en' : 'ko';
  try { localStorage.setItem('guestViewLang', lang); } catch (e) {}
  applyLang();
}
applyLang();
</script>
</body>
</html>
