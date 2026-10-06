<?php

namespace App\Mcp\Tools;

use App\Support\AccessPolicy;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

final class ListErpCompanies extends ErpReadTool
{
    protected string $name = 'list_erp_companies';

    protected string $description = 'List active ERP companies this authenticated account can view. Start here to obtain company_id. Read-only.';

    public function handle(Request $request): Response
    {
        return $this->read(function () use ($request): Response {
            $this->onlyArguments($request, []);
            $actor = $this->actor($request);
            $companies = $actor->accessibleCompanies()->filter(fn ($company) => AccessPolicy::canSeeCompany($actor, $company->id))->map(fn ($company) => [
                'id' => $company->id, 'code' => $company->code, 'name' => $company->name,
            ])->values()->all();

            return Response::json(['companies' => $companies, 'read_only' => true]);
        });
    }
}
