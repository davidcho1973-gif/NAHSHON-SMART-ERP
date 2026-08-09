<?php

namespace App\Support;

/**
 * 현장 작업자 화면(간편 등록 · 게이트 출퇴근)의 3개 언어 문구.
 *
 * 왜 PHP 한 곳에 모으나: 두 화면 모두 언어를 **새로고침 없이** 바꿔야 한다(등록 폼은 작업자가
 * 읽기 전에, 게이트는 본인이 인식된 직후). 그래서 사전을 통째로 화면에 실어 보내고 JS 가 바꾼다.
 * 사전이 뷰마다 흩어지면 한쪽만 번역되는 사고가 나므로 여기서만 관리한다.
 */
final class WorkerLang
{
    public const DEFAULT = 'ko';

    /** 화면에 노출하는 언어 선택지(코드 => 버튼에 찍히는 이름). */
    public const OPTIONS = [
        'ko' => '한국어',
        'en' => 'English',
        'es' => 'Español',
    ];

    /** 지원하지 않는 값이면 기본 언어로. */
    public static function resolve(mixed $lang): string
    {
        return is_string($lang) && array_key_exists($lang, self::OPTIONS) ? $lang : self::DEFAULT;
    }

    /**
     * 간편 등록 폼 문구.
     *
     * @return array<string, array<string, string>>
     */
    public static function join(): array
    {
        return [
            'ko' => [
                'eyebrow' => '작업자 간편 등록',
                'title' => '작업자 등록',
                'language' => '언어',
                'name' => '이름',
                'namePlaceholder' => '홍길동',
                'company' => '소속회사',
                'companyPlaceholder' => '선택하세요',
                'companyHint' => '소속회사를 고르면 고용 구분이 자동으로 정해집니다.',
                'askTitle' => '소속 구분을 선택해 주세요',
                'askDirect' => 'DASOL PRISM 소속',
                'askDirectSub' => 'DASOL PRISM 에서 급여를 받습니다',
                'askIndirect' => '협력업체 소속',
                'askIndirectSub' => '소속 업체에서 급여를 받습니다',
                'trade' => '공정 (Trade)',
                'tradePlaceholder' => '목록에서 선택하거나 직접 입력',
                'tradeHint' => '공정관리(WBS)의 공종 목록이며, 없으면 직접 입력하세요.',
                'email' => '이메일',
                'phone' => '전화번호',
                'submit' => '작업자로 등록하기',
                'errors' => '입력을 확인해 주세요:',
                'doneTitle' => '등록 완료!',
                'doneBody' => '님, 작업자로 등록되었습니다.',
                'doneDevice' => '이 휴대폰이 기억되었습니다. 다음부터 게이트 QR 을 스캔하면 이름을 찾지 않고 바로 출퇴근할 수 있습니다.',
                'doneBadge' => '사번',
                'labelDirect' => 'DASOL PRISM 소속(직접고용)',
                'labelIndirect' => '협력사 소속(간접고용)',
                'labelClient' => '원청 담당자',
                'suffixRegistered' => ' 으로 등록됩니다.',
            ],
            'en' => [
                'eyebrow' => 'Quick Worker Sign-Up',
                'title' => 'Worker Registration',
                'language' => 'Language',
                'name' => 'Full name',
                'namePlaceholder' => 'John Smith',
                'company' => 'Company',
                'companyPlaceholder' => 'Select',
                'companyHint' => 'Pick your company and your employment type is set automatically.',
                'askTitle' => 'Who pays your wages?',
                'askDirect' => 'DASOL PRISM',
                'askDirectSub' => 'I am paid by DASOL PRISM',
                'askIndirect' => 'Subcontractor',
                'askIndirectSub' => 'I am paid by my own company',
                'trade' => 'Trade',
                'tradePlaceholder' => 'Pick from the list or type your own',
                'tradeHint' => 'Trades come from the WBS schedule. Type your own if it is missing.',
                'email' => 'Email',
                'phone' => 'Phone',
                'submit' => 'Register as worker',
                'errors' => 'Please check your entries:',
                'doneTitle' => 'You are registered!',
                'doneBody' => ', you are now registered as a worker.',
                'doneDevice' => 'This phone is remembered. Next time, just scan the gate QR — no need to look up your name.',
                'doneBadge' => 'Employee no.',
                'labelDirect' => 'DASOL PRISM (direct hire)',
                'labelIndirect' => 'Subcontractor (indirect)',
                'labelClient' => 'Client representative',
                'suffixRegistered' => ' will be recorded.',
            ],
            'es' => [
                'eyebrow' => 'Registro rápido de trabajador',
                'title' => 'Registro de trabajador',
                'language' => 'Idioma',
                'name' => 'Nombre completo',
                'namePlaceholder' => 'Juan Pérez',
                'company' => 'Empresa',
                'companyPlaceholder' => 'Seleccione',
                'companyHint' => 'Elija su empresa y el tipo de empleo se define automáticamente.',
                'askTitle' => '¿Quién le paga su salario?',
                'askDirect' => 'DASOL PRISM',
                'askDirectSub' => 'DASOL PRISM me paga',
                'askIndirect' => 'Subcontratista',
                'askIndirectSub' => 'Mi propia empresa me paga',
                'trade' => 'Oficio',
                'tradePlaceholder' => 'Elija de la lista o escriba el suyo',
                'tradeHint' => 'Los oficios vienen del cronograma (WBS). Escriba el suyo si no aparece.',
                'email' => 'Correo electrónico',
                'phone' => 'Teléfono',
                'submit' => 'Registrarme como trabajador',
                'errors' => 'Revise los datos ingresados:',
                'doneTitle' => '¡Registro completo!',
                'doneBody' => ', ya está registrado como trabajador.',
                'doneDevice' => 'Este teléfono quedó registrado. La próxima vez solo escanee el QR de la entrada; no tendrá que buscar su nombre.',
                'doneBadge' => 'N.º de empleado',
                'labelDirect' => 'DASOL PRISM (contratación directa)',
                'labelIndirect' => 'Subcontratista (indirecta)',
                'labelClient' => 'Representante del cliente',
                'suffixRegistered' => ' será el registro.',
            ],
        ];
    }

    /**
     * 인쇄 포스터 문구 — 벽에 붙는 종이라 언어 전환 버튼이 없다. 3개 언어를 한 장에 함께 찍는다.
     *
     * @return array<string, array<string, array<string, mixed>>> [포스터 키][언어] => title/hint/steps
     */
    public static function poster(): array
    {
        return [
            'gate' => [
                'ko' => [
                    'title' => '출근 · 퇴근',
                    'hint' => '출입할 때 휴대폰 카메라로 이 QR 을 스캔하세요. (앱 설치·로그인 불필요)',
                    'steps' => [
                        '휴대폰 카메라로 QR 코드를 스캔합니다.',
                        '이름을 입력해 본인을 선택합니다. (한 번 등록하면 다음부터 자동)',
                        '출근하기 / 퇴근하기 버튼을 누르면 끝.',
                    ],
                ],
                'en' => [
                    'title' => 'Clock In / Clock Out',
                    'hint' => 'Scan this QR with your phone camera when you enter or leave. No app, no login.',
                    'steps' => [
                        'Scan the QR code with your phone camera.',
                        'Type your name and pick yourself. (Remembered from next time)',
                        'Tap CLOCK IN or CLOCK OUT. Done.',
                    ],
                ],
                'es' => [
                    'title' => 'Entrada / Salida',
                    'hint' => 'Escanee este QR con la cámara de su teléfono al entrar y salir. Sin app ni contraseña.',
                    'steps' => [
                        'Escanee el código QR con la cámara.',
                        'Escriba su nombre y elíjase. (Se recuerda la próxima vez)',
                        'Pulse ENTRADA o SALIDA. Listo.',
                    ],
                ],
            ],
            'join' => [
                'ko' => [
                    'title' => '작업자 간편 등록',
                    'hint' => '자사·협력사 모두 이 QR 하나로 등록합니다.',
                    'steps' => [
                        '휴대폰 카메라로 QR 코드를 스캔합니다.',
                        '이름·소속회사·공정·이메일·전화번호를 입력합니다.',
                        '등록 완료 — 바로 현장 출퇴근을 시작할 수 있습니다.',
                    ],
                ],
                'en' => [
                    'title' => 'Worker Sign-Up',
                    'hint' => 'One QR for everyone — our own crew and subcontractors.',
                    'steps' => [
                        'Scan the QR code with your phone camera.',
                        'Enter your name, company, trade, email and phone.',
                        'Done — you can start clocking in right away.',
                    ],
                ],
                'es' => [
                    'title' => 'Registro de trabajador',
                    'hint' => 'Un solo QR para todos: personal propio y subcontratistas.',
                    'steps' => [
                        'Escanee el código QR con la cámara.',
                        'Ingrese su nombre, empresa, oficio, correo y teléfono.',
                        'Listo — ya puede registrar su entrada.',
                    ],
                ],
            ],
            'apply' => [
                'ko' => [
                    'title' => '입사 지원서',
                    'hint' => '신분증·경력 등을 포함한 정식 입사지원서입니다.',
                    'steps' => [
                        '휴대폰 카메라로 QR 코드를 스캔합니다.',
                        '인적사항과 서류를 입력하고 제출합니다.',
                        '관리자 검토 후 등록이 완료됩니다.',
                    ],
                ],
                'en' => [
                    'title' => 'Employment Application',
                    'hint' => 'Full application including ID and work history.',
                    'steps' => [
                        'Scan the QR code with your phone camera.',
                        'Fill in your details and upload documents.',
                        'A manager reviews it and completes your registration.',
                    ],
                ],
                'es' => [
                    'title' => 'Solicitud de empleo',
                    'hint' => 'Solicitud completa con identificación e historial laboral.',
                    'steps' => [
                        'Escanee el código QR con la cámara.',
                        'Complete sus datos y suba los documentos.',
                        'Un supervisor lo revisa y completa su registro.',
                    ],
                ],
            ],
        ];
    }

    /**
     * 게이트 포스터의 IN/OUT 태그 — 세 언어를 한 칩에 담는다.
     *
     * @return array<int, array{label: string, class: string}>
     */
    public static function gateTags(): array
    {
        return [
            ['label' => '출근 · IN · ENTRADA', 'class' => 'in'],
            ['label' => '퇴근 · OUT · SALIDA', 'class' => 'out'],
        ];
    }

    /**
     * 게이트 출퇴근 화면 문구.
     *
     * @return array<string, array<string, string>>
     */
    public static function gate(): array
    {
        return [
            'ko' => [
                'title' => '현장 출퇴근',
                'searchLabel' => '이름으로 본인을 찾으세요',
                'searchPlaceholder' => '이름 입력 (예: 김철수)',
                'searchEmpty' => '이름을 입력하면 목록이 나옵니다.',
                'searching' => '검색 중…',
                'noMatch' => '일치하는 작업자가 없습니다.\n등록되지 않았다면 먼저 간편등록을 하세요.',
                'searchError' => '검색 오류. 다시 시도하세요.',
                'pick' => '선택 ›',
                'clockIn' => '🟢 출근하기',
                'clockOut' => '🔴 퇴근하기',
                'working' => '처리 중…',
                'other' => '← 다른 사람',
                'notMe' => '내가 아닙니다 (기기 기억 해제)',
                'remember' => '이 휴대폰을 내 것으로 기억하기',
                'remembered' => '✓ 기억했습니다. 다음부터 바로 출퇴근합니다.',
                'onDuty' => '근무중 (출근',
                'offDuty' => '퇴근 완료',
                'noRecord' => '오늘 기록 없음',
                'doneIn' => '출근 완료',
                'doneOut' => '퇴근 완료',
                'alreadyDone' => '이미 처리됨',
                'recorded' => '기록',
                'offSite' => '· ⚠ 현장 밖에서 스캔됨',
                'home' => '처음으로',
                'failed' => '처리 실패. 다시 시도하세요.',
                'network' => '네트워크 오류. 다시 시도하세요.',
                'recognized' => '✓ 이 휴대폰으로 기억된 본인',
            ],
            'en' => [
                'title' => 'Site Clock In / Out',
                'searchLabel' => 'Find yourself by name',
                'searchPlaceholder' => 'Type your name',
                'searchEmpty' => 'Type your name to see the list.',
                'searching' => 'Searching…',
                'noMatch' => 'No matching worker.\nIf you are not registered, sign up first.',
                'searchError' => 'Search failed. Please try again.',
                'pick' => 'Select ›',
                'clockIn' => '🟢 CLOCK IN',
                'clockOut' => '🔴 CLOCK OUT',
                'working' => 'Working…',
                'other' => '← Someone else',
                'notMe' => 'Not me (forget this phone)',
                'remember' => 'Remember this phone as mine',
                'remembered' => '✓ Remembered. Next time you go straight in.',
                'onDuty' => 'On duty (in at',
                'offDuty' => 'Clocked out',
                'noRecord' => 'No record today',
                'doneIn' => 'clocked in',
                'doneOut' => 'clocked out',
                'alreadyDone' => 'Already recorded',
                'recorded' => 'recorded',
                'offSite' => '· ⚠ scanned off site',
                'home' => 'Start over',
                'failed' => 'Could not record. Please try again.',
                'network' => 'Network error. Please try again.',
                'recognized' => '✓ Recognized on this phone',
            ],
            'es' => [
                'title' => 'Entrada / Salida de obra',
                'searchLabel' => 'Búsquese por su nombre',
                'searchPlaceholder' => 'Escriba su nombre',
                'searchEmpty' => 'Escriba su nombre para ver la lista.',
                'searching' => 'Buscando…',
                'noMatch' => 'No se encontró ningún trabajador.\nSi no está registrado, regístrese primero.',
                'searchError' => 'Error de búsqueda. Intente de nuevo.',
                'pick' => 'Elegir ›',
                'clockIn' => '🟢 ENTRADA',
                'clockOut' => '🔴 SALIDA',
                'working' => 'Procesando…',
                'other' => '← Otra persona',
                'notMe' => 'No soy yo (olvidar este teléfono)',
                'remember' => 'Recordar este teléfono como mío',
                'remembered' => '✓ Guardado. La próxima vez entra directo.',
                'onDuty' => 'En obra (entrada',
                'offDuty' => 'Salida registrada',
                'noRecord' => 'Sin registro hoy',
                'doneIn' => 'entrada registrada',
                'doneOut' => 'salida registrada',
                'alreadyDone' => 'Ya registrado',
                'recorded' => 'registrado',
                'offSite' => '· ⚠ escaneado fuera de la obra',
                'home' => 'Volver al inicio',
                'failed' => 'No se pudo registrar. Intente de nuevo.',
                'network' => 'Error de red. Intente de nuevo.',
                'recognized' => '✓ Reconocido en este teléfono',
            ],
        ];
    }
}
