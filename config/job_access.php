<?php

// Job titles describe work; permissions describe actions. Keep both app surfaces on this catalog.
return [
    'actions' => ['view' => '조회', 'edit' => '작성·수정', 'approve' => '승인', 'pay' => '지급 실행', 'delete' => '삭제', 'export' => '다운로드·내보내기'],
    'modules' => [
        'attendance' => '근태·인원 현황', 'people' => '직원 등록·인사', 'private_hr' => '인사·세금 서류',
        'payroll' => '시급·급여', 'finance' => '회계·경비·자금', 'contracts' => '계약·기성·변경공사',
        'purchasing' => '구매신청·발주', 'materials' => '자재·장비·입고', 'progress' => '공정·작업 배치',
        'reports' => '현장 보고', 'safety' => '안전·교육', 'documents' => '현장 문서',
        'office' => '차량·숙소·총무', 'messages' => '메시지·공지', 'system' => '계정·조직 설정',
    ],
    'jobs' => [
        'worker' => ['label' => '작업자', 'position' => 'worker', 'scope' => 'self', 'permissions' => ['reports' => ['view', 'edit'], 'documents' => ['view', 'edit'], 'messages' => ['view', 'edit']]],
        'trade_lead' => ['label' => '작업반장', 'position' => 'foreman', 'scope' => 'team', 'permissions' => ['attendance' => ['view', 'edit'], 'people' => ['view'], 'progress' => ['view', 'edit'], 'reports' => ['view', 'edit'], 'materials' => ['view', 'edit'], 'purchasing' => ['view'], 'documents' => ['view', 'edit'], 'messages' => ['view', 'edit']]],
        'trade_manager' => ['label' => '공정팀장', 'position' => 'trade_manager', 'scope' => 'trade', 'permissions' => ['attendance' => ['view', 'edit', 'approve'], 'people' => ['view'], 'progress' => ['view', 'edit'], 'reports' => ['view', 'edit', 'approve'], 'materials' => ['view', 'edit'], 'purchasing' => ['view'], 'safety' => ['view'], 'documents' => ['view', 'edit', 'export'], 'messages' => ['view', 'edit']]],
        'site_manager' => ['label' => '소장', 'position' => 'superintendent', 'scope' => 'site', 'permissions' => ['attendance' => ['view', 'edit', 'approve'], 'people' => ['view'], 'progress' => ['view', 'edit'], 'reports' => ['view', 'edit', 'approve'], 'materials' => ['view', 'edit'], 'purchasing' => ['view'], 'safety' => ['view'], 'documents' => ['view', 'edit'], 'messages' => ['view', 'edit']]],
        'engineering' => ['label' => '공무팀장', 'position' => 'engineer', 'scope' => 'site', 'permissions' => ['progress' => ['view', 'edit'], 'reports' => ['view', 'edit'], 'contracts' => ['view', 'edit'], 'materials' => ['view'], 'purchasing' => ['view'], 'documents' => ['view', 'edit', 'export'], 'messages' => ['view', 'edit']]],
        'safety' => ['label' => '안전관리자', 'position' => 'safety', 'scope' => 'site', 'permissions' => ['attendance' => ['view'], 'people' => ['view'], 'safety' => ['view', 'edit', 'approve'], 'reports' => ['view', 'edit'], 'documents' => ['view', 'edit'], 'messages' => ['view', 'edit']]],
        'office' => ['label' => '사무실 관리자', 'position' => 'office', 'scope' => 'company', 'permissions' => ['reports' => ['view', 'edit'], 'documents' => ['view', 'edit'], 'messages' => ['view', 'edit']]],
        'president' => ['label' => '사장', 'position' => 'general_manager', 'scope' => 'company', 'permissions' => ['attendance' => ['view', 'approve'], 'people' => ['view'], 'payroll' => ['view', 'approve'], 'finance' => ['view', 'approve'], 'contracts' => ['view', 'approve'], 'purchasing' => ['view', 'approve'], 'materials' => ['view'], 'progress' => ['view'], 'reports' => ['view', 'approve'], 'safety' => ['view'], 'documents' => ['view', 'export'], 'office' => ['view'], 'messages' => ['view', 'edit']]],
    ],
    'duties' => [
        'hr' => ['label' => '인사·총무', 'permissions' => ['people' => ['view', 'edit'], 'private_hr' => ['view', 'edit', 'export'], 'attendance' => ['view'], 'office' => ['view', 'edit']]],
        'payroll' => ['label' => '급여', 'permissions' => ['attendance' => ['view'], 'people' => ['view'], 'payroll' => ['view', 'edit', 'export']]],
        'accounting' => ['label' => '회계', 'permissions' => ['finance' => ['view', 'edit', 'export'], 'contracts' => ['view', 'edit', 'export']]],
        'purchasing' => ['label' => '구매', 'permissions' => ['purchasing' => ['view', 'edit'], 'materials' => ['view', 'edit'], 'documents' => ['view', 'edit']]],
    ],
    // Read-only and export are independently selectable. System authority is never inherited from a title.
];
