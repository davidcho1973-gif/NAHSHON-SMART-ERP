<?php

namespace App\Services\Kakao;

use Illuminate\Support\Facades\Http;

/** Official template-only Alimtalk API. Acknowledgement is not delivery confirmation. */
class SolapiAlimtalk
{
    public function readiness(): array
    {
        $missing = [];
        foreach (['api_key', 'api_secret', 'channel_id'] as $key) {
            if (! trim((string) config('kakao.'.$key))) {
                $missing[] = $key;
            }
        }
        $url = (string) config('kakao.base_url');
        if (! filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https'
            || parse_url($url, PHP_URL_USER) || parse_url($url, PHP_URL_QUERY) || parse_url($url, PHP_URL_FRAGMENT)
            || ! in_array(parse_url($url, PHP_URL_PATH), [null, '', '/'], true)) {
            $missing[] = 'https_app_url';
        }
        foreach (['clock_in', 'clock_out', 'daily_report'] as $kind) {
            if (! trim((string) config('kakao.templates.'.$kind))) {
                $missing[] = 'template_'.$kind;
            }
        }
        if (config('kakao.confirmed_countries', []) === []) {
            $missing[] = 'confirmed_countries';
        }

        return ['enabled' => (bool) config('kakao.enabled'), 'configured' => $missing === [], 'missing' => $missing];
    }

    public function link(string $kind): string
    {
        return rtrim((string) config('kakao.base_url'), '/').'/attendance-app'.($kind === 'daily_report' ? '/ops-room' : '');
    }

    public function send(string $phone, string $kind, array $variables): array
    {
        $ready = $this->readiness();
        $country = str_starts_with($phone, '+1') ? '1' : (str_starts_with($phone, '+82') ? '82' : '');
        if (! $ready['enabled'] || ! $ready['configured'] || ! isset(config('kakao.templates')[$kind])
            || ! in_array($country, config('kakao.confirmed_countries'), true)) {
            return ['status' => 'blocked', 'reason' => 'provider_not_ready'];
        }
        $date = gmdate('Y-m-d\TH:i:s\Z');
        $salt = bin2hex(random_bytes(16));
        $signature = hash_hmac('sha256', $date.$salt, config('kakao.api_secret'));
        try {
            // No retry: a timeout can follow acceptance. SMS fallback is explicitly disabled.
            $response = Http::withHeaders(['Authorization' => 'HMAC-SHA256 apiKey='.config('kakao.api_key').", date={$date}, salt={$salt}, signature={$signature}"])
                ->acceptJson()->connectTimeout(5)->timeout(15)->withoutRedirecting()
                ->post('https://api.solapi.com/messages/v4/send-many/detail', [
                    'messages' => [[
                        'to' => ($country === '82' ? '0' : '').substr($phone, strlen($country) + 1), 'country' => $country, 'type' => 'ATA',
                        'kakaoOptions' => [
                            'pfId' => config('kakao.channel_id'), 'templateId' => config('kakao.templates.'.$kind),
                            'disableSms' => true, 'variables' => $variables,
                        ],
                    ]],
                    'allowDuplicates' => false, 'showMessageList' => true,
                ]);
            if (! $response->successful()) {
                return ['status' => $response->serverError() ? 'unknown' : 'failed', 'reason' => 'http_'.$response->status()];
            }
            $body = $response->json();
            $message = collect($body['messageList'] ?? [])->first();
            $code = (string) ($message['statusCode'] ?? '');
            if (! empty($body['failedMessageList'])) {
                return ['status' => 'failed', 'reason' => 'provider_rejected'];
            }
            if (! is_array($message) || empty($message['messageId'])) {
                return ['status' => 'unknown', 'reason' => 'missing_acknowledgement'];
            }

            return [
                'status' => match ($code) {
                    '2000' => 'accepted', '4000' => 'delivered', default => 'unknown'
                },
                'message_id' => substr($message['messageId'], 0, 120),
                'group_id' => substr((string) ($body['groupInfo']['groupId'] ?? ''), 0, 120),
                'provider_code' => substr($code, 0, 40),
                'reason' => 'check_provider_delivery_log',
            ];
        } catch (\Throwable) {
            // Never log the exception body: it can contain recipient data or Authorization headers.
            return ['status' => 'unknown', 'reason' => 'transport_or_response_error'];
        }
    }
}
