#!/usr/bin/env bash
#
# 배포의 진짜 관문. 훅으로 나갔든 Push to deploy 로 나갔든 경로는 상관없고
# "이 커밋이 지금 서버에서 돌고 있는가" 만 본다.
#
# 배포는 서버가 바뀌는 일이지 요청이 200 을 받는 일이 아니다. 훅이 202 를 주고도
# 빌드가 깨져 옛 버전이 계속 도는 일은 얼마든지 있다.
#
# 필요한 환경변수: BASE, GITHUB_SHA, ENV_LABEL
set -euo pipefail

if [ -z "${BASE:-}" ]; then
  echo "::notice title=확인 건너뜀::${ENV_LABEL} 주소 변수가 없어 배포 결과를 확인하지 못했습니다."
  exit 0
fi

want="${GITHUB_SHA:0:7}"
tries="${TRIES:-20}"
interval="${INTERVAL:-30}"

for i in $(seq 1 "$tries"); do
  sleep "$interval"
  body=$(curl -sS --max-time 20 "$BASE/build-version" || true)
  got=$(printf '%s' "$body" | sed -n 's/.*"commit_short" *: *"\([^"]*\)".*/\1/p')
  echo "  ${i}회차 — 서버: ${got:-응답없음} / 기대: $want"
  if [ "$got" = "$want" ]; then
    echo "::notice title=배포 확인됨::${ENV_LABEL} — $want 가 돌고 있습니다."
    exit 0
  fi
done

waited=$(( tries * interval ))
if [ "$waited" -ge 60 ]; then
  spent="$(( waited / 60 ))분"
else
  spent="${waited}초"
fi
echo "::error title=배포 안 됨::${ENV_LABEL} — ${spent} 안에 $want 로 바뀌지 않았습니다. 훅과 Push to deploy 둘 다 배포를 만들어내지 못했습니다."
echo
echo "확인할 곳: Laravel Cloud → Deployments 탭에 이 커밋의 배포 기록이 있는지."
echo "  기록이 아예 없으면 → Laravel Cloud 가 푸시를 감지하지 못하는 것"
echo "  기록이 있는데 실패했으면 → 그 배포 로그에 원인이 있음"
exit 1
