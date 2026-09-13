<?php

namespace App\Support;

use App\Models\DailyClosingReport;
use App\Models\IntegratedDocument;
use App\Models\OpsActionItem;
use App\Models\OpsIntakeBatch;
use App\Models\OpsIntakeItem;
use App\Models\OpsLaborReport;
use App\Models\Site;
use App\Models\WbsItem;

/** Authorize operations before the legacy adapter invokes a domain service. */
final class LegacyOperationPolicy
{
    private const RECORDS = [
        'api_getDocDetail' => IntegratedDocument::class,
        'api_confirmDoc' => IntegratedDocument::class,
        'api_deleteDoc' => IntegratedDocument::class,
        'api_linkDoc' => IntegratedDocument::class,
        'api_completeOpsAction' => OpsActionItem::class,
        'api_deleteOpsAction' => OpsActionItem::class,
        'api_applyOpsItem' => OpsIntakeItem::class,
        'api_revertOpsItem' => OpsIntakeItem::class,
        'api_dismissOpsItem' => OpsIntakeItem::class,
        'api_getOpsBatch' => OpsIntakeBatch::class,
        'api_getOpsJob' => OpsIntakeBatch::class,
        'api_deleteOpsLabor' => OpsLaborReport::class,
        'api_updateOpsBatch' => OpsIntakeBatch::class,
        'api_deleteOpsBatch' => OpsIntakeBatch::class,
        'api_getDailyClosing' => DailyClosingReport::class,
    ];

    private const MANAGE = [
        'api_confirmDoc', 'api_deleteDoc', 'api_linkDoc', 'api_createDocFolder', 'api_deleteDocFolder',
        'api_saveOpsAction', 'api_completeOpsAction', 'api_deleteOpsAction',
        'api_applyOpsItem', 'api_applyAllOpsItems', 'api_revertOpsItem', 'api_dismissOpsItem',
        'api_updateOpsBatch', 'api_deleteOpsBatch', 'api_startDailyClosing',
        'api_saveDailyPlan', 'api_sendDailyReport', 'api_saveOpsLabor', 'api_deleteOpsLabor',
        'api_markWbsStatus', 'api_updateWbsRow', 'api_insertWbsRow', 'api_addManualWbsWork',
        'api_toggleWeekCommit', 'api_updateProcurement', 'api_processWbsManual',
        'api_createSafetyCardForWbs', 'api_assignSafetySigner',
    ];

    public static function authorize(string $method, array $args, string $siteId): string
    {
        $user = auth()->user();
        if (in_array($method, self::MANAGE, true)) {
            OperationalAccess::assertManage();
        }
        if ($class = self::RECORDS[$method] ?? null) {
            $record = $class::find((int) ($args[0] ?? 0));
            OperationalAccess::assertRecord($record);
            if ($record instanceof IntegratedDocument) {
                abort_unless(SensitiveDocuments::allows($record->document_type), 403);
            }
        }
        if (in_array($method, ['api_updateWbsRow', 'api_insertWbsRow', 'api_markWbsStatus', 'api_toggleWeekCommit', 'api_createSafetyCardForWbs', 'api_getWbsLabor'], true)) {
            OperationalAccess::assertRecord(WbsItem::where('wbs_code', (string) ($args[0] ?? ''))->first());
        }
        if ($method === 'api_saveOpsAction' && ($args[0]['id'] ?? null)) {
            OperationalAccess::assertRecord(OpsActionItem::find($args[0]['id']));
        }
        if ($method === 'api_applyAllOpsItems') {
            foreach ((array) ($args[0] ?? []) as $id) {
                OperationalAccess::assertRecord(OpsIntakeItem::find((int) $id));
            }
        }
        if ($method === 'api_getDocStorageHealth') {
            abort_unless(AccessPolicy::canManageSystem($user), 403);
        }
        if ($method === 'api_saveOpsLabor' && ($args[0]['id'] ?? null)) {
            OperationalAccess::assertRecord(OpsLaborReport::find($args[0]['id']));
        }
        // A UI-provided Global filter cannot expand a scoped user's authority.
        if ($user && ! AccessPolicy::canManageSystem($user) && (isset(self::RECORDS[$method]) || str_contains($method, 'Doc') || str_contains($method, 'Ops') || str_contains($method, 'Daily') || $method === 'api_opsIngest')) {
            $resolved = SmartCompanyData::resolveSiteId($siteId);
            $resolved ??= AiInformationAccess::siteId($user);
            if ($resolved === null && ! str_contains($method, 'Doc')) {
                abort(403, '현장 배정이 필요합니다.');
            }
            if ($resolved !== null) {
                OperationalAccess::assertSite($resolved, $user);
                $siteId = (string) Site::findOrFail($resolved)->code;
            }
        }

        return $siteId;
    }
}
