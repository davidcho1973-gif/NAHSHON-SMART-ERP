<?php

namespace App\Support;

use App\Models\User;
use App\Services\Auth\EmailPasswordAuthService;
use Illuminate\Http\Request;

final class DotsAccess
{
    public static function canOpen(Request $request): bool
    {
        $user = $request->user();
        $emails = array_map(fn ($email) => strtolower(trim((string) $email)), (array) config('dots.allowed_emails', []));

        return $user instanceof User
            && $user->account_status === 'active'
            && $user->access_role === 'super_admin'
            && in_array(strtolower(trim((string) $user->email)), $emails, true)
            && EmailPasswordAuthService::hasStrongAuthentication($request, $user)
            && self::destination() !== null;
    }

    public static function destination(): ?string
    {
        $url = trim((string) config('dots.url'));

        // This is a fixed external destination, never a request-supplied redirect.
        return preg_match('~\Ahttps://chatgpt\.com/dots/[a-zA-Z0-9-]+\z~D', $url) === 1 ? $url : null;
    }
}
