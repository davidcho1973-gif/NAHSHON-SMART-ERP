<?php

namespace App\Models\Scopes;

use App\Models\Employee;
use App\Models\Payslip;
use App\Models\Project;
use App\Models\ProjectContract;
use App\Models\User;
use App\Support\AiInformationAccess;
use App\Support\JobAccess;
use App\Support\SensitiveDocuments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Schema;

/** Scope belongs to the signed-in actor, not a UI filter or their legacy carrier role. */
class JobDataScope implements Scope
{
    private array $columns = [];

    public function __construct(private readonly ?User $actor = null) {}

    public function apply(Builder $builder, Model $model): void
    {
        $user = $this->actor ?? auth()->user();
        if (! JobAccess::managed($user) || $user->access_role === 'super_admin'
            || request()->is('manager-invitation/*', 'auth/*', 'login', 'app/*')) {
            return;
        }
        $table = $model->getTable();
        $columns = $this->columns[$table] ??= Schema::getColumnListing($table);
        $column = fn (string $name): string => $model->qualifyColumn($name);
        // Shared checklist questions have no employee owner; equipment QR operations still stay on assigned sites.
        if ($table === 'equipment_checklist_templates') {
            $builder->where(fn (Builder $q) => $q->whereNull($column('company_id'))->orWhere($column('company_id'), $user->allowed_company_id ?: 0))
                ->where(fn (Builder $q) => $q->whereNull($column('site_id'))->orWhereIn($column('site_id'), JobAccess::siteIds($user)));

            return;
        }
        $personalEquipment = $table === 'equipments' && request()->is('eq/*');
        if ($table === 'companies') {
            $builder->where($column('id'), $user->allowed_company_id ?: 0);

            return;
        }
        if ($table === 'sites') {
            $builder->whereIn($column('id'), JobAccess::siteIds($user));

            return;
        }
        if ($table === 'payroll_runs') {
            $employees = JobAccess::employeeQuery($user)->select('id');
            $builder->whereHas('payslips', fn (Builder $q) => $q->withoutGlobalScope(self::class)->whereIn('employee_id', clone $employees))
                ->whereDoesntHave('payslips', fn (Builder $q) => $q->withoutGlobalScope(self::class)->whereNotIn('employee_id', clone $employees));

            return;
        }
        if ($model instanceof Employee) {
            $builder->whereIn($column('id'), JobAccess::employeeQuery($user)->select('id'));

            return;
        }
        // Authentication itself must be able to resolve the current user after a permission change.
        if ($model instanceof User) {
            return;
        }
        if (in_array('employee_id', $columns, true)) {
            $builder->where(function (Builder $q) use ($column, $user, $personalEquipment): void {
                $q->whereIn($column('employee_id'), JobAccess::employeeQuery($user)->select('id'));
                if ($user->access_scope !== 'self' || $personalEquipment) {
                    $q->orWhereNull($column('employee_id'));
                }
            });
        } elseif (in_array('site_id', $columns, true)) {
            $builder->where(function (Builder $q) use ($user, $column, $columns): void {
                $q->whereIn($column('site_id'), JobAccess::siteIds($user));
                if ($user->access_scope === 'company' && in_array('company_id', $columns, true)) {
                    $q->orWhere(fn (Builder $nullSite) => $nullSite->whereNull($column('site_id'))->where($column('company_id'), $user->allowed_company_id ?: 0));
                }
            });
        } elseif (in_array('company_id', $columns, true)) {
            $builder->where($column('company_id'), $user->allowed_company_id ?: 0);
        } elseif (in_array('project_id', $columns, true)) {
            $builder->whereIn($column('project_id'), Project::query()->select('id'));
        } elseif (in_array('payslip_id', $columns, true)) {
            $builder->whereIn($column('payslip_id'), Payslip::query()->select('id'));
        } elseif (in_array('project_contract_id', $columns, true)) {
            $builder->whereIn($column('project_contract_id'), ProjectContract::query()->select('id'));
        }
        if (in_array('employee_id', $columns, true) && in_array('site_id', $columns, true) && $user->access_scope === 'company') {
            $builder->where(fn (Builder $q) => $q->whereNull($column('site_id'))->orWhereIn($column('site_id'), JobAccess::siteIds($user)));
        }
        if (in_array('company_id', $columns, true) && $table !== 'companies') {
            $builder->where(function (Builder $q) use ($user, $column, $columns, $table): void {
                $q->where($column('company_id'), $user->allowed_company_id ?: 0);
                $q->orWhere(function (Builder $legacy) use ($user, $column, $columns, $table): void {
                    $legacy->whereNull($column('company_id'))->where(function (Builder $owner) use ($user, $column, $columns, $table): void {
                        $owner->whereRaw('1 = 0');
                        if (in_array('site_id', $columns, true)) {
                            $owner->orWhereIn($column('site_id'), JobAccess::siteIds($user));
                        }
                        if (in_array('employee_id', $columns, true)) {
                            $owner->orWhereIn($column('employee_id'), JobAccess::employeeQuery($user)->select('id'));
                        }
                        if (in_array($table, ['items', 'item_categories', 'equipment_checklist_templates'], true)) {
                            $owner->orWhereRaw('1 = 1');
                        }
                    });
                });
            });
        }
        if (in_array($user->access_scope, ['team', 'trade'], true) && in_array('trade', $columns, true) && ! $personalEquipment) {
            $trade = JobAccess::trade($user);
            $trade ? $builder->where($column('trade'), $trade) : $builder->whereRaw('1 = 0');
        }
        if ($user->access_scope === 'team' && in_array('team_id', $columns, true)) {
            $builder->where(function (Builder $q) use ($column, $user, $personalEquipment): void {
                $q->where($column('team_id'), $user->allowed_team_id ?: 0);
                if ($personalEquipment) {
                    $q->orWhereNull($column('team_id'));
                }
            });
        }
        if ($user->access_scope === 'trade' && in_array('team_id', $columns, true)) {
            $builder->where(function (Builder $q) use ($column, $user, $personalEquipment): void {
                $q->whereIn($column('team_id'), JobAccess::teamIds($user));
                if ($personalEquipment) {
                    $q->orWhereNull($column('team_id'));
                }
            });
        }
        if ($table === 'purchase_requests' && in_array($user->access_scope, ['team', 'trade'], true)) {
            $owners = User::query()->whereIn('employee_id', JobAccess::employeeQuery($user)->select('id'))->select('id');
            $builder->where(fn (Builder $q) => $q->where($column('requested_by_id'), $user->id)->orWhereIn($column('requested_by_id'), $owners));
        }
        if (in_array('employee_id', $columns, true) && in_array('site_id', $columns, true) && $user->access_scope !== 'company') {
            $builder->where(fn (Builder $q) => $q->where($column('employee_id'), $user->employee_id ?: 0)->orWhereIn($column('site_id'), JobAccess::siteIds($user)));
        }
        if ($user->access_scope === 'self' && ! in_array('employee_id', $columns, true) && ! in_array($table, ['sites', 'companies', 'intelligent_documents', 'integrated_documents'], true)) {
            $ownerColumn = collect(['requested_by_id', 'submitted_by_id', 'created_by_id', 'reported_by_id', 'owner_user_id', 'user_id'])->first(fn ($field) => in_array($field, $columns, true));
            if ($ownerColumn) {
                $builder->where($column($ownerColumn), $user->id);
            } elseif (in_array('site_id', $columns, true) || in_array('company_id', $columns, true)) {
                $builder->whereRaw('1 = 0');
            }
        }
        foreach (config('job_parents.'.class_basename($model), []) as [$foreignKey, $parent]) {
            if (! in_array($foreignKey, $columns, true)) {
                continue;
            }
            $parentQuery = $parent::query()->withoutGlobalScope(self::class)->withGlobalScope('job_parent', new self($user))->select((new $parent)->qualifyColumn('id'));
            $builder->where(fn (Builder $q) => $q->whereNull($column($foreignKey))->orWhereIn($column($foreignKey), $parentQuery));
        }
        if (in_array($table, ['intelligent_documents', 'integrated_documents'], true)) {
            $forbidden = array_values(array_diff(SensitiveDocuments::MONEY_TYPES, JobAccess::visibleMoneyTypes($user)));
            $builder->where(fn (Builder $q) => $q->whereNull($column('document_type'))->orWhereNotIn($column('document_type'), $forbidden));
            if (in_array('access_level', $columns, true) && in_array('owner_user_id', $columns, true)) {
                $builder->where(fn (Builder $q) => $q->whereNull($column('access_level'))->orWhere($column('access_level'), '!=', 'private')->orWhere($column('owner_user_id'), $user->id));
            }
            if ($table === 'intelligent_documents') {
                AiInformationAccess::withoutUnauthorizedJobText($builder, $user);
            }
        }
        if ($table === 'teams' && $user->access_scope === 'team') {
            $builder->where($column('id'), $user->allowed_team_id ?: 0);
        }
        if ($table === 'teams' && $user->access_scope === 'trade') {
            $builder->whereIn($column('id'), JobAccess::teamIds($user));
        }
    }

    public static function assertWritable(Model $model): void
    {
        $user = auth()->user();
        if (! JobAccess::managed($user) || $user->access_role === 'super_admin' || request()->is('manager-invitation/*', 'auth/*', 'app/*')) {
            return;
        }
        foreach (config('job_parents.'.class_basename($model), []) as [$foreignKey, $parent]) {
            if ($model->isDirty($foreignKey) && $model->getAttribute($foreignKey) !== null) {
                abort_unless($parent::query()->withoutGlobalScope(self::class)->withGlobalScope('job_parent', new self($user))->whereKey($model->getAttribute($foreignKey))->exists(), 403, '담당 범위 밖의 자료입니다.');
            }
        }
        if (in_array($user->access_scope, ['team', 'trade'], true) && $model->isDirty('trade')) {
            abort_unless(filled(JobAccess::trade($user)) && $model->trade === JobAccess::trade($user), 403, '담당 공종의 자료만 작성할 수 있습니다.');
        }
        if ($user->access_scope === 'trade' && $model->isDirty('trade_type')) {
            abort_unless($model->trade_type === JobAccess::trade($user), 403, '담당 공정만 변경할 수 있습니다.');
        }
        if ($model->isDirty('project_id') && $model->project_id !== null) {
            abort_unless(Project::whereKey($model->project_id)->exists(), 403, '담당 범위 밖의 프로젝트입니다.');
        }
        foreach (['company_id', 'site_id', 'team_id', 'employee_id'] as $column) {
            if (! $model->isDirty($column) || $model->getAttribute($column) === null) {
                continue;
            }
            $id = (int) $model->getAttribute($column);
            $allowed = match ($column) {
                'company_id' => $id === (int) $user->allowed_company_id,
                'site_id' => in_array($id, JobAccess::siteIds($user), true),
                'team_id' => $user->access_scope === 'trade' ? in_array($id, JobAccess::teamIds($user), true) : ($user->access_scope !== 'team' || $id === (int) $user->allowed_team_id),
                'employee_id' => JobAccess::employeeQuery($user)->whereKey($id)->exists(),
            };
            abort_unless($allowed, 403, '담당 범위 밖의 자료를 저장할 수 없습니다.');
        }
    }
}
