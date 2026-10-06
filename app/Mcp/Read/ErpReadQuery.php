<?php

namespace App\Mcp\Read;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Payslip;
use App\Models\Site;
use App\Services\Communication\CommunicationService;
use App\Support\AccessPolicy;
use App\Support\SensitiveDocuments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Only the compiled catalog selects models/columns. No SQL, table, method or relation input. */
final class ErpReadQuery
{
    public function definition(string $key): array
    {
        return ErpDatasetCatalog::all()[$key] ?? throw new LogicException('Unknown ERP dataset.');
    }

    public function query(string $key, ErpReadContext $context, array $visited = []): Builder
    {
        if (in_array($key, $visited, true) || count($visited) > 8) {
            throw new LogicException('Invalid catalog parent chain.');
        }
        $definition = $this->definition($key);
        abort_unless(in_array($context->actor->access_role, $definition['roles'], true), 403, 'Module access denied.');
        /** @var Model $model */
        $model = new $definition['model'];
        $query = $model->newQuery();
        $column = fn (string $name): string => $model->qualifyColumn($name);

        if (isset($definition['parent'])) {
            [$foreignKey, $parentKey] = $definition['parent'];
            $parent = $this->query($parentKey, $context, [...$visited, $key]);
            $query->whereIn($column($foreignKey), $parent->select($parent->getModel()->qualifyColumn('id')));
        }

        switch ($definition['scope']) {
            case 'company':
                $query->where($column($model instanceof Company ? 'id' : 'company_id'), $context->companyId);
                break;
            case 'company_site':
                // Legacy rows may have a null company; only an authorized physical site can supply it.
                $query->where(fn (Builder $q) => $q->where($column('company_id'), $context->companyId)
                    ->orWhere(fn (Builder $q) => $q->whereNull($column('company_id'))
                        ->whereIn($column('site_id'), $context->selectedSiteIds())));
                if ($context->siteId !== null || ! AccessPolicy::canManageSystem($context->actor)) {
                    $query->whereIn($column('site_id'), $context->selectedSiteIds());
                } else {
                    $query->where(fn (Builder $q) => $q->whereNull($column('site_id'))
                        ->orWhereIn($column('site_id'), $context->selectedSiteIds()));
                }
                break;
            case 'site':
                $query->whereIn($column($model instanceof Site ? 'id' : 'site_id'), $context->selectedSiteIds());
                break;
            case 'employee':
                $employees = Employee::query()->where('company_id', $context->companyId);
                if ($context->siteId !== null || ! AccessPolicy::canManageSystem($context->actor)) {
                    $employees->whereIn('site_id', $context->selectedSiteIds());
                }
                if (! AccessPolicy::canManageSystem($context->actor)) {
                    if ($context->actor->access_scope === 'self') {
                        $employees->whereKey($context->actor->employee_id ?: 0);
                    } elseif ($context->actor->access_scope === 'team') {
                        $employees->where('team_id', $context->actor->allowed_team_id ?: 0);
                    }
                }
                $query->whereIn($column($model instanceof Employee ? 'id' : 'employee_id'), $employees->select('id'));
                break;
            case 'payroll_run':
                $allowed = $this->query('payslips', $context, [...$visited, $key])->select('payslips.id');
                $query->whereHas('payslips', fn (Builder $q) => $q->whereIn('payslips.id', clone $allowed))
                    ->whereDoesntHave('payslips', fn (Builder $q) => $q->whereNotIn('payslips.id', clone $allowed));
                break;
            case 'own_user':
                $query->where($column($definition['user_column'] ?? 'user_id'), $context->actor->id);
                break;
            case 'personal_alert':
                $query->where($column('user_id'), $context->actor->id)
                    ->where($column('company_id'), $context->companyId)
                    ->whereIn($column('site_id'), $context->selectedSiteIds());
                break;
            case 'template':
                $query->where(fn (Builder $q) => $q
                    ->where(fn (Builder $global) => $global->whereNull('company_id')->whereNull('site_id')->where('scope_type', 'global'))
                    ->orWhere(fn (Builder $local) => $local->where('company_id', $context->companyId)
                        ->where(fn (Builder $sites) => $sites->whereNull('site_id')->orWhereIn('site_id', $context->selectedSiteIds()))));
                break;
            case 'global':
                break;
            default:
                throw new LogicException('Missing explicit ERP scope.');
        }

        // In addition to site scoping, team/self accounts must not inherit coworkers' rows.
        if (! AccessPolicy::canManageSystem($context->actor)) {
            $fields = $definition['fields'];
            if ($context->actor->access_scope === 'self' && in_array('employee_id', $fields, true)) {
                $query->where($column('employee_id'), $context->actor->employee_id ?: 0);
            }
            if ($context->actor->access_scope === 'team' && in_array('team_id', $fields, true)) {
                $query->where($column('team_id'), $context->actor->allowed_team_id ?: 0);
            } elseif ($context->actor->access_scope === 'team' && in_array('employee_id', $fields, true)) {
                $query->whereIn($column('employee_id'), Employee::query()->where('company_id', $context->companyId)
                    ->where('team_id', $context->actor->allowed_team_id ?: 0)->select('id'));
            }
        }

        if ($model instanceof Payslip) {
            // A payslip's full totals cannot be labeled as a single site's totals.
            $query->whereHas('lines', fn (Builder $q) => $q->whereIn('site_id', $context->selectedSiteIds()))
                ->whereDoesntHave('lines', fn (Builder $q) => $q->whereNull('site_id')->orWhereNotIn('site_id', $context->selectedSiteIds()));
        }

        $this->privacy($query, $definition['privacy'] ?? null, $context);

        return $query;
    }

    private function privacy(Builder $query, ?string $privacy, ErpReadContext $context): void
    {
        switch ($privacy) {
            case null:
                return;
            case 'document':
                $query->visibleTo($context->actor);
                $this->scopeMoneyDocuments($query, $context);

                return;
            case 'integrated_document':
                $this->scopeMoneyDocuments($query, $context);
                $visible = $this->query('documents', $context)->select('intelligent_documents.id');
                $query->where(fn (Builder $q) => $q->whereNull('source_document_id')->orWhereIn('source_document_id', $visible));

                return;
            case 'email_thread':
                $query->visibleTo($context->actor);

                return;
            case 'communication_room':
                // This query builder is pure; roomsForUser() provisions rooms and is forbidden here.
                $query->whereIn('communication_rooms.id', app(CommunicationService::class)
                    ->roomQueryForUser($context->actor)->select('communication_rooms.id'));

                return;
            case 'communication_message':
                $query->whereNull('removed_at');

                return;
            default:
                throw new LogicException('Unknown ERP privacy policy.');
        }
    }

    private function scopeMoneyDocuments(Builder $query, ErpReadContext $context): void
    {
        if (! SensitiveDocuments::canSeeMoney($context->actor)) {
            $query->where(fn (Builder $q) => $q->whereNull('document_type')->orWhereNotIn('document_type', SensitiveDocuments::MONEY_TYPES));
        }
    }

    public function read(string $key, ErpReadContext $context, array $input): array
    {
        $definition = $this->definition($key);
        $query = $this->query($key, $context)->select($definition['fields']);
        if (isset($input['id'])) {
            $query->whereKey($input['id']);
        }
        $query->where('id', '>', $input['after_id'] ?? 0)->orderBy('id');
        if (! empty($input['search'])) {
            abort_unless(! empty($definition['search']), 422, 'This dataset does not support text search.');
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $input['search']).'%';
            $query->where(function (Builder $q) use ($definition, $term): void {
                foreach ($definition['search'] as $field) {
                    $q->orWhere($field, 'ilike', $term);
                }
            });
        }
        $limit = $input['limit'] ?? 20;
        $records = $query->limit($limit + 1)->get();
        $more = $records->count() > $limit;
        $records = $records->take($limit);
        $rows = $records->map(function (Model $record) use ($key, $definition, $input): array {
            // Only raw, explicitly selected columns; never model appenders, relations or decrypted casts.
            $row = [];
            foreach ($definition['fields'] as $field) {
                $value = $record->getRawOriginal($field);
                if (is_string($value) && mb_strlen($value) > ($input['text_offset'] ?? 0) + ($input['text_limit'] ?? 2000)) {
                    $row['_text_pages'][$field] = ['offset' => $input['text_offset'] ?? 0,
                        'next_offset' => ($input['text_offset'] ?? 0) + ($input['text_limit'] ?? 2000), 'total_characters' => mb_strlen($value)];
                }
                $row[$field] = is_string($value) && mb_strlen($value) > ($input['text_limit'] ?? 2000)
                    ? mb_substr($value, $input['text_offset'] ?? 0, $input['text_limit'] ?? 2000) : $value;
            }
            if (! empty($definition['attachments'])) {
                $row['attachment_slots'] = array_keys($definition['attachments']);
                $row['attachment_tool'] = 'read_'.$key.'_attachment';
            }

            return $row;
        })->all();

        while (count($rows) > 1 && strlen(json_encode($rows, JSON_THROW_ON_ERROR)) > 524288) {
            array_pop($rows);
            $more = true;
        }
        abort_if(strlen(json_encode($rows, JSON_THROW_ON_ERROR)) > 524288, 413);

        return ['dataset' => $key, 'company_id' => $context->companyId, 'site_id' => $context->siteId,
            'records' => $rows, 'next_after_id' => $more && $rows !== [] ? (int) end($rows)['id'] : null,
            'read_only' => true, 'source' => 'stored ERP records', 'as_of' => now()->toIso8601String()];
    }
}
