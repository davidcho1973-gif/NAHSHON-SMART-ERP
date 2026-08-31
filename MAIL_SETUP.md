# 메일 설정 (Mail Setup)

ERP 가 실제로 메일을 보내려면 Laravel Cloud 에 메일 제공자를 설정해야 합니다.
설정하기 전까지 발송 버튼은 <b>보낸 척하지 않고</b> 메일앱을 여는 `mailto:` 로 대체됩니다.

> **주의 — 라라벨의 기본 메일러는 `log` 입니다.**
> 설정이 없어도 발송은 예외 없이 "성공" 하고, 메일은 로그 파일에만 쌓입니다.
> 그래서 화면에는 "발송했습니다" 가 뜨는데 받는 사람은 영원히 못 받습니다.
> 설정한 뒤에는 반드시 아래 **확인** 절차를 거치세요.

## Gmail (앱 비밀번호)

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your-account@gmail.com
MAIL_PASSWORD=your-16-char-app-password
MAIL_FROM_ADDRESS=your-account@gmail.com
MAIL_FROM_NAME="NAHSHON ERP"
```

### 반드시 지킬 것

- **`MAIL_SCHEME` 은 넣지 마세요.** 비워 두면 587 포트에서 STARTTLS 가 자동으로 걸립니다.
  값을 넣어야 한다면 허용되는 것은 `smtp` 와 `smtps` **둘뿐**입니다.
  `MAIL_SCHEME=tls` 는 흔한 오해인데, 넣는 순간 발송이 통째로 죽습니다:
  `The "tls" scheme is not supported; supported schemes for mailer "smtp" are: "smtp", "smtps".`
  더 나쁜 것은 <b>설정 점검이 초록불로 보인다</b>는 점입니다 — 값이 다 채워져 있으니까요.
  (465 포트를 쓴다면 `MAIL_SCHEME=smtps`.)
- **`MAIL_ENCRYPTION` 은 효과가 없습니다.** `config/mail.php` 에 그 키가 없습니다. 넣어도 무시됩니다.
- **`MAIL_FROM_ADDRESS` 를 `MAIL_USERNAME` 과 같은 주소로 맞추세요.** Gmail 은 인증한 계정과
  다른 주소로 보내는 것을 거절합니다.
- **`MAIL_PASSWORD` 는 Gmail 로그인 비밀번호가 아니라 앱 비밀번호(16자)** 입니다.
  구글 계정 → 보안 → 2단계 인증 → 앱 비밀번호에서 만듭니다. 표시될 때 들어가는 공백은 빼고 붙여넣으세요.

## Resend (도메인 발송 — 운영 권장)

Gmail SMTP 는 하루 발송 한도가 있고, 받는 쪽에서 스팸으로 분류될 확률이 높습니다.
일일 보고를 원청에 매일 보낼 것이라면 도메인 발송이 맞습니다.

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.resend.com
MAIL_PORT=587
MAIL_USERNAME=resend
MAIL_PASSWORD=re_xxxxxxxxxxxx
MAIL_FROM_ADDRESS=ops@your-domain.com
MAIL_FROM_NAME="NAHSHON ERP"
```

- `MAIL_FROM_ADDRESS` 의 도메인은 Resend 에서 **인증(SPF/DKIM)** 을 마친 도메인이어야 합니다.
  인증 전에는 발송이 거절되거나 전부 스팸으로 갑니다.
- `MAIL_MAILER=resend` 로 두는 방법도 있지만 그러면 `resend/resend-laravel` 패키지가
  설치돼 있어야 합니다. 위처럼 **SMTP 로 쓰면 패키지 없이 됩니다.**

## Laravel Cloud 절차

1. Laravel Cloud 프로젝트를 엽니다.
2. Environment(또는 Variables)로 갑니다.
3. 위 값을 넣습니다.
4. **반드시 재배포합니다.** 배포 스크립트가 설정을 캐시하므로, 값만 저장하고 재배포하지 않으면
   서버는 계속 옛 설정으로 돕니다.
5. 운영과 스테이징은 **별개 앱**이라 환경변수가 공유되지 않습니다. 둘 다 쓸 것이면 양쪽에 넣으세요.

## 확인 — 두 단계로

### 1단계 · 값이 들어갔는가

로그인 없이 열립니다:

```
https://<우리 주소>/build-version
```

`mail` 블록의 `message` 한 줄이 결론입니다. `scheme_ok` 가 `false` 면 `MAIL_SCHEME` 값이
잘못된 것이고, 그 상태로는 발송이 100% 실패합니다(다른 칸이 다 초록이어도).

### 2단계 · 진짜로 나가는가

값이 채워진 것과 실제로 나가는 것은 다릅니다 — 비밀번호가 틀렸는지, 포트가 막혔는지,
도메인 인증이 끝났는지는 한 통 보내 봐야 압니다.

**[조직 설정] → [메일 진단] → [나에게 테스트 메일 보내기]**

로그인한 본인 주소로 한 통이 갑니다. 실패하면 서버가 받은 오류 메시지를 그대로 보여 줍니다.

### 흔한 오류와 원인

| 오류 메시지에 보이는 것 | 실제 원인 |
|---|---|
| `"tls" scheme is not supported` | `MAIL_SCHEME=tls` — 지우세요 |
| `Failed to authenticate ... 535` | 비밀번호가 틀렸거나 앱 비밀번호가 아님 |
| `Connection could not be established` | 포트가 막혔거나 호스트 오타 |
| `550 ... not verified` / 전부 스팸함 | 발신 도메인 SPF/DKIM 미인증 |
| `Class "Resend" not found` | `MAIL_MAILER=resend` 인데 패키지 없음 → SMTP 방식으로 바꾸세요 |
| 오류는 없는데 안 옴 | `MAIL_MAILER=log` 이거나 `failover` 가 log 로 떨어짐 |

## 설정 전 동작 (폴백)

메일 설정이 없으면 발송 버튼은 실패하지 않고 `mailto:` 링크를 돌려줍니다.
사장님 메일앱이 열리고 수신자·제목·본문이 채워지며, [보내기]만 누르면 됩니다.
이력에는 `channel=mailto` 로 남아 <b>보낸 척하지 않습니다.</b>

다만 **정해진 시각 자동 발송(일일 계획서 08:30 / 마감 18:30)은 아무것도 하지 않습니다** —
그 자리에는 메일앱을 열어 줄 사람이 없기 때문입니다.
