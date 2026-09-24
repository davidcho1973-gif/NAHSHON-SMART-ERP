{{--
    KO · EN · ES — 화면마다 붙는 언어 단추 한 벌.

    ── 왜 조각으로 빼 두는가 ──────────────────────────────────────────────
    현장에는 한국어를 못 읽는 사람이 더 많다. 그런데 언어 단추가 첫 화면에만 있어서,
    거기서 한 칸만 들어가면(문서·물어보기·자재·현장 기록) 다시 한글 화면이 나왔다.
    그 사람은 «영어로 바꿨는데 왜 한글이지» 가 아니라 <b>이 앱은 내 말을 모른다</b> 고
    읽는다. 화면마다 따로 만들면 새 화면에서 또 빠지므로, 한 조각을 모든 화면이 쓴다.

    고른 언어는 서버가 기억한다(app_locale 쿠키 + 로그인한 사람은 본인 언어까지).
    브라우저 안에만 두면 서버가 그리는 화면은 그 선택을 영영 모른다 — AppLocale 참고.
--}}
@php($currentLocale = \App\Support\AppLocale::normalize(app()->getLocale()) ?? 'ko')
<div class="lang-switch" role="group" aria-label="Language">
    @foreach (['ko' => 'KO', 'en' => 'EN', 'es' => 'ES'] as $code => $label)
        <button type="button" data-locale="{{ $code }}" aria-pressed="{{ $currentLocale === $code ? 'true' : 'false' }}">{{ $label }}</button>
    @endforeach
</div>
<style>
    .lang-switch { display: inline-flex; gap: 2px; padding: 3px; border: 1px solid rgba(0,0,0,.12); border-radius: 12px; background: rgba(0,0,0,.04); }
    .lang-switch button { min-width: 38px; min-height: 32px; padding: 4px 8px; border: 0; border-radius: 9px; background: transparent; color: inherit; font: inherit; font-size: 12px; font-weight: 700; cursor: pointer; opacity: .6; }
    .lang-switch button[aria-pressed="true"] { background: #fff; color: #0877bd; opacity: 1; box-shadow: 0 1px 3px rgba(28,43,61,.12); }
</style>
<script>
    (function () {
        // 누른 언어를 서버에 알리고 그 화면을 다시 그린다. 서버가 기억해야 다음 화면도
        // 같은 말로 열린다 — 브라우저 안에만 두면 첫 화면만 바뀐다(그게 예전 문제였다).
        var url = @json(route('locale.set'));
        // 토큰을 화면의 meta 에서 찾지 않는다 — 그 태그가 없는 화면이 여럿이고,
        // 없으면 이 단추만 조용히 안 먹는다. 조각이 자기 것을 들고 다닌다.
        var token = @json(csrf_token());
        document.querySelectorAll('.lang-switch button').forEach(function (button) {
            button.addEventListener('click', function () {
                fetch(url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': token,
                    },
                    body: JSON.stringify({ lang: button.dataset.locale }),
                }).then(function () { window.location.reload(); })
                  .catch(function () { window.location.reload(); });
            });
        });
    })();
</script>
