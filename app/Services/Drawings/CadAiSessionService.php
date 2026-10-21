<?php

namespace App\Services\Drawings;

use App\Models\CadAiCommand;
use App\Models\CadAiSession;
use App\Models\CadDrawing;
use App\Models\User;
use App\Support\Mcp\CadMcpOAuth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** This queue changes only an explicitly shared browser document, never an ERP drawing file or claim quantity. */
class CadAiSessionService
{
    public function __construct(private readonly CadDrawingService $cad, private readonly CadAiSchema $schema) {}

    private function actor(User $user): User
    {
        $fresh = User::with('employee')->find($user->id);
        abort_unless($fresh && CadMcpOAuth::eligible($fresh), 403, 'CAD AI account access is unavailable.');
        $this->cad->gate($fresh);

        return $fresh;
    }

    public function create(User $user, int $drawingId, int $revision, array $context, bool $allowEdits): array
    {
        $user = $this->actor($user);
        $drawing = CadDrawing::with('site')->findOrFail($drawingId);
        $this->cad->access($user, $drawing, false, true);
        if ($allowEdits) {
            $this->cad->access($user, $drawing, true);
        }
        abort_unless($drawing->revisions()->where('revision', $revision)->exists(), 404);
        $context = $this->schema->context($context);
        $this->prune($user->id);
        abort_if(CadAiSession::where('user_id', $user->id)->whereNull('stopped_at')->where('expires_at', '>', now()->toIso8601String())->count() >= 5, 429, 'Stop an existing CAD sharing session before opening another.');
        $token = bin2hex(random_bytes(32));
        $session = CadAiSession::create(['uuid' => (string) Str::uuid(), 'user_id' => $user->id,
            'company_id' => $drawing->company_id, 'site_id' => $drawing->site_id, 'drawing_id' => $drawing->id,
            'revision' => $revision, 'editor_token_hash' => hash('sha256', $token), 'context' => $context,
            'allow_edits' => $allowEdits, 'expires_at' => now()->addMinutes(15)]);

        return ['sessionId' => $session->uuid, 'editorToken' => $token, 'expiresAt' => $session->expires_at->toIso8601String(), 'documentHash' => $context['documentHash']];
    }

    public function session(User $user, string $uuid, ?string $editorToken = null, bool $browser = false): CadAiSession
    {
        $user = $this->actor($user);
        $session = CadAiSession::with('drawing.site')->where('uuid', $uuid)->where('user_id', $user->id)->firstOrFail();
        if ($session->stopped_at || $session->expires_at->lte(now()) || ! $session->context) {
            $this->redact($session);
            abort(410, 'CAD sharing session expired or was stopped. Share the browser context again.');
        }
        if ($browser) {
            abort_unless(is_string($editorToken) && strlen($editorToken) === 64 && $session->editor_token_hash
                && hash_equals($session->editor_token_hash, hash('sha256', $editorToken)), 403, 'The active CAD browser token is required.');
        }
        try {
            abort_unless($session->drawing && (int) $session->site_id === (int) $session->drawing->site_id && (int) $session->company_id === (int) $session->drawing->company_id, 403);
            $this->cad->access($user, $session->drawing, false, true);
        } catch (HttpExceptionInterface $exception) {
            $this->redact($session);
            throw $exception;
        }

        return $session;
    }

    private function redact(CadAiSession $session): void
    {
        DB::transaction(function () use ($session): void {
            $session->update(['context' => null, 'editor_token_hash' => null, 'allow_edits' => false, 'expires_at' => now(), 'stopped_at' => $session->stopped_at ?? now()]);
            $session->commands()->where('status', 'pending')->update(['status' => 'rejected']);
            $session->commands()->update(['actions' => null, 'result' => null]);
        });
    }

    private function assertLive(CadAiSession $session): void
    {
        abort_if($session->stopped_at || $session->expires_at->lte(now()) || ! $session->context, 410, 'CAD sharing session is no longer active.');
    }

    public function update(User $user, string $uuid, string $token, array $context, bool $allowEdits): array
    {
        $session = $this->session($user, $uuid, $token, true);
        $user = $this->actor($user);
        $this->cad->access($user, $session->drawing, $allowEdits);
        $context = $this->schema->context($context);

        return DB::transaction(function () use ($session, $context, $allowEdits): array {
            $session = CadAiSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            $this->assertLive($session);
            $session->update(['context' => $context, 'allow_edits' => $allowEdits, 'expires_at' => now()->addMinutes(15)]);
            $this->invalidatePending($session, $context['documentHash'], $allowEdits);

            return ['sessionId' => $session->uuid, 'expiresAt' => $session->expires_at->toIso8601String(), 'documentHash' => $context['documentHash'], 'allowEdits' => $allowEdits];
        });
    }

    private function invalidatePending(CadAiSession $session, string $hash, bool $allowEdits, ?int $except = null): void
    {
        $session->commands()->where('status', 'pending')->when($except, fn ($q) => $q->where('id', '!=', $except))
            ->when($allowEdits, fn ($q) => $q->where('document_hash', '!=', $hash))
            ->update(['status' => 'rejected', 'result' => json_encode(['message' => 'Browser context changed or editing permission was withdrawn.'])]);
    }

    public function stop(User $user, string $uuid, string $token): array
    {
        $session = $this->session($user, $uuid, $token, true);
        $this->redact($session);

        return ['stopped' => true];
    }

    public function commands(User $user, string $uuid, string $token): array
    {
        $session = $this->session($user, $uuid, $token, true);
        $this->expireCommands($session);
        // Revoked edit rights stop pending writes, even if browser has not sent its next heartbeat.
        if (! $session->allow_edits || ! $this->cad->canManage($this->actor($user))) {
            $this->invalidatePending($session, $session->context['documentHash'], false);
        }
        foreach ($session->commands()->where('status', 'pending')->get() as $command) {
            if (! $this->activeFamily($session->user_id, $command->oauth_client_id, $command->oauth_family_id)) {
                $command->update(['status' => 'rejected', 'actions' => null, 'result' => null]);
            }
        }

        return ['commands' => $session->commands()->where('status', 'pending')->orderBy('id')->limit(1)->get()->map(fn ($command) => [
            'id' => $command->uuid, 'documentHash' => $command->document_hash, 'actions' => $command->actions, 'status' => $command->status])->all(),
            'expiresAt' => $session->expires_at->toIso8601String()];
    }

    private function expireCommands(CadAiSession $session): void
    {
        $session->commands()->where('expires_at', '<=', now()->toIso8601String())->where('status', 'pending')->update(['status' => 'rejected', 'actions' => null, 'result' => null]);
    }

    public function submit(User $user, string $clientId, string $uuid, string $documentHash, string $key, array $actions, string $familyId): array
    {
        $this->clientId($clientId);
        $session = $this->session($user, $uuid);
        abort_unless($this->activeFamily($user->id, $clientId, $familyId), 403, 'CAD OAuth connection was revoked or expired.');
        $this->schema->hash($documentHash);
        if (! preg_match('/^[A-Za-z0-9_.:-]{1,100}$/D', $key)) {
            $this->schema->fail('Use a stable idempotencyKey of 1–100 ASCII letters, digits, dot, dash, colon or underscore.');
        }
        $this->cad->access($this->actor($user), $session->drawing, true);
        abort_unless($session->allow_edits, 403, 'The browser owner has not allowed editing.');

        return DB::transaction(function () use ($session, $clientId, $documentHash, $key, $actions, $familyId): array {
            $session = CadAiSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            $this->assertLive($session);
            $prior = $session->commands()->where('oauth_client_id', $clientId)->where('idempotency_key', $key)->first();
            if ($prior) {
                abort_if($prior->expires_at->lte(now()), 410, 'CAD command expired.');
                abort_unless($prior->oauth_family_id === $familyId, 409, 'Idempotency key belongs to another OAuth connection.');
                abort_unless($prior->document_hash === $documentHash && $this->canonical($prior->actions) === $this->canonical($actions), 409, 'Idempotency key was already used for a different CAD command.');

                return $this->commandRow($prior) + ['alreadyQueued' => true];
            }
            abort_unless($session->allow_edits, 403);
            abort_unless(hash_equals($session->context['documentHash'], $documentHash), 409, 'CAD document changed. Read the current shared context before submitting actions.');
            $actions = $this->schema->actions($actions, $session->context);
            $this->expireCommands($session);
            abort_if($session->commands()->where('status', 'pending')->exists(), 409, 'Wait for the pending CAD command result before queuing another.');
            $command = $session->commands()->create(['uuid' => (string) Str::uuid(), 'oauth_client_id' => $clientId, 'oauth_family_id' => $familyId, 'idempotency_key' => $key,
                'document_hash' => $documentHash, 'actions' => $actions, 'status' => 'pending', 'expires_at' => now()->addMinutes(2)->min($session->expires_at)]);

            return $this->commandRow($command) + ['queued' => true, 'applied' => false];
        });
    }

    private function clientId(string $client): void
    {
        abort_unless($client !== '' && strlen($client) <= 100 && preg_match('/^[A-Za-z0-9_.:-]+$/D', $client), 403, 'Authenticated CAD OAuth client is required.');
    }

    private function canonical(array $value): string
    {
        $normalize = function (array $data) use (&$normalize): array {
            if (! array_is_list($data)) {
                ksort($data);
            }
            foreach ($data as &$item) {
                if (is_array($item)) {
                    $item = $normalize($item);
                }
            }

            return $data;
        };

        return json_encode($normalize($value), JSON_THROW_ON_ERROR);
    }

    private function activeFamily(int $userId, string $clientId, string $familyId): bool
    {
        return Str::isUuid($familyId) && CadMcpOAuth::client($clientId)
            && DB::connection(config('passport.connection'))->table('oauth_erp_grants')->where('id', $familyId)->where('revoked', false)->exists()
            && Passport::token()->newQuery()->where('user_id', $userId)->where('client_id', $clientId)->where('family_id', $familyId)
                ->where('resource', CadMcpOAuth::resource())->where('issuer', CadMcpOAuth::issuer())->where('revoked', false)->where('expires_at', '>', now()->toIso8601String())->exists();
    }

    private function commandRow(CadAiCommand $command): array
    {
        return ['id' => $command->uuid, 'sessionId' => $command->session->uuid, 'status' => $command->status, 'documentHash' => $command->document_hash,
            'expiresAt' => $command->expires_at->toIso8601String(), 'result' => $command->result];
    }

    public function commandStatus(User $user, string $clientId, string $uuid, string $commandUuid): array
    {
        $this->clientId($clientId);
        $session = $this->session($user, $uuid);
        $this->expireCommands($session);
        $command = $session->commands()->where('uuid', $commandUuid)->where('oauth_client_id', $clientId)->firstOrFail();
        abort_if($command->expires_at->lte(now()), 410, 'CAD command expired.');
        abort_unless($this->activeFamily($user->id, $clientId, $command->oauth_family_id), 403);

        return $this->commandRow($command);
    }

    public function result(User $user, string $uuid, string $token, string $commandUuid, string $status, string $message, ?array $context): array
    {
        $session = $this->session($user, $uuid, $token, true);
        if (! in_array($status, ['applied', 'rejected', 'failed'], true) || mb_strlen($message) > 1000) {
            $this->schema->fail();
        }
        if ($context !== null) {
            $context = $this->schema->context($context);
        }
        if ($status === 'applied' && $context === null) {
            $this->schema->fail('An applied command requires the resulting browser context.');
        }

        return DB::transaction(function () use ($user, $session, $commandUuid, $status, $message, $context): array {
            $session = CadAiSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            $this->assertLive($session);
            $command = $session->commands()->where('uuid', $commandUuid)->lockForUpdate()->firstOrFail();
            abort_unless($this->activeFamily($session->user_id, $command->oauth_client_id, $command->oauth_family_id), 403, 'CAD OAuth connection was revoked or expired.');
            $result = ['message' => $message, 'documentHash' => $context['documentHash'] ?? $session->context['documentHash'], 'source' => 'current_browser_snapshot'];
            if ($command->status !== 'pending') {
                abort_unless($command->status === $status && $command->result === $result, 409, 'The command already has a different final result.');

                return $this->commandRow($command);
            }
            abort_if($command->expires_at->lte(now()), 410, 'CAD command expired before acknowledgement.');
            if ($status === 'applied') {
                $this->cad->access($this->actor($user), $session->drawing, true);
                abort_unless($session->allow_edits && hash_equals($session->context['documentHash'], $command->document_hash), 409, 'CAD command is stale or editing was disabled.');
                $session->update(['context' => $context, 'expires_at' => now()->addMinutes(15)]);
                $this->invalidatePending($session, $context['documentHash'], true, $command->id);
            }
            $command->update(['status' => $status, 'result' => $result]);

            return $this->commandRow($command);
        });
    }

    public function list(User $user): array
    {
        $user = $this->actor($user);
        $this->prune($user->id);
        $rows = [];
        foreach (CadAiSession::where('user_id', $user->id)->whereNull('stopped_at')->where('expires_at', '>', now()->toIso8601String())->orderByDesc('id')->limit(5)->get() as $candidate) {
            try {
                $session = $this->session($user, $candidate->uuid);
                $rows[] = ['sessionId' => $session->uuid, 'drawingId' => $session->drawing_id, 'revision' => $session->revision,
                    'documentHash' => $session->context['documentHash'], 'allowEdits' => $session->allow_edits && $this->cad->canManage($user),
                    'scope' => $session->context['scope'], 'entityCount' => count($session->context['entities']), 'expiresAt' => $session->expires_at->toIso8601String(), 'source' => 'current_browser_snapshot'];
            } catch (HttpExceptionInterface) {
                continue;
            }
        }

        return ['sessions' => $rows, 'source' => 'explicitly_shared_current_browser_context_only'];
    }

    public function read(User $user, string $uuid, array $filters = []): array
    {
        $session = $this->session($user, $uuid);
        $this->schema->keys($filters, ['offset', 'limit', 'entityIds', 'layer']);
        $offset = $filters['offset'] ?? 0;
        $limit = $filters['limit'] ?? 50;
        if (! is_int($offset) || $offset < 0 || $offset > 5000 || ! is_int($limit) || $limit < 1 || $limit > 100) {
            $this->schema->fail();
        }
        $entities = $this->filtered($session->context, $filters);
        $page = array_slice($entities, $offset, $limit);
        $result = ['sessionId' => $session->uuid, 'source' => 'current_browser_snapshot', 'untrustedData' => true,
            'documentHash' => $session->context['documentHash'], 'unit' => $session->context['unit'], 'scope' => $session->context['scope'],
            'entities' => $page, 'layers' => $session->context['layers'], 'totalEntities' => count($entities), 'sharedEntityCount' => count($session->context['entities']),
            'nextOffset' => $offset + count($page) < count($entities) ? $offset + count($page) : null, 'allowEdits' => $session->allow_edits && $this->cad->canManage($this->actor($user))];
        // Large individual records are supported by asking for fewer entities, never silently truncated.
        if (strlen(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) > 262144) {
            $this->schema->fail('Requested CAD context page exceeds 256 KiB. Reduce limit.');
        }

        return $result;
    }

    private function filtered(array $context, array $filters): array
    {
        $entities = $context['entities'];
        if (isset($filters['entityIds'])) {
            $ids = $filters['entityIds'];
            if (! is_array($ids) || ! array_is_list($ids) || count($ids) > 1000 || count(array_filter($ids, 'is_string')) !== count($ids) || array_diff($ids, array_column($entities, 'id'))) {
                $this->schema->fail('Entity IDs must come from the current shared CAD context.');
            }
            $entities = array_values(array_filter($entities, fn ($entity) => in_array($entity['id'], $ids, true)));
        }
        if (isset($filters['layer'])) {
            if (! is_string($filters['layer']) || strlen($filters['layer']) > 400) {
                $this->schema->fail();
            }
            $entities = array_values(array_filter($entities, fn ($entity) => $entity['layer'] === $filters['layer']));
        }

        return $entities;
    }

    public function quantities(User $user, string $uuid, array $filters = [], ?string $unit = null): array
    {
        $session = $this->session($user, $uuid);
        $this->schema->keys($filters, ['entityIds', 'layer']);
        $entities = $this->filtered($session->context, $filters);

        return app(CadAiQuantities::class)->calculate($entities, $session->context['unit'], $unit ?? $session->context['unit'])
            + ['documentHash' => $session->context['documentHash'], 'scope' => $session->context['scope'], 'source' => 'deterministic_geometry_from_current_browser_snapshot', 'notVerifiedWork' => true];
    }

    public function prune(?int $userId = null): int
    {
        $count = 0;
        CadAiSession::whereNull('stopped_at')->where('expires_at', '<=', now()->toIso8601String())->when($userId, fn ($q) => $q->where('user_id', $userId))->chunkById(100, function ($sessions) use (&$count): void {
            foreach ($sessions as $session) {
                $this->redact($session);
                $count++;
            }
        });
        CadAiCommand::where('expires_at', '<=', now()->toIso8601String())->when($userId, fn ($q) => $q->whereHas('session', fn ($s) => $s->where('user_id', $userId)))
            ->update(['actions' => null, 'result' => null]);

        return $count;
    }
}
