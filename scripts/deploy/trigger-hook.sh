#!/usr/bin/env bash
#
# Laravel Cloud 의 Deploy Hook 을 호출한다.
#
# 이 단계는 실패해도 잡을 세우지 않는다. 대시보드의 "Push to deploy" 가 켜져 있으면
# 푸시만으로도 배포가 나가므로, 훅은 "테스트를 통과한 커밋만" 을 위한 보조 경로일 뿐
# 유일한 경로가 아니다. 훅이 안 된다고 빨간불을 켜면 정작 배포가 됐는지는 아무도 안 본다.
# 성패는 verify-build.sh 가 서버에 직접 물어서 판정한다.
#
# 필요한 환경변수: HOOK, GITHUB_SHA, ENV_LABEL, GITHUB_OUTPUT
set -euo pipefail

echo "ok=false" >> "$GITHUB_OUTPUT"

if [ -z "${HOOK:-}" ]; then
  echo "::notice title=훅 없음::${ENV_LABEL} 훅 시크릿이 없어 건너뜁니다. Push to deploy 로 배포됐는지는 아래에서 확인합니다."
  exit 0
fi

# commit_hash 를 붙여 "테스트를 통과한 그 커밋" 을 콕 집어 배포한다.
# 안 붙이면 Laravel Cloud 가 호출 시점의 브랜치 최신 커밋을 가져가는데,
# 테스트가 도는 동안 푸시가 하나 더 들어오면 검증 안 된 커밋이 나간다.
sep='?'
case "$HOOK" in *\?*) sep='&' ;; esac
with_hash="${HOOK}${sep}commit_hash=${GITHUB_SHA}"

# 훅의 호출 규약(메서드·파라미터 허용 여부)은 대시보드에 적혀 있지 않다.
# 좁은 것부터 넓은 것 순으로 시도하고 처음 2xx 가 나오는 조합에서 멈춘다.
# 순서가 곧 우선순위다 — commit_hash 가 붙은 쪽이 배포 대상이 정확하므로 먼저 본다.
# 로그에는 조합 이름과 응답 코드만 남는다. URL 은 어떤 경우에도 출력하지 않는다.
try() {
  curl -sS -o /tmp/hook.out -w '%{http_code}' -X "$1" "$2" --max-time 60 || echo 000
}

code=''
chosen=''
last=''
for combo in \
  "POST|$with_hash|POST + commit_hash" \
  "POST|$HOOK|POST (파라미터 없이)" \
  "GET|$with_hash|GET + commit_hash" \
  "GET|$HOOK|GET (파라미터 없이)"
do
  method=${combo%%|*}
  rest=${combo#*|}
  url=${rest%%|*}
  label=${rest#*|}

  c=$(try "$method" "$url")
  echo "  $label → HTTP $c"
  case "$c" in
    2*) code=$c; chosen=$label; break ;;
  esac
  last=$c
done

if [ -n "$code" ]; then
  echo "ok=true" >> "$GITHUB_OUTPUT"
  echo "::notice title=배포 시작됨::${ENV_LABEL} — $chosen 으로 ${GITHUB_SHA:0:7} 배포를 요청했습니다 (HTTP $code)."
  head -c 300 /tmp/hook.out || true
  echo
  exit 0
fi

echo "마지막 응답 본문:"
head -c 500 /tmp/hook.out || true
echo

# 전부 경고다. 훅이 안 되는 것과 배포가 안 되는 것은 다른 이야기다.
case "$last" in
  000) echo "::warning title=훅 연결 실패::${ENV_LABEL} — Deploy Hook 에 연결하지 못했습니다." ;;
  # 리다이렉트를 -L 로 따라가지 않는 이유: 따라가면 첫 화면 HTML 을 200 으로 받아
  # 배포가 성공한 것으로 오인하게 된다.
  3*) echo "::warning title=훅이 로그인 화면으로 보냄::${ENV_LABEL} — 네 조합 모두 리다이렉트됐습니다. 입력칸에서 잘려 보이는 값을 복사하면 이렇게 됩니다. 복사 버튼으로 다시 복사하세요." ;;
  401|403) echo "::warning title=훅이 거부됨::${ENV_LABEL} — HTTP $last. 토큰이 만료됐거나 재발급된 것 같습니다." ;;
  404) echo "::warning title=훅을 찾을 수 없음::${ENV_LABEL} — HTTP $last. 주소 일부만 복사됐거나 훅이 삭제된 것 같습니다." ;;
  *)   echo "::warning title=훅 호출 실패::${ENV_LABEL} — HTTP $last. 어느 조합도 통하지 않았습니다." ;;
esac
