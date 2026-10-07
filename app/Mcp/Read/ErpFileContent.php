<?php

namespace App\Mcp\Read;

use Laravel\Mcp\Server\Concerns\HasMeta;
use Laravel\Mcp\Server\Contracts\Content;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Server\Tool;

/** Standard MCP embedded resource. Contains bytes, never a permanent/public storage URL. */
final class ErpFileContent implements Content
{
    use HasMeta;

    public function __construct(private readonly array $file) {}

    public function toTool(Tool $tool): array
    {
        return $this->toArray();
    }

    public function toPrompt(Prompt $prompt): array
    {
        return $this->toArray();
    }

    public function toResource(Resource $resource): array
    {
        return $this->toArray()['resource'];
    }

    public function toArray(): array
    {
        return $this->mergeMeta(['type' => 'resource', 'resource' => [
            'uri' => $this->file['uri'], 'mimeType' => $this->file['mime'], 'blob' => base64_encode($this->file['bytes']),
        ]]);
    }

    public function __toString(): string
    {
        return $this->file['name'];
    }
}
