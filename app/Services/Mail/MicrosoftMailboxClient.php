<?php

namespace App\Services\Mail;

use App\Models\MailboxConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class MicrosoftMailboxClient
{
    private const GRAPH = 'https://graph.microsoft.com/v1.0';

    public function configured(): bool
    {
        return filled(config('services.microsoft_mail.client_id'))
            && filled(config('services.microsoft_mail.client_secret'));
    }

    public function authorizationUrl(string $state): string
    {
        $this->assertConfigured();
        $tenant = rawurlencode((string) config('services.microsoft_mail.tenant', 'organizations'));

        return "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/authorize?".http_build_query([
            'client_id' => config('services.microsoft_mail.client_id'),
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri(),
            'response_mode' => 'query',
            'scope' => config('services.microsoft_mail.scopes'),
            'state' => $state,
            'prompt' => 'select_account',
        ]);
    }

    /** @return array<string,mixed> */
    public function exchangeCode(string $code): array
    {
        return $this->token(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $this->redirectUri()]);
    }

    /** @return array<string,mixed> */
    public function profile(string $accessToken): array
    {
        return Http::withToken($accessToken)->acceptJson()->timeout(30)
            ->get(self::GRAPH.'/me', ['$select' => 'id,displayName,mail,userPrincipalName'])
            ->throw()->json();
    }

    public function request(MailboxConnection $connection): PendingRequest
    {
        $this->refreshWhenNeeded($connection);

        return Http::withToken((string) $connection->access_token)->acceptJson()->timeout(60)->retry(3, 500, throw: false);
    }

    public function refreshWhenNeeded(MailboxConnection $connection): void
    {
        if ($connection->token_expires_at && $connection->token_expires_at->isAfter(now()->addMinutes(3))) {
            return;
        }
        if (blank($connection->refresh_token)) {
            throw new RuntimeException('Microsoft 연결이 만료되었습니다. 이메일을 다시 연결해 주세요.');
        }
        $token = $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $connection->refresh_token]);
        $connection->forceFill([
            'access_token' => $token['access_token'],
            'refresh_token' => $token['refresh_token'] ?? $connection->refresh_token,
            'token_expires_at' => now()->addSeconds(max(60, (int) ($token['expires_in'] ?? 3600))),
            'status' => 'active',
        ])->save();
    }

    public function redirectUri(): string
    {
        return (string) (config('services.microsoft_mail.redirect') ?: route('email-ai.microsoft.callback'));
    }

    /** @return array<string,mixed> */
    private function token(array $fields): array
    {
        $this->assertConfigured();
        $tenant = rawurlencode((string) config('services.microsoft_mail.tenant', 'organizations'));
        $response = Http::asForm()->acceptJson()->timeout(30)->post(
            "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token",
            $fields + [
                'client_id' => config('services.microsoft_mail.client_id'),
                'client_secret' => config('services.microsoft_mail.client_secret'),
                'scope' => config('services.microsoft_mail.scopes'),
            ],
        );
        if ($response->failed()) {
            throw new RuntimeException(Str::limit((string) ($response->json('error_description') ?: 'Microsoft 인증에 실패했습니다.'), 500));
        }

        return $response->json();
    }

    private function assertConfigured(): void
    {
        if (! $this->configured()) {
            throw new RuntimeException('Microsoft 이메일 연결 환경변수가 아직 설정되지 않았습니다.');
        }
    }
}
