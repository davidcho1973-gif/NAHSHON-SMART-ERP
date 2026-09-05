#!/usr/bin/env bash
#
# 훅이 없는 배포가 지금 어느 커밋을 돌고 있는가 — 묻지 않아도 매번 적히게.
#
# ── verify-build.sh 와 무엇이 다른가 ───────────────────────────────────
# verify-build.sh 는 <b>우리가 방금 시킨</b> 배포를 확인한다. 훅을 불렀으니 그 커밋이
# 서버에 올라오는 것이 당연하고, 안 올라오면 실패다.
#
# 이 스크립트는 <b>우리가 시키지 않은</b> 배포를 들여다본다. 훅 시크릿이 없는 환경은
# 대시보드의 «Push to deploy» 로만 나가고, 그 경로는 CI 에 아무 기록을 남기지 않는다.
# 그래서 「거기 배포됐어?」 에 아무도 답할 수 없었고, 실제로 한 환경이 이틀 동안 옛
# 커밋에 멈춰 있는 동안 그 위로 커밋 열네 개가 쌓였는데 아무도 몰랐다(2026-09-05 기록).
#
# ── 실패시키지 않는 이유 ───────────────────────────────────────────────
# 내가 일으키지 않은 배포가 늦다고 빨간 X 를 놓으면, 사람은 곧 그 X 를 무시하는 법을
# 배운다. 그러면 진짜 실패도 함께 묻힌다. 그래서 여기서는 <b>적기만</b> 한다 —
# 경고는 남기되 잡은 초록으로 끝낸다. 훅이 생기면 verify-build.sh 로 올리면 된다.
#
# 필요한 환경변수: BASE, GITHUB_SHA, ENV_LABEL
set -euo pipefail

label="${ENV_LABEL:-이 환경}"

if [ -z "${BASE:-}" ]; then
  echo "::notice title=확인 건너뜀::${label} 주소가 없어 확인하지 못했습니다."
  exit 0
fi

want="${GITHUB_SHA:0:7}"
tries="${TRIES:-10}"
interval="${INTERVAL:-30}"

say() {
  echo "$1"
  if [ -n "${GITHUB_STEP_SUMMARY:-}" ]; then
    echo "$1" >>"$GITHUB_STEP_SUMMARY"
  fi
}

got=""
for i in $(seq 1 "$tries"); do
  sleep "$interval"
  body=$(curl -sS --max-time 20 "$BASE/build-version" || true)
  got=$(printf '%s' "$body" | sed -n 's/.*"commit_short" *: *"\([^"]*\)".*/\1/p')
  echo "  ${i}회차 — ${label}: ${got:-응답없음} / 기대: $want"
  [ "$got" = "$want" ] && break
done

if [ "$got" = "$want" ]; then
  say "### ${label} — 최신입니다"
  say ""
  say "\`$want\` 가 돌고 있습니다."
  echo "::notice title=배포 확인됨::${label} — $want 가 돌고 있습니다."
  exit 0
fi

if [ -z "$got" ]; then
  say "### ${label} — 응답 없음"
  say ""
  say "\`$BASE/build-version\` 이 응답하지 않습니다. 주소가 바뀌었거나 서버가 내려가 있습니다."
  echo "::warning title=응답 없음::${label} — $BASE 가 응답하지 않습니다."
  exit 0
fi

# 여기가 이 파일을 만든 이유다. 서버는 멀쩡히 응답하는데 옛 커밋이다 —
# 화면이 열리기 때문에 아무도 눈치채지 못하는, 가장 오래 가는 종류의 사고다.
say "### ${label} — 옛 커밋에 멈춰 있습니다"
say ""
say "| | |"
say "|---|---|"
say "| 서버가 돌고 있는 커밋 | \`$got\` |"
say "| 방금 올린 커밋 | \`$want\` |"
say ""
say "이 환경은 배포 훅이 없어 Laravel Cloud 의 **Push to deploy** 로만 나갑니다."
say "그것이 꺼져 있거나 다른 브랜치를 보고 있으면, 푸시해도 아무 일이 일어나지 않습니다."
say ""
say "**할 일:** Laravel Cloud → 그 환경 → Deployments 탭 → 맨 위 줄이 \`$want\` 인지"
say "확인하고, 아니면 **Deploy** 를 누릅니다. (환경 고르는 법은 \`DEPLOYMENT_ENVIRONMENTS.md\`)"
echo "::warning title=옛 커밋::${label} — 서버는 $got, 방금 올린 것은 $want. Laravel Cloud 에서 Deploy 를 눌러야 합니다."
exit 0
