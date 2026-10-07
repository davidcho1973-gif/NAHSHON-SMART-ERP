#!/usr/bin/env bash
# Source only in a disposable local checkout. No deployment/provider environment is inherited.
export APP_ENV=testing APP_DEBUG=false APP_URL=http://127.0.0.1:8765
# Keep the per-run key in inherited process memory, never in logs or a file.
# A caller may have tracing enabled; do not restore it while key material exists.
set +x
if ! APP_KEY="$(php -r 'echo "base64:".base64_encode(random_bytes(32));')"; then
    return 1 2>/dev/null || exit 1
fi
export APP_KEY
export ERP_BROWSER_SYNTHETIC=1 ERP_BROWSER_BASE_URL=http://127.0.0.1:8765
export DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432
export DB_DATABASE=erp_assistant_browser_test DB_USERNAME=postgres DB_PASSWORD=postgres DB_URL=''
export SESSION_DRIVER=file SESSION_SECURE_COOKIE=false CACHE_STORE=file
export QUEUE_CONNECTION=database MAIL_MAILER=array BROADCAST_CONNECTION=log
export FILESYSTEM_DISK=local DOCUMENT_STORAGE_DISK=local BCRYPT_ROUNDS=4
export SNAP_AUTOLOGIN=false AI_ASSISTANT_ENABLED=true
export AI_ASSISTANT_MUTATIONS_ENABLED=true AI_ASSISTANT_CHECKS_ENABLED=true
export OPENAI_API_KEY='' GEMINI_API_KEY='' ANTHROPIC_API_KEY=''
export GOOGLE_CLIENT_ID='' GOOGLE_CLIENT_SECRET='' GOOGLE_REDIRECT_URI=''
export MICROSOFT_MAIL_CLIENT_ID='' MICROSOFT_MAIL_CLIENT_SECRET=''
export GRAPH_MAIL_CLIENT_ID='' GRAPH_MAIL_CLIENT_SECRET=''
export POSTMARK_API_KEY='' RESEND_API_KEY='' SLACK_BOT_USER_OAUTH_TOKEN=''
export AWS_ACCESS_KEY_ID='' AWS_SECRET_ACCESS_KEY='' AWS_SESSION_TOKEN='' AWS_BUCKET=''
export TELEGRAM_BOT_TOKEN='' VAPID_PUBLIC_KEY='' VAPID_PRIVATE_KEY=''
