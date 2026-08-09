#!/usr/bin/env bash
#
# 훅 호출이 실패했을 때 "무엇이 들어 있는지" 를 값 노출 없이 되짚어 준다.
#
# 응답 코드만 보고 원인을 추측하면 왕복이 길어진다. 호스트·경로 구간·길이만 있어도
# 대시보드 화면 주소인지, 훅 주소인지, 토큰이 잘렸는지가 갈린다.
#
# 토큰이 새지 않도록 소문자와 하이픈뿐인 짧은 구간만 그대로 찍고,
# 숫자나 대문자가 하나라도 섞이면 길이만 남긴다. 값 전체는 절대 출력하지 않는다.
#
# 필요한 환경변수: HOOK
set -euo pipefail

scheme=$(printf '%s' "${HOOK:-}" | sed -n 's#^\([a-zA-Z][a-zA-Z0-9+.-]*\)://.*#\1#p')
host=$(printf '%s' "${HOOK:-}" | sed -e 's#^[a-zA-Z][a-zA-Z0-9+.-]*://##' -e 's#[/?].*##')
path=$(printf '%s' "${HOOK:-}" | sed -e 's#^[a-zA-Z][a-zA-Z0-9+.-]*://[^/?]*##' -e 's#?.*##')

echo "프로토콜: ${scheme:-없음(주소가 http(s):// 로 시작하지 않습니다)}"
echo "호스트: ${host:-읽지 못함}"
echo "전체 길이: ${#HOOK}자"
echo "경로 구간:"

# `|| [ -n "$seg" ]` 가 없으면 개행으로 끝나지 않는 마지막 구간을 read 가 버린다.
# 하필 그 마지막 구간이 토큰이라, 길이가 얼마인지가 진단의 핵심이다.
printf '%s' "$path" | tr '/' '\n' | while IFS= read -r seg || [ -n "$seg" ]; do
  [ -z "$seg" ] && continue
  rest=$(printf '%s' "$seg" | tr -d 'a-z-')
  if [ -z "$rest" ] && [ "${#seg}" -le 20 ]; then
    echo "  /$seg"
  else
    echo "  /<${#seg}자>"
  fi
done

echo
echo "판별:"
case "$path" in
  *projects*|*environments*|*settings*|*dashboard*)
    echo "  ✗ 경로에 대시보드 화면 이름이 들어 있습니다 — 브라우저 주소창 URL 입니다." ;;
  *hook*|*deploy*)
    echo "  ~ 훅 주소 형태는 맞습니다. 토큰 구간이 짧으면 입력칸에서 잘린 값을 복사한 것입니다 — 복사 버튼을 쓰세요." ;;
  *)
    echo "  ? 알려진 어느 형태와도 다릅니다." ;;
esac
