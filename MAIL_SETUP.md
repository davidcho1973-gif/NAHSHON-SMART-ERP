# Mail Setup

The applicant email button sends real email only when Laravel Cloud has a real mail provider configured.

## Recommended Simple Setup

Use SMTP first. Gmail SMTP is acceptable for testing, but a domain mail provider such as Google Workspace, Postmark, Resend SMTP, SendGrid, or Mailgun is better for production deliverability.

Set these variables in Laravel Cloud:

```env
MAIL_MAILER=smtp
MAIL_SCHEME=tls
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your-google-email@gmail.com
MAIL_PASSWORD=your-google-app-password
MAIL_FROM_ADDRESS=your-google-email@gmail.com
MAIL_FROM_NAME="NAHSHON MEP"
```

For Gmail, `MAIL_PASSWORD` must be a Google app password, not the normal Gmail login password.

## Laravel Cloud Steps

1. Open the Laravel Cloud project.
2. Go to Environment or Variables.
3. Add the `MAIL_*` values above.
4. Redeploy the application.
5. Open Admin > Applicants.
6. Use `지원서 링크로 보내기` with a test email.

If `MAIL_MAILER` is still `log` or the SMTP host is still `127.0.0.1`, the ERP will create the application link and open an email draft instead of stopping on a failure.

## Fallback Behavior

If real SMTP is not configured, the applicant email action still creates the application link and opens a `mailto:` email draft. The draft includes the applicant email, subject, and application link. The admin only needs to press Send in their email app.
