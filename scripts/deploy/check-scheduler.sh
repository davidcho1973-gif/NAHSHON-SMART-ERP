#!/usr/bin/env bash
#
# 스케줄러가 살아 있는가. 배포할 때마다 본다.
#
# 왜 배포 때 보나 — 스케줄러가 꺼져도 앱은 멀쩡해 보인다. 화면도 뜨고 출근도 찍힌다.
# 다만 오후 8시 자동 퇴근이 안 돌고, 문서가 "분석 중"에 머물고, 경비가 안 잡힐 뿐이다.
# 아무도 오류를 못 본 채로 며칠이 지나고, 그 사이 근무시간이 0 으로 계산된다.
# 배포는 우리가 어차피 결과를 들여다보는 몇 안 되는 순간이라, 여기에 붙여 둔다.
#
# 배포를 실패시키지는 않는다. 스케줄러는 코드가 아니라 인프라 설정이고, 여기서 빨간
# X 를 내봐야 고쳐지지 않는다. 매번 빨간 X 를 보면 사람들은 X 를 무시하게 된다.
# 대신 경고와 요약으로 눈에 띄게 남긴다.
#
# 필요한 환경변수: BASE, ENV_LABEL
set -euo pipefail

if [ -z "${BASE:-}" ]; then
  echo "::notice title=스케줄러 확인 건너뜀::${ENV_LABEL} 주소 변수가 없습니다."
  exit 0
fi

body=$(curl -sS --max-time 20 "$BASE/build-version" || true)

if [ -z "$body" ]; then
  echo "::warning title=스케줄러 확인 실패::${ENV_LABEL} — /build-version 이 응답하지 않았습니다."
  exit 0
fi

field() { printf '%s' "$body" | sed -n "s/.*\"$1\" *: *\"\\([^\"]*\\)\".*/\\1/p"; }

running=$(printf '%s' "$body" | sed -n 's/.*"running" *: *\([a-z]*\).*/\1/p')
store=$(field store)
wakes=$(printf '%s' "$body" | sed -n 's/.*"wakes_database_every_minute" *: *\([a-z]*\).*/\1/p')
minutes=$(printf '%s' "$body" | sed -n 's/.*"minutes_ago" *: *\([0-9]*\).*/\1/p')
last=$(field last_beat_at)
message=$(field message)

{
  echo "### ${ENV_LABEL} 스케줄러"
  echo
  if [ "$running" = "true" ]; then
    echo "**정상 동작 중** — 마지막 맥박 ${minutes:-0}분 전 (\`${last:-?}\`)"
  else
    echo "**멈춤** — ${message:-사유 불명}"
    echo
    echo "이 상태에서는 오후 8시 자동 퇴근, 문서 재분석, 경비 계상이 돌지 않습니다."
    echo "Laravel Cloud → Environment 탭에서 Scheduler 리소스가 켜져 있는지 확인하세요."
  fi
  echo
  if [ "$wakes" = "true" ]; then
    echo "**캐시가 \`${store}\`** — schedule:run 이 매분 데이터베이스를 깨웁니다."
    echo "Custom environment variables 에 \`CACHE_STORE=file\` 을 넣으세요."
  else
    echo "캐시 저장소: \`${store:-?}\` (데이터베이스를 매분 깨우지 않습니다)"
  fi
} >> "${GITHUB_STEP_SUMMARY:-/dev/stdout}"

if [ "$running" = "true" ]; then
  echo "::notice title=스케줄러 정상::${ENV_LABEL} — 마지막 맥박 ${minutes:-0}분 전."
else
  echo "::warning title=스케줄러 멈춤::${ENV_LABEL} — ${message:-사유 불명} 자동 퇴근이 돌지 않습니다."
fi

# 사람이 로그에서 바로 읽을 수 있게 원문도 남긴다.
if [ "$wakes" = "true" ]; then
  echo "::warning title=캐시가 데이터베이스::${ENV_LABEL} — schedule:run 이 매분 데이터베이스를 깨웁니다. CACHE_STORE=file 을 설정하세요."
fi

# 도메인이 절반만 바뀌는 사고 — 새 주소로 열리는데 APP_URL 은 옛 주소인 경우.
# QR·설치 카드·매니페스트가 전부 옛 주소를 가리키는데 화면은 멀쩡해 보인다.
appurl=$(field app_url)
matches=$(printf '%s' "$body" | sed -n 's/.*"matches" *: *\([a-z]*\).*/\1/p')

if [ "$matches" = "false" ]; then
  echo "::warning title=도메인 불일치::${ENV_LABEL} — 열린 주소는 ${BASE} 인데 APP_URL 은 ${appurl} 입니다. QR·설치 카드가 옛 주소를 가리킵니다."
  {
    echo
    echo "**도메인 불일치** — 열린 주소 \`${BASE}\` / APP_URL \`${appurl}\`"
    echo
    echo "APP_URL 을 새 도메인으로 바꾸고 재배포하세요. 안 그러면 QR·앱 설치 카드·매니페스트가"
    echo "모두 옛 주소를 가리킵니다(화면은 멀쩡해 보입니다)."
  } >> "${GITHUB_STEP_SUMMARY:-/dev/stdout}"
fi

# 업로드 저장소가 배포를 견디는가 — local/public 이면 배포마다 문서 원본이 사라진다.
durable=$(printf '%s' "$body" | sed -n 's/.*"durable" *: *\([a-z]*\).*/\1/p')
dochub=$(field document_hub)

if [ "$durable" = "false" ]; then
  echo "::warning title=업로드 저장소가 휘발성::${ENV_LABEL} — 문서 디스크가 \`${dochub:-?}\` 입니다. 배포마다 문서 원본·현장 사진이 사라집니다. 버킷 연결 + DOCUMENT_STORAGE_DISK/DOCUMENT_DISK/WBS_PHOTO_DISK 환경변수를 확인하세요."
fi

# 파일을 실제로 몇 MB 까지 받는가. public/.user.ini 에 64M/72M 을 적어 두었지만
# «적어 두었다» 와 «적용됐다» 는 다르다 — PHP-FPM 이 그 파일을 안 읽으면 기본값(2M)이
# 살아 있고, 화면은 「최대 50MB」라고 적어 둔 채 도면 한 장도 못 받는다.
# 화면도 서버도 멀쩡해 보이므로 여기서 숫자로 확인한다.
userini=$(printf '%s' "$body" | sed -n 's/.*"user_ini_applied" *: *\([a-z]*\).*/\1/p')
perfile=$(printf '%s' "$body" | sed -n 's/.*"effective_per_file_mb" *: *\([0-9.]*\).*/\1/p')
postmax=$(printf '%s' "$body" | sed -n 's/.*"post_max_size_mb" *: *\([0-9.]*\).*/\1/p')

if [ "$userini" = "false" ]; then
  echo "::warning title=업로드 한도가 기본값::${ENV_LABEL} — 파일당 ${perfile:-?}MB 까지만 받습니다(요청 본문 ${postmax:-?}MB). public/.user.ini 가 적용되지 않았습니다 — 도면·사진이 서버에 닿기 전에 잘립니다."
  {
    echo
    echo "**업로드 한도가 기본값입니다** — 파일당 \`${perfile:-?}MB\`, 요청 본문 \`${postmax:-?}MB\`"
    echo
    echo "\`public/.user.ini\` 에 적어 둔 64M/72M 이 적용되지 않았습니다. 이 상태에서는"
    echo "도면·사진이 서버에 닿기도 전에 잘리고, 화면에는 이유 없는 실패만 보입니다."
  } >> "${GITHUB_STEP_SUMMARY:-/dev/stdout}"
fi

echo "running=${running:-?} minutes_ago=${minutes:-?} last_beat_at=${last:-?} cache_store=${store:-?} app_url=${appurl:-?} domain_ok=${matches:-?} storage_durable=${durable:-?} document_disk=${dochub:-?} upload_per_file_mb=${perfile:-?} post_max_mb=${postmax:-?} user_ini=${userini:-?}"
