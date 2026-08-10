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
                'companyOther' => '목록에 없음 — 직접 입력',
                'companyOtherPlaceholder' => '회사 이름을 적어 주세요',
                'companyOtherHint' => '처음 등록되는 회사입니다. 아래에서 누가 급여를 주는지 골라 주세요.',
                'askTitle' => '소속 구분을 선택해 주세요',
                'askDirect' => 'DASOL PRISM 소속',
                'askDirectSub' => 'DASOL PRISM 에서 급여를 받습니다',
                'askIndirect' => '협력업체 소속',
                'askIndirectSub' => '소속 업체에서 급여를 받습니다',
                'trade' => '공정 (Trade)',
                'tradePlaceholder' => '공정을 선택하세요',
                'tradeHint' => '목록에서 고르거나, 없으면 직접 적어 주세요.',
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
                'companyOther' => 'Not on the list — type it in',
                'companyOtherPlaceholder' => 'Type your company name',
                'companyOtherHint' => 'This company is new to us. Please choose below who pays your wages.',
                'askTitle' => 'Who pays your wages?',
                'askDirect' => 'DASOL PRISM',
                'askDirectSub' => 'I am paid by DASOL PRISM',
                'askIndirect' => 'Subcontractor',
                'askIndirectSub' => 'I am paid by my own company',
                'trade' => 'Trade',
                'tradePlaceholder' => 'Select your trade',
                'tradeHint' => 'Pick from the list, or type yours if it is not there.',
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
                'companyOther' => 'No está en la lista — escríbala',
                'companyOtherPlaceholder' => 'Escriba el nombre de su empresa',
                'companyOtherHint' => 'Esta empresa es nueva para nosotros. Elija abajo quién le paga su salario.',
                'askTitle' => '¿Quién le paga su salario?',
                'askDirect' => 'DASOL PRISM',
                'askDirectSub' => 'DASOL PRISM me paga',
                'askIndirect' => 'Subcontratista',
                'askIndirectSub' => 'Mi propia empresa me paga',
                'trade' => 'Oficio',
                'tradePlaceholder' => 'Seleccione su oficio',
                'tradeHint' => 'Elija de la lista, o escriba el suyo si no aparece.',
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
                        '한 번 찍고 나면 “홈 화면에 추가” 안내가 뜹니다 — 추가하면 다음부터 QR 없이 아이콘만 누르면 됩니다.',
                    ],
                ],
                'en' => [
                    'title' => 'Clock In / Clock Out',
                    'hint' => 'Scan this QR with your phone camera when you enter or leave. No app, no login.',
                    'steps' => [
                        'Scan the QR code with your phone camera.',
                        'Type your name and pick yourself. (Remembered from next time)',
                        'Tap CLOCK IN or CLOCK OUT. Done.',
                        'After your first punch you will be offered "Add to Home Screen" — add it and next time just tap the icon, no QR needed.',
                    ],
                ],
                'es' => [
                    'title' => 'Entrada / Salida',
                    'hint' => 'Escanee este QR con la cámara de su teléfono al entrar y salir. Sin app ni contraseña.',
                    'steps' => [
                        'Escanee el código QR con la cámara.',
                        'Escriba su nombre y elíjase. (Se recuerda la próxima vez)',
                        'Pulse ENTRADA o SALIDA. Listo.',
                        'Después del primer registro le ofrecerá "Agregar a la pantalla de inicio" — agréguelo y la próxima vez solo toque el ícono, sin QR.',
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

    /**
     * 직영 작업자에게 건네는 앱 설치 카드 문구.
     *
     * 게이트(협력사)와 다르다 — 이쪽은 사람이 정해져 있고 계정이 있다. 그래서 가장 흔한
     * 실패는 "설치를 못 한다"가 아니라 <b>어느 구글 계정으로 로그인해야 하는지 모른다</b>
     * 이다. 휴대폰에 구글 계정이 두세 개 들어 있는 경우가 많다. 그래서 카드에 본인
     * 이메일을 찍어 준다.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function installCard(): array
    {
        return [
            'ko' => [
                'title' => '내 출퇴근 앱',
                'hint' => '내 근무시간과 급여를 내 휴대폰에서 바로 봅니다.',
                'account' => '로그인할 구글 계정',
                'steps' => [
                    '휴대폰 카메라로 위 QR 을 스캔합니다.',
                    '구글로 로그인 — 반드시 아래 적힌 계정으로 하세요.',
                    '"홈 화면에 추가" 안내가 뜨면 추가합니다. 다음부터는 아이콘만 누르면 됩니다.',
                ],
                'trouble' => '로그인이 안 되면 현장 관리자에게 말씀하세요. 계정 등록이 필요할 수 있습니다.',
            ],
            'en' => [
                'title' => 'My Attendance App',
                'hint' => 'See your hours and your pay on your own phone.',
                'account' => 'Sign in with this Google account',
                'steps' => [
                    'Scan the QR code above with your phone camera.',
                    'Sign in with Google — you must use the account printed below.',
                    'When it offers "Add to Home Screen", add it. Next time just tap the icon.',
                ],
                'trouble' => 'If sign-in fails, tell your site manager. Your account may need to be set up.',
            ],
            'es' => [
                'title' => 'Mi aplicación de asistencia',
                'hint' => 'Vea sus horas y su pago en su propio teléfono.',
                'account' => 'Inicie sesión con esta cuenta de Google',
                'steps' => [
                    'Escanee el código QR de arriba con la cámara de su teléfono.',
                    'Inicie sesión con Google — debe usar la cuenta impresa abajo.',
                    'Cuando ofrezca "Agregar a la pantalla de inicio", agréguelo. La próxima vez solo toque el ícono.',
                ],
                'trouble' => 'Si no puede iniciar sesión, avise a su supervisor. Puede que falte registrar su cuenta.',
            ],
        ];
    }

    /**
     * "홈 화면에 추가" 안내 문구.
     *
     * 이 문구가 필요한 이유 — 작업자에게 이건 웹사이트가 아니라 앱이어야 한다. 매번
     * 게이트 QR 을 찾아 스캔하게 두면 셋째 날부터 안 찍는다. 홈 화면에 아이콘이 있으면
     * 한 번 눌러서 열고, 주소창도 안 보여서 앱과 구별되지 않는다.
     *
     * 안드로이드와 아이폰의 문구가 다른 이유 — 안드로이드는 브라우저가 설치 버튼을
     * 우리에게 넘겨주지만(누르면 끝), 아이폰 사파리는 그런 것을 주지 않는다. 사람이
     * 공유 버튼을 직접 눌러야 해서, 어느 버튼인지 그림으로 짚어 줘야 한다.
     *
     * @return array<string, array<string, string>>
     */
    public static function install(): array
    {
        return [
            'ko' => [
                'title' => '이 화면을 앱처럼 쓰세요',
                'body' => '휴대폰 홈 화면에 아이콘이 생깁니다. 다음부터는 QR 을 찾지 않고 아이콘만 누르면 됩니다.',
                'install' => '홈 화면에 추가',
                'later' => '나중에',
                'iosTitle' => '아이폰에서 추가하는 방법',
                'iosStep1' => '아래 가운데 <b>공유</b> 버튼을 누릅니다',
                'iosStep2' => '목록을 내려 <b>홈 화면에 추가</b> 를 누릅니다',
                'iosStep3' => '오른쪽 위 <b>추가</b> 를 누르면 끝입니다',
                'iosSafari' => '공유 목록에 <b>홈 화면에 추가</b> 가 안 보이면, 이 주소를 사파리(Safari)로 열어 다시 해 보세요.',
                'done' => '홈 화면에 추가되었습니다.',
                'close' => '닫기',
            ],
            'en' => [
                'title' => 'Use this like an app',
                'body' => 'An icon is added to your phone\'s home screen. Next time just tap the icon — no QR to find.',
                'install' => 'Add to Home Screen',
                'later' => 'Later',
                'iosTitle' => 'How to add it on iPhone',
                'iosStep1' => 'Tap the <b>Share</b> button at the bottom center',
                'iosStep2' => 'Scroll down and tap <b>Add to Home Screen</b>',
                'iosStep3' => 'Tap <b>Add</b> at the top right — done',
                'iosSafari' => 'If you do not see <b>Add to Home Screen</b> in the share list, open this address in Safari and try again.',
                'done' => 'Added to your home screen.',
                'close' => 'Close',
            ],
            'es' => [
                'title' => 'Úselo como una aplicación',
                'body' => 'Se agrega un ícono a la pantalla de inicio de su teléfono. La próxima vez solo toque el ícono — sin buscar el código QR.',
                'install' => 'Agregar a la pantalla de inicio',
                'later' => 'Más tarde',
                'iosTitle' => 'Cómo agregarlo en iPhone',
                'iosStep1' => 'Toque el botón <b>Compartir</b> abajo al centro',
                'iosStep2' => 'Baje y toque <b>Agregar a inicio</b>',
                'iosStep3' => 'Toque <b>Agregar</b> arriba a la derecha — listo',
                'iosSafari' => 'Si no ve <b>Agregar a inicio</b> en la lista de compartir, abra esta dirección en Safari e intente de nuevo.',
                'done' => 'Agregado a su pantalla de inicio.',
                'close' => 'Cerrar',
            ],
        ];
    }
}
