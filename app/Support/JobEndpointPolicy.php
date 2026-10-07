<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;

/** Managed job profiles fail closed on endpoints that have not been assigned to a business module. */
final class JobEndpointPolicy
{
    public static function legacy(string $method, array $args = []): ?array
    {
        $entry = config('job_endpoints.legacy.'.$method);
        if ($method === 'api_reviewExpense') {
            return ['finance', ($args[1] ?? '') === 'paid' ? 'pay' : 'approve'];
        }
        if ($method === 'api_reviewClaimRecord' || $method === 'api_setBillingStatus') {
            return ['contracts', $method === 'api_setBillingStatus' && in_array($args[0]['action'] ?? '', ['submit', 'withdraw'], true) ? 'edit' : 'approve'];
        }

        return $entry;
    }

    public static function authorizeLegacy(?User $user, string $method, array $args = []): void
    {
        if (! JobAccess::managed($user) || $user->access_role === 'super_admin') {
            return;
        }
        $permission = self::legacy($method, $args);
        abort_unless($permission && JobAccess::can($user, ...$permission), 403, '이 업무를 처리할 권한이 없습니다.');
    }

    public static function route(Request $request): ?array
    {
        $route = $request->route();
        $controller = class_basename(explode('@', $route?->getActionName() ?? '')[0]);
        $action = explode('@', $route?->getActionName() ?? '')[1] ?? '__invoke';
        if ($controller === 'SmartCompanyApiController') {
            return self::legacy((string) $route->parameter('method'), (array) $request->input('args', []));
        }
        if ($controller === 'JobApprovalController') {
            $module = (string) $route->parameter('module');

            return in_array($module, ['purchasing', 'contracts', 'payroll', 'finance'], true) ? [$module, $action === 'pay' ? 'pay' : 'approve'] : null;
        }
        if ($controller === 'PurchaseRequestController' && $action === 'action' && $request->input('action') === 'clarify') {
            return ['purchasing', 'view'];
        }
        $specific = config('job_endpoints.controllers.'.$controller.'.'.$action);
        if ($specific) {
            return $specific;
        }
        $module = config('job_endpoints.controllers.'.$controller.'._module');
        if (! $module) {
            return null;
        }
        $verb = $request->isMethod('GET') ? 'view' : 'edit';
        if (in_array($action, ['download', 'export', 'exportIndex', 'exportCsv', 'file', 'showFile', 'receipt', 'printable', 'certified'], true)) {
            $verb = 'export';
        } elseif (in_array($action, ['approve', 'reject', 'review', 'decide'], true)) {
            $verb = 'approve';
        } elseif (in_array($action, ['destroy', 'delete', 'deleteDocument'], true) || $request->isMethod('DELETE')) {
            $verb = 'delete';
        }

        return [$module, $verb];
    }

    /** Responses can include sensitive employee fields even on otherwise permitted staff lists. */
    public static function redact(mixed $value, User $user, ?string $module = null): mixed
    {
        if (! is_array($value) || $user->access_role === 'super_admin') {
            return $value;
        }
        $private = ['w9tinlast4', 'tinlast4', 'tin', 'ssn', 'taxid', 'w9certifiedon', 'w9onfile', 'birthdate', 'dateofbirth', 'address', 'visa', 'visatype', 'visaexpireson', 'email', 'loginemail', 'phone', 'nationality', 'passport', 'bankaccount', 'routingnumber', 'onboardingrequesturl'];
        if (! JobAccess::can($user, 'private_hr') && (array_key_exists('employeeNumber', $value) || array_key_exists('employee_number', $value))) {
            unset($value['notes'], $value['payload']);
        }
        $contracts = ['contractamount', 'totalcontract', 'contractbalance', 'projectedbalance', 'currentamount', 'originalamount', 'approvedchangeamount', 'billedamount', 'aroutstanding', 'retainage', 'totalbilled', 'totalreceived'];
        $finance = ['plannedcost', 'actualcost', 'unitcost', 'unitprice', 'totalcost', 'budgetamount', 'costrate'];
        $money = ['baserate', 'appliedrate', 'hourlyrate', 'grosspay', 'netpay', 'salary', 'payroll', 'wages', 'fringerate', 'perdiem', 'payrate'];
        foreach ($value as $key => $item) {
            $normalized = strtolower(str_replace(['_', '-'], '', (string) $key));
            $action = ['canmanage' => 'edit', 'canedit' => 'edit', 'cancreate' => 'edit', 'candelete' => 'delete', 'canapprove' => 'approve', 'canpay' => 'pay', 'canexport' => 'export', 'canclear' => 'delete'][$normalized] ?? null;
            if ($module && $action && is_bool($item)) {
                $value[$key] = $item && JobAccess::can($user, $module, $action);

                continue;
            }
            if ((! JobAccess::can($user, 'private_hr') && (in_array($normalized, $private, true) || preg_match('/^(visa|w9|tin|ssn|passport|taxid)/', $normalized)))
                || (! JobAccess::can($user, 'finance') && in_array($normalized, $finance, true))
                || (! JobAccess::can($user, 'contracts') && in_array($normalized, $contracts, true))
                || (! JobAccess::can($user, 'payroll') && in_array($normalized, $money, true))) {
                unset($value[$key]);

                continue;
            }
            $value[$key] = self::redact($item, $user, $module);
            if ($normalized === 'canmanage' && $item === true) {
                // Action-specific API guards remain authoritative; clients also receive the permission matrix.
                $value['jobPermissions'] = $user->job_permissions;
            }
        }

        return $value;
    }
}
