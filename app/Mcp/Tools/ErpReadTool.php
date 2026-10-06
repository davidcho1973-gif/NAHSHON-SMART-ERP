<?php

namespace App\Mcp\Tools;

use App\Mcp\Read\ErpReadBoundary;
use App\Models\User;
use App\Support\Mcp\ErpMcpOAuth;
use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

abstract class ErpReadTool extends Tool
{
    public function toArray(): array
    {
        $result = parent::toArray();
        $result['annotations'] = ['readOnlyHint' => true, 'destructiveHint' => false,
            'idempotentHint' => true, 'openWorldHint' => false];
        $result['securitySchemes'] = [['type' => 'oauth2', 'scopes' => ['erp:read']]];
        $result['_meta']['securitySchemes'] = $result['securitySchemes'];
        $result['inputSchema']['additionalProperties'] = false;

        return $result;
    }

    protected function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && ErpMcpOAuth::eligible($user), 403);

        return $user;
    }

    protected function onlyArguments(Request $request, array $names): void
    {
        if (array_diff(array_keys($request->all()), $names) !== []) {
            throw ValidationException::withMessages(['arguments' => 'Unexpected argument.']);
        }
    }

    protected function read(Closure $read): Response
    {
        try {
            return app(ErpReadBoundary::class)->run($read);
        } catch (ValidationException) {
            return Response::error('Invalid ERP query arguments. Check the tool schema.');
        } catch (HttpExceptionInterface $exception) {
            return Response::error(match ($exception->getStatusCode()) {
                403, 404 => 'This record is unavailable or outside your current ERP permissions.',
                413 => 'Requested content exceeds the safe reading limit. Reduce limit or text_limit, or use the ERP viewer for large files.',
                default => 'The requested ERP read is unavailable.',
            });
        } catch (Throwable $exception) {
            // Do not expose SQL, filesystem paths, document contents, tokens or arguments.
            Log::warning('ERP MCP read failed', ['tool' => $this->name(), 'exception' => get_class($exception)]);

            return Response::error('ERP read failed. No business records were changed.');
        }
    }
}
