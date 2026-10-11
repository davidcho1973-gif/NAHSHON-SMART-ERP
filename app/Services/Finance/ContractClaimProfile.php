<?php

namespace App\Services\Finance;

use App\Models\ProjectContract;
use InvalidArgumentException;

/** Contract-specific entitlement and submission requirements; never inferred from a price split. */
class ContractClaimProfile
{
    public static function read(ProjectContract $contract): array
    {
        return array_replace(self::defaults(), (array) data_get($contract->payload, 'claim_profile', []));
    }

    public static function confirmed(ProjectContract $contract): bool
    {
        $profile = self::read($contract);

        return filled($profile['confirmed_at']) && filled($profile['confirmed_by']) && filled($profile['basis_note']);
    }

    public static function defaults(): array
    {
        return ['version' => 0, 'confirmed_at' => null, 'confirmed_by' => null, 'basis_note' => '',
            'stored_materials' => false, 'submission_day' => null, 'preparation_days' => 7,
            'template_label' => '', 'requirements' => []];
    }

    /** Browser-supplied confirmation metadata is ignored. Changed terms require new confirmation. */
    public static function update(array $input, array $previous, int $actorId): array
    {
        $previous = array_replace(self::defaults(), $previous);
        if (array_key_exists('version', $input) && (int) $input['version'] !== (int) $previous['version']) {
            throw new InvalidArgumentException('다른 담당자가 청구 조건을 변경했습니다. 계약을 새로 열어 확인하세요.');
        }
        $basis = trim((string) ($input['basis_note'] ?? $previous['basis_note']));
        $label = trim((string) ($input['template_label'] ?? $previous['template_label']));
        if (mb_strlen($basis) > 5000 || mb_strlen($label) > 200) {
            throw new InvalidArgumentException('확인 근거는 5000자, 양식 이름은 200자 이내로 입력하세요.');
        }
        $day = array_key_exists('submission_day', $input) ? $input['submission_day'] : $previous['submission_day'];
        $day = $day === '' ? null : $day;
        $lead = $input['preparation_days'] ?? $previous['preparation_days'];
        if (($day !== null && (filter_var($day, FILTER_VALIDATE_INT) === false || (int) $day < 1 || (int) $day > 31))
            || filter_var($lead, FILTER_VALIDATE_INT) === false || (int) $lead < 0 || (int) $lead > 30) {
            throw new InvalidArgumentException('제출일은 1~31일, 준비 기간은 0~30일로 입력하세요.');
        }
        $stored = filter_var($input['stored_materials'] ?? $previous['stored_materials'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($stored === null) {
            throw new InvalidArgumentException('반입자재 기성 인정 여부를 확인하세요.');
        }
        $items = $input['requirements'] ?? $previous['requirements'];
        if (! is_array($items) || count($items) > 100) {
            throw new InvalidArgumentException('필수 제출서류는 100개 이내로 입력하세요.');
        }
        $requirements = [];
        $seen = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                throw new InvalidArgumentException('제출서류 형식을 확인하세요.');
            }
            $title = trim((string) ($item['title'] ?? ''));
            $stage = (string) ($item['stage'] ?? '');
            if ($title === '' || mb_strlen($title) > 200 || ! in_array($stage, ['once', 'each_claim', 'final'], true)) {
                throw new InvalidArgumentException('서류 제목과 제출 시점(최초·매회·최종)을 확인하세요.');
            }
            $key = trim((string) ($item['key'] ?? '')) ?: 'req_'.substr(hash('sha256', $stage."\n".$title), 0, 20);
            if (! preg_match('/^[a-zA-Z0-9_-]{1,80}$/D', $key) || isset($seen[$key])) {
                throw new InvalidArgumentException('제출서류 식별자가 올바르지 않거나 중복되었습니다.');
            }
            $seen[$key] = true;
            $requirements[] = ['key' => $key, 'title' => $title, 'stage' => $stage, 'required' => true];
        }
        $profile = ['basis_note' => $basis, 'stored_materials' => $stored,
            'submission_day' => $day === null ? null : (int) $day, 'preparation_days' => (int) $lead,
            'template_label' => $label, 'requirements' => $requirements];
        $oldTerms = array_intersect_key($previous, $profile);
        $changed = $profile != $oldTerms;
        $confirm = filter_var($input['confirm'] ?? false, FILTER_VALIDATE_BOOL);
        if ($confirm && $basis === '') {
            throw new InvalidArgumentException('조건을 확인하려면 계약 조항·원청 회신 등 확인 근거를 입력하세요.');
        }
        if (! $changed && ! $confirm && (int) $previous['version'] > 0) {
            return $previous;
        }

        return ['version' => (int) $previous['version'] + 1,
            'confirmed_at' => $confirm ? now()->toIso8601String() : ($changed ? null : $previous['confirmed_at']),
            'confirmed_by' => $confirm ? $actorId : ($changed ? null : $previous['confirmed_by'])] + $profile;
    }
}
