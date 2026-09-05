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
# 선택: REFERENCE_HOOK — 이미 잘 되는 다른 환경의 훅. 모양을 나란히 찍어 비교한다.
#       REFERENCE_LABEL — 그 훅의 이름(사람이 읽을 것).
#
# 비교가 왜 필요한가: 「토큰 구간이 짧으면 잘린 것」 이라고만 적어 두면, 정작 얼마가
# 짧은 것인지 아무도 모른다. 2026-09-05 에 실제로 그랬다 — 나손 훅이 302 로 튕기는데
# 토큰이 16자였고, 그것이 잘린 값인지 원래 그런 것인지 가릴 근거가 없었다.
# 잘 되는 훅의 길이를 옆에 놓으면 추측이 비교가 된다.
set -euo pipefail

shape() {
  local url="$1" label="$2"
  local scheme host path
  scheme=$(printf '%s' "$url" | sed -n 's#^\([a-zA-Z][a-zA-Z0-9+.-]*\)://.*#\1#p')
  host=$(printf '%s' "$url" | sed -e 's#^[a-zA-Z][a-zA-Z0-9+.-]*://##' -e 's#[/?].*##')
  path=$(printf '%s' "$url" | sed -e 's#^[a-zA-Z][a-zA-Z0-9+.-]*://[^/?]*##' -e 's#?.*##')

  echo "[$label]"
  echo "프로토콜: ${scheme:-없음(주소가 http(s):// 로 시작하지 않습니다)}"
  echo "호스트: ${host:-읽지 못함}"
  echo "전체 길이: ${#url}자"
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
}

# 마지막 경로 구간의 길이 = 토큰 길이.
token_len() {
  printf '%s' "$1" \
    | sed -e 's#^[a-zA-Z][a-zA-Z0-9+.-]*://[^/?]*##' -e 's#?.*##' \
    | awk -F/ '{print length($NF)}'
}

shape "${HOOK:-}" "이 환경의 훅"

path=$(printf '%s' "${HOOK:-}" | sed -e 's#^[a-zA-Z][a-zA-Z0-9+.-]*://[^/?]*##' -e 's#?.*##')

if [ -n "${REFERENCE_HOOK:-}" ]; then
  shape "$REFERENCE_HOOK" "${REFERENCE_LABEL:-이미 잘 되는 훅} (비교용)"

  mine=$(token_len "${HOOK:-}")
  theirs=$(token_len "$REFERENCE_HOOK")
  echo "토큰 길이: 이 환경 ${mine}자 / ${REFERENCE_LABEL:-잘 되는 훅} ${theirs}자"
  if [ "$mine" -lt "$theirs" ]; then
    echo "  ✗ 이 환경의 토큰이 더 짧습니다 — 입력칸에서 드래그로 복사해 잘린 값입니다."
    echo "    Laravel Cloud 의 Deploy hook 옆 <b>복사 버튼</b>으로 다시 복사해 시크릿을 덮어쓰세요."
  else
    # 길이가 같은데 튕겼다면 남는 이유는 셋뿐이다. 셋 다 처방이 하나라서 그것만 말한다 —
    # 어느 쪽인지 가리려고 왕복하는 것보다 재생성 한 번이 빠르고 확실하다.
    echo "  ✗ 길이는 잘 되는 훅과 같습니다 — <b>잘려서 생긴 문제가 아닙니다.</b>"
    echo "    302 는 «Laravel Cloud 가 이 주소를 모른다» 는 뜻이고, 남는 이유는 셋입니다:"
    echo "      · 훅 토글이 꺼졌다   · 훅이 재발급됐다   · 다른 값이 들어갔다"
    echo "    셋 다 처방은 하나입니다 — 그 환경의 Deploy hook 을 <b>재생성(🔄)</b>한 뒤"
    echo "    <b>복사 버튼(📋)</b>으로 복사해 시크릿을 덮어쓰세요. 지금 값은 어차피 안 통하므로 잃을 것이 없습니다."
  fi
  echo
fi

echo "판별:"
case "$path" in
  *projects*|*environments*|*settings*|*dashboard*)
    echo "  ✗ 경로에 대시보드 화면 이름이 들어 있습니다 — 브라우저 주소창 URL 입니다." ;;
  *hook*|*deploy*)
    echo "  ~ 훅 주소 형태는 맞습니다. 그래도 튕겼다면 값이 잘렸거나 재발급된 것입니다." ;;
  *)
    echo "  ? 알려진 어느 형태와도 다릅니다." ;;
esac
