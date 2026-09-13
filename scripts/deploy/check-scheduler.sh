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

# 이름만으로 찾으면 JSON 앞쪽의 같은 이름을 집는다 — "pending" 은 마이그레이션 블록에도,
# "message" 는 네 블록에 있다. 중첩 경로를 지정해 그 자리의 값만 읽는다.
json() {
  printf '%s' "$body" | python3 -c '
import json, sys
try:
    node = json.load(sys.stdin)
except Exception:
    sys.exit(0)
for key in sys.argv[1].split("."):
    node = node.get(key) if isinstance(node, dict) else None
    if node is None:
        break
if isinstance(node, bool):
    print("true" if node else "false")
elif node is not None:
    print(node)
' "$1" 2>/dev/null || true
  # 읽기가 실패해도 «값 없음» 으로 끝낸다. set -e 아래에서 이 함수가 실패하면
  # 경고만 하기로 한 이 단계가 배포를 빨갛게 만든다 — 이 파일의 첫 줄에 적어 둔
  # 약속(«배포를 실패시키지 않는다»)을 진단 도구가 스스로 깨는 셈이다.
}

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

# 큐 일꾼이 돌고 있는가.
#
# 문서 AI 분석은 뒤에서 도는 일꾼이 처리하는데, 그 일꾼은 코드가 아니라 배포 환경의
# 프로세스다. 안 만들면 문서가 「읽는 중」에서 영원히 멈추는데 화면은 멀쩡하다 —
# 2026-09-06 나손에서 85건이 그렇게 쌓여 있었고 아무도 몰랐다.
q_working=$(json queue.working)
q_pending=$(json queue.pending)
q_oldest=$(json queue.oldest_pending_minutes)
q_failed=$(json queue.failed)

if [ "$q_working" = "false" ]; then
  echo "::warning title=큐 일꾼이 멈춤::${ENV_LABEL} — ${q_pending:-?}건이 밀려 있고 가장 오래된 것이 ${q_oldest:-?}분째 기다립니다. 큐 일꾼(queue:work)이 돌고 있지 않아 문서 AI 분석이 전부 멈춰 있습니다."
  {
    echo
    echo "**큐 일꾼이 돌고 있지 않습니다** — 밀린 작업 \`${q_pending:-?}건\`, 가장 오래된 것 \`${q_oldest:-?}분\`째"
    echo
    echo "문서를 올려도 AI 분석이 돌지 않고 「읽는 중」에서 멈춥니다. 「물어보기」도 답하지 못합니다."
    echo "Laravel Cloud 에서 백그라운드 프로세스를 만들고 Scale to Zero 를 꺼 주세요."
  } >> "${GITHUB_STEP_SUMMARY:-/dev/stdout}"
fi

if [ -n "${q_failed:-}" ] && [ "$q_failed" -gt 0 ] 2>/dev/null; then
  echo "::warning title=실패한 작업 있음::${ENV_LABEL} — 큐에서 ${q_failed}건이 실패로 빠져 있습니다. 그 문서들은 다시 돌리지 않으면 영영 분석되지 않습니다."
fi

# 메일이 진짜로 나가는 상태인가. 진단은 이미 /build-version 에 있었는데 배포 로그에
# 찍히지 않아, 「보고서 메일이 왜 안 오지」를 아무도 배포 화면에서 볼 수 없었다.
# 라라벨 기본 메일러는 log 라서 설정이 없어도 발송이 예외 없이 «성공» 한다 —
# 화면에는 "발송했습니다" 가 뜨고 로그 파일에만 쌓인다.
mail_ready=$(json mail.ready)
mail_scheme_ok=$(json mail.scheme_ok)
mailer=$(json mail.mailer)
mail_scheme=$(json mail.scheme)
mail_recipients=$(json mail.daily_report_recipients)

if [ "$mail_ready" = "false" ]; then
  if [ "$mail_scheme_ok" = "false" ]; then
    echo "::warning title=메일 설정 오류::${ENV_LABEL} — MAIL_SCHEME 값 «${mail_scheme:-?}» 은 쓸 수 없습니다. 다른 설정이 다 맞아도 한 통도 안 나갑니다(587 포트면 비우고, 465 포트면 smtps)."
  else
    echo "::warning title=메일 준비 안 됨::${ENV_LABEL} — 메일러 «${mailer:-?}» 로는 지금 발송되지 않습니다. 보고서가 조용히 로그에만 쌓입니다."
  fi
  {
    echo
    echo "**메일이 나가지 않는 상태입니다** — 메일러 \`${mailer:-?}\` · MAIL_SCHEME \`${mail_scheme:-?}\`"
    echo
    echo "라라벨 기본 메일러는 \`log\` 라서 설정이 없어도 발송이 «성공» 합니다."
    echo "화면에는 「발송했습니다」가 뜨고 로그 파일에만 쌓입니다 — 받는 사람은 영원히 못 받습니다."
  } >> "${GITHUB_STEP_SUMMARY:-/dev/stdout}"
elif [ "${mail_recipients:-0}" = "0" ]; then
  echo "::warning title=받는 사람이 없음::${ENV_LABEL} — 메일 설정은 정상인데 일일보고 수신자가 0명입니다. 발송은 성공하고 아무 데도 안 갑니다."
fi

echo "running=${running:-?} minutes_ago=${minutes:-?} last_beat_at=${last:-?} cache_store=${store:-?} app_url=${appurl:-?} domain_ok=${matches:-?} storage_durable=${durable:-?} document_disk=${dochub:-?} upload_per_file_mb=${perfile:-?} post_max_mb=${postmax:-?} user_ini=${userini:-?} queue_working=${q_working:-?} queue_pending=${q_pending:-?} queue_oldest_min=${q_oldest:-?} queue_failed=${q_failed:-?} mail_ready=${mail_ready:-?} mail_recipients=${mail_recipients:-?}"
