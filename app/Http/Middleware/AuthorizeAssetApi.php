<?php

namespace App\Http\Middleware;

use App\Models\Employee;
use App\Models\Equipment;
use App\Models\Vehicle;
use App\Support\OperationalAccess;
use Closure;
use Illuminate\Http\Request;

class AuthorizeAssetApi
{
    public function handle(Request $request, Closure $next)
    {
        OperationalAccess::assertManage();
        foreach (['equipment' => Equipment::class, 'vehicle' => Vehicle::class] as $key => $class) {
            $value = $request->route($key) ?? $request->input($key.'_id');
            if ($value) {
                OperationalAccess::assertRecord($value instanceof $class ? $value : $class::find($value));
            }
        }
        if ($id = $request->input('employee_id')) {
            OperationalAccess::assertRecord(Employee::find($id));
        }
        if ($request->filled('site_id')) {
            OperationalAccess::assertSite((int) $request->input('site_id'));
        }

        return $next($request);
    }
}
