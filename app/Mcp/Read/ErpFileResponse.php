<?php

namespace App\Mcp\Read;

use Laravel\Mcp\Response;

final class ErpFileResponse extends Response
{
    public static function attachment(array $file): self
    {
        return new self(new ErpFileContent($file));
    }
}
