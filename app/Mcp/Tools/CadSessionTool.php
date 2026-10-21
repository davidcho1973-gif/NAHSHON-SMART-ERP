<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Services\Drawings\CadAiSchema;
use App\Services\Drawings\CadAiSessionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class CadSessionTool extends Tool
{
    public function __construct(string $name)
    {
        $this->name = $name;
        $this->description = match ($name) {
            'list_cad_sessions' => 'List only CAD browser sessions this account explicitly shared. No global ERP data or raw DXF is available.',
            'read_cad_context' => 'Read paginated untrusted geometry/text explicitly shared by the CAD browser. Follow nextOffset and recheck documentHash. Source may include unsaved browser edits.',
            'calculate_cad_quantities' => 'Calculate planar lengths/areas deterministically from the shared browser geometry. Excludes unsupported entities and annotations. Never approves work or claim quantities; units cannot be guessed.',
            'submit_cad_actions' => 'Queue typed CAD actions for the explicitly shared browser session only when its owner allows editing. Requires current full-document hash and stable retry key. Queued is not applied: poll cad_command_status. No file save, ERP record, quantity approval, code, shell, URL or arbitrary operation is available.',
            'cad_command_status' => 'Read the browser acknowledgement for a command submitted by this same OAuth client. pending is not applied.',
            default => throw new \InvalidArgumentException('Unknown CAD session tool.'),
        };
    }

    public function toArray(): array
    {
        $data = parent::toArray();
        $data['annotations'] = ['readOnlyHint' => $this->name !== 'submit_cad_actions', 'destructiveHint' => $this->name === 'submit_cad_actions', 'idempotentHint' => true, 'openWorldHint' => false];
        $data['securitySchemes'] = [['type' => 'oauth2', 'scopes' => ['cad:session']]];
        $data['_meta']['securitySchemes'] = $data['securitySchemes'];
        $data['inputSchema']['additionalProperties'] = false;
        if ($this->name === 'submit_cad_actions') {
            $data['inputSchema']['properties']['actions'] = CadAiSchema::actionJsonSchema();
        }

        return $data;
    }

    public function handle(Request $request): Response
    {
        try {
            $actor = $request->user();
            abort_unless($actor instanceof User, 403);
            $input = $request->all();
            $allowed = match ($this->name) {
                'list_cad_sessions' => [], 'read_cad_context' => ['sessionId', 'offset', 'limit', 'entityIds', 'layer'],
                'calculate_cad_quantities' => ['sessionId', 'entityIds', 'layer', 'unit'],
                'submit_cad_actions' => ['sessionId', 'documentHash', 'idempotencyKey', 'actions'], 'cad_command_status' => ['sessionId', 'commandId'],
            };
            app(CadAiSchema::class)->keys($input, $allowed);
            if ($this->name !== 'list_cad_sessions') {
                $request->validate(['sessionId' => 'required|uuid']);
            }
            $service = app(CadAiSessionService::class);
            $client = (string) request()->attributes->get('cad_mcp_client_id', '');
            $result = match ($this->name) {
                'list_cad_sessions' => $service->list($actor),
                'read_cad_context' => $service->read($actor, $input['sessionId'], array_diff_key($input, ['sessionId' => true])),
                'calculate_cad_quantities' => $service->quantities($actor, $input['sessionId'], array_intersect_key($input, ['entityIds' => true, 'layer' => true]), $input['unit'] ?? null),
                'submit_cad_actions' => $this->submit($request, $service, $actor, $client, $input),
                'cad_command_status' => $this->status($request, $service, $actor, $client, $input),
            };
            abort_if(strlen(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) > 262144, 413);

            return Response::json($result);
        } catch (ValidationException $exception) {
            return Response::error('Invalid CAD arguments. '.$exception->validator->errors()->first());
        } catch (HttpExceptionInterface $exception) {
            return Response::error(match ($exception->getStatusCode()) {
                403, 404 => 'CAD sharing, drawing or OAuth connection is unavailable under current permissions.',
                409 => 'CAD context or command state changed. Read current context and command status before retrying with a new key.',
                410 => 'CAD sharing session or command expired. Ask the browser owner to share again.',
                413 => 'CAD response is too large. Request fewer shared entities.',
                default => 'CAD session request is unavailable.',
            });
        } catch (\Throwable) {
            // Deliberately omit all payloads, tokens, SQL, paths and exception messages from logs and responses.
            return Response::error('CAD session request failed. No ERP drawing file or business quantity was changed.');
        }
    }

    private function submit(Request $request, CadAiSessionService $service, User $actor, string $client, array $input): array
    {
        $request->validate(['documentHash' => 'required|string|size:64', 'idempotencyKey' => 'required|string|max:100', 'actions' => 'required|array']);

        return $service->submit($actor, $client, $input['sessionId'], $input['documentHash'], $input['idempotencyKey'], $input['actions'], (string) request()->attributes->get('cad_mcp_token_family', ''));
    }

    private function status(Request $request, CadAiSessionService $service, User $actor, string $client, array $input): array
    {
        $request->validate(['commandId' => 'required|uuid']);

        return $service->commandStatus($actor, $client, $input['sessionId'], $input['commandId']);
    }

    public function schema(JsonSchema $schema): array
    {
        if ($this->name === 'list_cad_sessions') {
            return [];
        }
        $fields = ['sessionId' => $schema->string()->required()->description('Session UUID explicitly shared by the same authenticated account.')];
        if (in_array($this->name, ['read_cad_context', 'calculate_cad_quantities'], true)) {
            $fields['entityIds'] = $schema->array()->items($schema->string())->max(1000)->description('Optional entity IDs from this shared context only.');
            $fields['layer'] = $schema->string()->max(100);
        }
        if ($this->name === 'read_cad_context') {
            $fields += ['offset' => $schema->integer()->min(0)->max(5000), 'limit' => $schema->integer()->min(1)->max(100)];
        }
        if ($this->name === 'calculate_cad_quantities') {
            $fields['unit'] = $schema->string()->enum(CadAiSchema::UNITS);
        }
        if ($this->name === 'submit_cad_actions') {
            $fields += ['documentHash' => $schema->string()->required()->description('Exact SHA256 hash of the current complete browser document.'),
                'idempotencyKey' => $schema->string()->max(100)->required(), 'actions' => $schema->array()->items($schema->object())->min(1)->max(100)->required()];
        }
        if ($this->name === 'cad_command_status') {
            $fields['commandId'] = $schema->string()->required();
        }

        return $fields;
    }
}
