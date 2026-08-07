/**
 * 관리자(Filament) 페이지 다국어 — 한글 라벨을 선택 언어(en/es)로 DOM 번역한다.
 * Filament 자체 UI는 서버 로케일(쿠키 app_locale + SetLocale 미들웨어)로 번역되고,
 * 이 스크립트는 커스텀 한글 라벨/네비/폼/테이블/팝업(모달)을 사전 기반으로 치환한다.
 * MutationObserver 로 Livewire 갱신·모달 등 동적 추가 요소도 자동 반영한다.
 */
(function () {
  'use strict';

  var COOKIE = 'app_locale';
  var SUPPORTED = ['ko', 'en', 'es'];

  function getCookie(name) {
    var m = document.cookie.match('(?:^|; )' + name + '=([^;]*)');
    return m ? decodeURIComponent(m[1]) : '';
  }
  function setCookie(name, val) {
    document.cookie = name + '=' + encodeURIComponent(val) + ';path=/;max-age=31536000;samesite=lax';
  }
  function currentLocale() {
    var l = getCookie(COOKIE) || localStorage.getItem('smartCompanyLanguage') || 'ko';
    return SUPPORTED.indexOf(l) >= 0 ? l : 'ko';
  }

  // 한글 → { en, es }. 키 재사용(예 '상태')으로 전 화면 공통 적용. window.__ADMIN_I18N_DICT 로 확장 가능.
  var DICT = Object.assign({
    // ── 네비게이션 / 모듈 / 페이지 제목 ──
    'SMART COMPANY': { en: 'SMART COMPANY', es: 'SMART COMPANY' },
    '대시보드': { en: 'Dashboard', es: 'Panel' },
    '현장 / PROJECT 관리': { en: 'Sites / Projects', es: 'Obras / Proyectos' },
    '현장 / PROJECT': { en: 'Site / Project', es: 'Obra / Proyecto' },
    '현장 / PROJECT 목록': { en: 'Sites / Projects', es: 'Obras / Proyectos' },
    '현장 관리 (Sites)': { en: 'Site Management', es: 'Gestión de Obras' },
    '현장 관리': { en: 'Site Management', es: 'Gestión de Obras' },
    '현장 통합 관리': { en: 'Site Management', es: 'Gestión de Obras' },
    'PROJECT 관리': { en: 'Project Management', es: 'Gestión de Proyectos' },
    'PROJECT 관리 (Projects)': { en: 'Projects', es: 'Proyectos' },
    'PROJECT 목록': { en: 'Projects', es: 'Proyectos' },
    'PROJECT 만들기': { en: 'New Project', es: 'Nuevo Proyecto' },
    'PROJECT 추가': { en: 'Add Project', es: 'Añadir Proyecto' },
    'PROJECT명': { en: 'Project Name', es: 'Nombre del Proyecto' },
    '인원 관리': { en: 'Personnel', es: 'Personal' },
    '직원 관리 (Employees)': { en: 'Employees', es: 'Empleados' },
    '회원 등록': { en: 'Member Registration', es: 'Registro' },
    '회원 등록 (Applicants)': { en: 'Applicants', es: 'Solicitantes' },
    '입사지원서 (Applicants)': { en: 'Applicants', es: 'Solicitantes' },
    '접근 제어': { en: 'Access Control', es: 'Control de Acceso' },
    '접근 제어 (Access)': { en: 'Access Control', es: 'Control de Acceso' },
    '급여 (Payroll)': { en: 'Payroll', es: 'Nómina' },
    '임금 프로필': { en: 'Pay Profiles', es: 'Perfiles de Pago' },
    '임금 프로필 (Pay Profiles)': { en: 'Pay Profiles', es: 'Perfiles de Pago' },
    '문서 관리': { en: 'Documents', es: 'Documentos' },
    'HR Documents': { en: 'HR Documents', es: 'Documentos RR.HH.' },
    '출퇴근 기록': { en: 'Attendance', es: 'Asistencia' },
    '출퇴근 기록 추가': { en: 'Add Attendance', es: 'Añadir Asistencia' },
    '거래처 마스터': { en: 'Vendor Master', es: 'Maestro de Proveedores' },
    'PROJECT': { en: 'PROJECT', es: 'PROYECTO' },

    // ── 공통 액션 ──
    '저장': { en: 'Save', es: 'Guardar' },
    '저장 후 계속 편집': { en: 'Save & continue editing', es: 'Guardar y seguir editando' },
    '취소': { en: 'Cancel', es: 'Cancelar' },
    '삭제': { en: 'Delete', es: 'Eliminar' },
    '수정': { en: 'Edit', es: 'Editar' },
    '편집': { en: 'Edit', es: 'Editar' },
    '생성': { en: 'Create', es: 'Crear' },
    '추가': { en: 'Add', es: 'Añadir' },
    '등록': { en: 'Register', es: 'Registrar' },
    '검색': { en: 'Search', es: 'Buscar' },
    '필터': { en: 'Filters', es: 'Filtros' },
    '내보내기': { en: 'Export', es: 'Exportar' },
    '확인': { en: 'Confirm', es: 'Confirmar' },
    '닫기': { en: 'Close', es: 'Cerrar' },
    '시작': { en: 'Start', es: 'Iniciar' },
    '종료': { en: 'End', es: 'Fin' },
    '관리': { en: 'Manage', es: 'Gestionar' },
    '보기': { en: 'View', es: 'Ver' },
    '메모': { en: 'Note', es: 'Nota' },
    '비고': { en: 'Remarks', es: 'Observaciones' },
    '안내:': { en: 'Notice:', es: 'Aviso:' },
    '새 회사 추가': { en: 'Add New Company', es: 'Añadir Empresa' },
    '새 회사 등록': { en: 'Register New Company', es: 'Registrar Empresa' },
    '기존 회사 연결': { en: 'Link Existing Company', es: 'Vincular Empresa' },

    // ── 상태 / 뱃지 / 단계 ──
    '활성': { en: 'Active', es: 'Activo' },
    '비활성': { en: 'Inactive', es: 'Inactivo' },
    '대기': { en: 'Waiting', es: 'En Espera' },
    '대기중': { en: 'Pending', es: 'Pendiente' },
    '진행중': { en: 'In Progress', es: 'En Progreso' },
    '진행 (In Progress)': { en: 'In Progress', es: 'En Progreso' },
    '완료': { en: 'Done', es: 'Completado' },
    '승인': { en: 'Approve', es: 'Aprobar' },
    '승인완료': { en: 'Approved', es: 'Aprobado' },
    '반려': { en: 'Rejected', es: 'Rechazado' },
    '불합격': { en: 'Failed', es: 'No Apto' },
    '시작전': { en: 'Not Started', es: 'No Iniciado' },
    '미분석': { en: 'Not Analyzed', es: 'Sin Analizar' },
    '미생성': { en: 'Not Created', es: 'No Creado' },
    '출근': { en: 'Clock In', es: 'Entrada' },
    '퇴근': { en: 'Clock Out', es: 'Salida' },
    '단계': { en: 'Stage', es: 'Etapa' },
    '지원 현황': { en: 'Application Status', es: 'Estado de Solicitud' },
    '인터뷰 합격': { en: 'Interview Passed', es: 'Entrevista Aprobada' },

    // ── 컬럼 / 공통 필드 ──
    '형태': { en: 'Type', es: 'Tipo' },
    '구분': { en: 'Category', es: 'Categoría' },
    '분류 / 파견 (Classification)': { en: 'Classification', es: 'Clasificación' },
    '기준 임금': { en: 'Base Wage', es: 'Salario Base' },
    '기준 임금 / Base rate': { en: 'Base Rate', es: 'Tarifa Base' },
    '직군': { en: 'Division', es: 'División' },
    '직군 / Division': { en: 'Division', es: 'División' },
    '파견': { en: 'Dispatch', es: 'Despliegue' },
    '현장': { en: 'Site', es: 'Obra' },
    '현장 (Site)': { en: 'Site', es: 'Obra' },
    '날짜': { en: 'Date', es: 'Fecha' },
    '시각': { en: 'Time', es: 'Hora' },
    '기록 시각': { en: 'Recorded At', es: 'Registrado' },
    '기록 방식': { en: 'Method', es: 'Método' },
    '방식': { en: 'Method', es: 'Método' },
    '코드': { en: 'Code', es: 'Código' },
    '이름': { en: 'Name', es: 'Nombre' },
    '성': { en: 'Last Name', es: 'Apellido' },
    '성명': { en: 'Full Name', es: 'Nombre Completo' },
    '상태': { en: 'Status', es: 'Estado' },
    '회사': { en: 'Company', es: 'Empresa' },
    '회사명': { en: 'Company Name', es: 'Nombre de Empresa' },
    '회사 코드': { en: 'Company Code', es: 'Código de Empresa' },
    '회사 역할': { en: 'Company Role', es: 'Rol de Empresa' },
    '법인명': { en: 'Legal Entity', es: 'Razón Social' },
    '대표 관리 회사 (소속사)': { en: 'Managing Company (Employer)', es: 'Empresa Gestora (Empleador)' },
    '원청사 (발주처/Client)': { en: 'Client (General Contractor)', es: 'Cliente (Contratista)' },
    '상위 원청사': { en: 'Upper Contractor', es: 'Contratista Principal' },
    '협력사': { en: 'Subcontractor', es: 'Subcontratista' },
    '하청사': { en: 'Subcontractor', es: 'Subcontratista' },
    '벤더': { en: 'Vendor', es: 'Proveedor' },
    '벤더 차수': { en: 'Vendor Tier', es: 'Nivel de Proveedor' },
    '벤더 차수 (Tier)': { en: 'Vendor Tier', es: 'Nivel de Proveedor' },
    '벤더 등급': { en: 'Vendor Tier', es: 'Nivel de Proveedor' },
    '국가': { en: 'Country', es: 'País' },
    '국가 (Country)': { en: 'Country', es: 'País' },
    '타임존': { en: 'Timezone', es: 'Zona Horaria' },
    '통화': { en: 'Currency', es: 'Moneda' },
    '현장 주소': { en: 'Site Address', es: 'Dirección de Obra' },
    '주소': { en: 'Address', es: 'Dirección' },
    '직원': { en: 'Employee', es: 'Empleado' },
    '대상 직원': { en: 'Employee', es: 'Empleado' },
    '직종': { en: 'Trade', es: 'Oficio' },
    '직종 / Trade': { en: 'Trade', es: 'Oficio' },
    '팀': { en: 'Team', es: 'Equipo' },
    '팀 / 조직': { en: 'Team / Org', es: 'Equipo / Org.' },
    '팀/조직': { en: 'Teams / Org', es: 'Equipos / Org.' },
    '담당 팀': { en: 'Team', es: 'Equipo' },
    '담당 팀 (Team)': { en: 'Team', es: 'Equipo' },
    '팀명': { en: 'Team Name', es: 'Nombre de Equipo' },
    '팀 코드': { en: 'Team Code', es: 'Código de Equipo' },
    '팀 메모': { en: 'Team Note', es: 'Nota de Equipo' },
    '팀 목록': { en: 'Teams', es: 'Equipos' },
    '팀 추가': { en: 'Add Team', es: 'Añadir Equipo' },
    '소속': { en: 'Affiliation', es: 'Afiliación' },
    '소속 계약 회사명': { en: 'Employer Company', es: 'Empresa Empleadora' },
    '이메일': { en: 'Email', es: 'Correo' },
    '전화번호': { en: 'Phone', es: 'Teléfono' },
    '담당자': { en: 'Manager', es: 'Responsable' },
    '담당자 전화': { en: 'Manager Phone', es: 'Tel. Responsable' },
    '책임자': { en: 'Supervisor', es: 'Supervisor' },
    '반장': { en: 'Foreman', es: 'Capataz' },
    '연락처': { en: 'Contact', es: 'Contacto' },
    '국적': { en: 'Nationality', es: 'Nacionalidad' },
    '비자 (Visa)': { en: 'Visa', es: 'Visa' },
    '비자 / Visa': { en: 'Visa', es: 'Visa' },
    '신분증 (ID)': { en: 'ID', es: 'Identificación' },
    '역할': { en: 'Role', es: 'Rol' },
    '권한': { en: 'Permission', es: 'Permiso' },
    '로그인 권한': { en: 'Login Role', es: 'Rol de Acceso' },
    '관리자 역할': { en: 'Admin Role', es: 'Rol de Administrador' },
    '계정 유형': { en: 'Account Type', es: 'Tipo de Cuenta' },
    '로그인 계정': { en: 'Login Account', es: 'Cuenta de Acceso' },
    '비밀번호': { en: 'Password', es: 'Contraseña' },
    '시작일': { en: 'Start Date', es: 'Fecha de Inicio' },
    '종료일': { en: 'End Date', es: 'Fecha de Fin' },
    '계약 시작일': { en: 'Contract Start', es: 'Inicio de Contrato' },
    '계약 종료일': { en: 'Contract End', es: 'Fin de Contrato' },
    '수정일': { en: 'Updated', es: 'Actualizado' },

    // ── 폼 섹션 / 그룹 ──
    '기본': { en: 'Basic', es: 'Básico' },
    '기본정보': { en: 'Basic Info', es: 'Información Básica' },
    '현장 기본정보': { en: 'Site Basic Info', es: 'Información de Obra' },
    '계약': { en: 'Contract', es: 'Contrato' },
    '계약 형태': { en: 'Contract Type', es: 'Tipo de Contrato' },
    '계약 회사': { en: 'Contract Company', es: 'Empresa Contratante' },
    '계약 회사명': { en: 'Contract Company', es: 'Empresa Contratante' },
    '계약 회사 목록': { en: 'Contract Companies', es: 'Empresas Contratantes' },
    '계약 회사 추가': { en: 'Add Contract Company', es: 'Añadir Empresa' },
    '계약금액': { en: 'Contract Amount', es: 'Monto del Contrato' },
    '계약(수주)금액': { en: 'Contract Amount', es: 'Monto del Contrato' },
    '재무 WBS': { en: 'Finance / WBS', es: 'Finanzas / EDT' },
    '규정/리소스': { en: 'Compliance / Resources', es: 'Normas / Recursos' },
    '현장 일정': { en: 'Site Schedule', es: 'Cronograma' },
    '현장 인력 계획': { en: 'Workforce Plan', es: 'Plan de Personal' },
    '공사 유형': { en: 'Construction Type', es: 'Tipo de Construcción' },
    '공종': { en: 'Construction Type', es: 'Tipo de Construcción' },
    '공사 범위': { en: 'Scope of Work', es: 'Alcance del Trabajo' },
    '공사 범위 (Scope of Work)': { en: 'Scope of Work', es: 'Alcance del Trabajo' },
    '프로젝트명': { en: 'Project Name', es: 'Nombre del Proyecto' },
    '프로젝트 코드': { en: 'Project Code', es: 'Código de Proyecto' },
    '프로젝트 단계': { en: 'Project Stage', es: 'Etapa del Proyecto' },
    '프로젝트 담당자 / PM': { en: 'Project Manager / PM', es: 'Gerente de Proyecto' },
    '주요 마일스톤': { en: 'Key Milestones', es: 'Hitos Clave' },
    '준공 예정일': { en: 'Planned Completion', es: 'Finalización Prevista' },
    '준공 예정': { en: 'Planned Completion', es: 'Finalización Prevista' },
    '실제 준공일': { en: 'Actual Completion', es: 'Finalización Real' },
    '예산 - 노무': { en: 'Budget - Labor', es: 'Presupuesto - Mano de Obra' },
    '예산 - 자재': { en: 'Budget - Material', es: 'Presupuesto - Material' },
    '예산 - 장비': { en: 'Budget - Equipment', es: 'Presupuesto - Equipo' },
    '예산 - 경비': { en: 'Budget - Expense', es: 'Presupuesto - Gastos' },
    '예상 인원': { en: 'Planned Headcount', es: 'Personal Previsto' },
    '보험 요구사항': { en: 'Insurance Requirements', es: 'Requisitos de Seguro' },
    '세금 / 공제 (Withholding)': { en: 'Tax / Withholding', es: 'Impuestos / Retención' },
    '연방 신고 상태': { en: 'Federal Filing Status', es: 'Estado Fiscal Federal' },
    '원천징수 주 (State)': { en: 'Withholding State', es: 'Estado de Retención' },

    // ── 임금/급여 ──
    '임금 형태': { en: 'Pay Type', es: 'Tipo de Pago' },
    '임금 형태 / Pay type': { en: 'Pay Type', es: 'Tipo de Pago' },
    '임금 기준 (Wage basis)': { en: 'Wage Basis', es: 'Base Salarial' },
    'OT 배수': { en: 'OT Multiplier', es: 'Multiplicador OT' },
    '시급': { en: 'Hourly', es: 'Por Hora' },
    '시급 (Hourly)': { en: 'Hourly', es: 'Por Hora' },
    '일급': { en: 'Daily', es: 'Diario' },
    '일급 (Daily)': { en: 'Daily', es: 'Diario' },
    '연봉': { en: 'Salary', es: 'Salario' },
    '연봉 (Salary)': { en: 'Salary', es: 'Salario' },
    'FLSA Exempt (OT 미적용)': { en: 'FLSA Exempt', es: 'Exento FLSA' },
    'Per Diem (비과세 일당)': { en: 'Per Diem', es: 'Viáticos' },

    // ── 직군/국적 ──
    '관리자': { en: 'Manager', es: 'Gerente' },
    '관리자 (Manager)': { en: 'Manager', es: 'Gerente' },
    '한국인': { en: 'Korean', es: 'Coreano' },
    '한국인 (Korean)': { en: 'Korean', es: 'Coreano' },
    '외국인': { en: 'Foreign', es: 'Extranjero' },
    '외국인 (Local)': { en: 'Local', es: 'Local' },
    '한국 파견 (Dispatched)': { en: 'Dispatched (KR)', es: 'Desplegado (KR)' },
    '한국 파견 인력 / 비자': { en: 'Dispatched Workers / Visa', es: 'Personal Desplegado / Visa' },

    // ── 공종 / 직종 (trades) ──
    '전기': { en: 'Electrical', es: 'Eléctrico' },
    '전기 (Electrical)': { en: 'Electrical', es: 'Eléctrico' },
    '전기팀': { en: 'Electrical Team', es: 'Equipo Eléctrico' },
    '배관': { en: 'Piping', es: 'Tubería' },
    '배관 (Piping)': { en: 'Piping', es: 'Tubería' },
    '배관팀': { en: 'Piping Team', es: 'Equipo de Tubería' },
    '기계설치 (Mechanical Installation)': { en: 'Mechanical Installation', es: 'Instalación Mecánica' },
    '기계설치팀': { en: 'Mechanical Team', es: 'Equipo Mecánico' },
    '리깅': { en: 'Rigging', es: 'Aparejos' },
    '리깅 (Rigging)': { en: 'Rigging', es: 'Aparejos' },
    '리깅팀': { en: 'Rigging Team', es: 'Equipo de Aparejos' },
    '용접': { en: 'Welding', es: 'Soldadura' },
    '용접 (Welding)': { en: 'Welding', es: 'Soldadura' },
    '용접팀': { en: 'Welding Team', es: 'Equipo de Soldadura' },
    '강구조 (Structural Steel)': { en: 'Structural Steel', es: 'Acero Estructural' },
    '장비설치 (Equipment Setting)': { en: 'Equipment Setting', es: 'Montaje de Equipos' },
    '시운전 지원 (Commissioning Support)': { en: 'Commissioning Support', es: 'Apoyo a Puesta en Marcha' },
    '안전': { en: 'Safety', es: 'Seguridad' },
    '안전 (Safety)': { en: 'Safety', es: 'Seguridad' },
    '안전팀': { en: 'Safety Team', es: 'Equipo de Seguridad' },
    '안전용품 (PPE)': { en: 'PPE', es: 'EPP' },
    '안전교육': { en: 'Safety Training', es: 'Capacitación' },
    '플러밍': { en: 'Plumbing', es: 'Plomería' },
    '플러밍 (Plumbing)': { en: 'Plumbing', es: 'Plomería' },

    // ── 장비/자재 카테고리 ──
    '자재 (Material)': { en: 'Material', es: 'Material' },
    '자재': { en: 'Material', es: 'Material' },
    '장비 (Equipment)': { en: 'Equipment', es: 'Equipo' },
    '공구 (Tool)': { en: 'Tool', es: 'Herramienta' },
    '수공구': { en: 'Hand Tool', es: 'Herramienta Manual' },
    '수공구 (Hand Tool)': { en: 'Hand Tool', es: 'Herramienta Manual' },
    '전동공구': { en: 'Power Tool', es: 'Herramienta Eléctrica' },
    '파워툴 (Power Tool)': { en: 'Power Tool', es: 'Herramienta Eléctrica' },
    '중장비': { en: 'Heavy Equipment', es: 'Maquinaria Pesada' },
    '중장비 (Heavy Equipment)': { en: 'Heavy Equipment', es: 'Maquinaria Pesada' },
    '발전/동력 (Generator & Power)': { en: 'Generator & Power', es: 'Generador y Energía' },
    '측정/계측 (Measuring)': { en: 'Measuring', es: 'Medición' },
    '컨테이너': { en: 'Container', es: 'Contenedor' },
    '크레인': { en: 'Crane', es: 'Grúa' },
    '지게차': { en: 'Forklift', es: 'Montacargas' },
    '굴착': { en: 'Excavation', es: 'Excavación' },
    '밸브': { en: 'Valve', es: 'Válvula' },
    '전선': { en: 'Wire', es: 'Cable' },
    '피스': { en: 'Fasteners', es: 'Sujetadores' },
    '체결': { en: 'Fastening', es: 'Fijación' },
    '사무실': { en: 'Office', es: 'Oficina' },
    '가설/시설 (Facility)': { en: 'Facility', es: 'Instalación' },
    '기타 (General)': { en: 'General', es: 'General' },
    '일반/기타': { en: 'General', es: 'General' },

    // ── 벤더 차수 / 계약 유형 옵션 ──
    '1차 벤더 (Tier 1)': { en: 'Tier 1', es: 'Nivel 1' },
    '2차 벤더 (Tier 2)': { en: 'Tier 2', es: 'Nivel 2' },
    '3차 벤더 (Tier 3)': { en: 'Tier 3', es: 'Nivel 3' },
    '직접 계약사': { en: 'Direct Contractor', es: 'Contratista Directo' },
    'EPC / 종합건설사': { en: 'EPC / General Contractor', es: 'EPC / Contratista General' },
    'End Client (최종 발주처)': { en: 'End Client', es: 'Cliente Final' },
    '하도급 협력사 (Sub-sub)': { en: 'Sub-sub Contractor', es: 'Sub-subcontratista' },
    'Lump Sum (정액)': { en: 'Lump Sum', es: 'Suma Alzada' },
    'Unit Price (단가)': { en: 'Unit Price', es: 'Precio Unitario' },
    'T&M (실비정산)': { en: 'Time & Material', es: 'Tiempo y Material' },
    '견적 (Estimate)': { en: 'Estimate', es: 'Estimación' },
    '수주 (Awarded)': { en: 'Awarded', es: 'Adjudicado' },
    '착공/NTP': { en: 'NTP / Start', es: 'Inicio / NTP' },
    '준공 (Completed)': { en: 'Completed', es: 'Completado' },
    '하자보수 (Warranty)': { en: 'Warranty', es: 'Garantía' },
    '종료 (Closed)': { en: 'Closed', es: 'Cerrado' },

    // ── QR / 출퇴근 ──
    'QR 스캔': { en: 'QR Scan', es: 'Escaneo QR' },
    'QR 코드': { en: 'QR Code', es: 'Código QR' },
    '현장 QR': { en: 'Site QR', es: 'QR de Obra' },
    '출퇴근 QR': { en: 'Attendance QR', es: 'QR de Asistencia' },
    'NFC 리더': { en: 'NFC Reader', es: 'Lector NFC' },
    '웹 포탈': { en: 'Web Portal', es: 'Portal Web' },
    '지원자 이름': { en: 'Applicant Name', es: 'Nombre del Solicitante' },
    '지원자 이메일': { en: 'Applicant Email', es: 'Correo del Solicitante' },
    '직원 활성화': { en: 'Activate Employee', es: 'Activar Empleado' },
    '계정 부여': { en: 'Grant Account', es: 'Otorgar Cuenta' },
    '계정 없음': { en: 'No Account', es: 'Sin Cuenta' },
    '구글 이메일': { en: 'Google Email', es: 'Correo de Google' },
    '구글 이메일 다시 입력': { en: 'Re-enter Google Email', es: 'Reingrese Correo de Google' },
    '관리자 이메일': { en: 'Admin Email', es: 'Correo del Administrador' },
    '제목 없음': { en: 'Untitled', es: 'Sin Título' },

    // ── 컬럼/필드 (bilingual & 추가) ──
    'Applicant code / 지원자 코드': { en: 'Applicant Code', es: 'Código de Solicitante' },
    'Employee / 직원': { en: 'Employee', es: 'Empleado' },
    'First name / 이름': { en: 'First Name', es: 'Nombre' },
    'Last name / 성': { en: 'Last Name', es: 'Apellido' },
    'Hire date / 입사일': { en: 'Hire Date', es: 'Fecha de Contratación' },
    'Onboarding status / 진행 상태': { en: 'Onboarding Status', es: 'Estado de Incorporación' },
    'Per Diem / 출장비 기준': { en: 'Per Diem', es: 'Viáticos' },
    '발효일 / Effective from': { en: 'Effective From', es: 'Vigente Desde' },
    'Change Order 번호 체계': { en: 'Change Order Numbering', es: 'Numeración de Órdenes de Cambio' },
    'Cost Code 체계': { en: 'Cost Code System', es: 'Sistema de Códigos de Costo' },
    'Sales/Use Tax 코드': { en: 'Sales/Use Tax Code', es: 'Código de Impuesto sobre Ventas' },
    'OSHA 안전계획': { en: 'OSHA Safety Plan', es: 'Plan de Seguridad OSHA' },
    '계약/PO 번호': { en: 'Contract / PO Number', es: 'Número de Contrato / OC' },
    '기성/청구 조건': { en: 'Billing Terms', es: 'Condiciones de Facturación' },
    '데이터 범위 (Scope)': { en: 'Data Scope', es: 'Alcance de Datos' },
    '연계 마스터 데이터': { en: 'Linked Master Data', es: 'Datos Maestros Vinculados' },
    '기본 지원서 언어': { en: 'Default Application Language', es: 'Idioma de Solicitud Predeterminado' },
    '지원서 언어': { en: 'Application Language', es: 'Idioma de Solicitud' },
    '투입 장비 / 공구': { en: 'Assigned Equipment / Tools', es: 'Equipo / Herramientas Asignados' },
    'Supervisor 전화': { en: 'Supervisor Phone', es: 'Teléfono del Supervisor' },
    '현장 Supervisor': { en: 'Site Supervisor', es: 'Supervisor de Obra' },
    '현장 코드': { en: 'Site Code', es: 'Código de Obra' },
    '현장명': { en: 'Site Name', es: 'Nombre de Obra' },
    '현장명 선택': { en: 'Select Site', es: 'Seleccionar Obra' },
    '희망 현장': { en: 'Preferred Site', es: 'Obra Preferida' },

    // ── 모달 헤딩 / 버튼 / 액션 (추가) ──
    'Badge/NFC 등록': { en: 'Badge/NFC Registration', es: 'Registro Badge/NFC' },
    'Hoffman Badge/NFC 등록': { en: 'Hoffman Badge/NFC Registration', es: 'Registro Badge/NFC Hoffman' },
    'Hoffman 안전교육 완료': { en: 'Hoffman Safety Training Done', es: 'Capacitación de Seguridad Hoffman' },
    '안전교육 완료': { en: 'Safety Training Done', es: 'Capacitación Completada' },
    'Employees 열기': { en: 'Open Employees', es: 'Abrir Empleados' },
    'QR 코드로 열기': { en: 'Open by QR Code', es: 'Abrir con Código QR' },
    'QR 입사지원서 만들기': { en: 'Create QR Application', es: 'Crear Solicitud QR' },
    '현장 공용 QR': { en: 'Site Shared QR', es: 'QR Compartido de Obra' },
    '현장에서 바로 쓰는 공용 QR': { en: 'Shared QR for on-site use', es: 'QR compartido para uso en obra' },
    '로그인 계정 부여 / 권한 설정': { en: 'Grant Login / Set Permissions', es: 'Otorgar Acceso / Permisos' },
    '인터뷰 합격 처리': { en: 'Mark Interview Passed', es: 'Marcar Entrevista Aprobada' },
    '지원서 검토': { en: 'Review Application', es: 'Revisar Solicitud' },
    '지원서 링크 만들기': { en: 'Create Application Link', es: 'Crear Enlace de Solicitud' },
    '지원서 링크 보내기': { en: 'Send Application Link', es: 'Enviar Enlace de Solicitud' },
    '지원서 링크로 보내기': { en: 'Send via Application Link', es: 'Enviar por Enlace' },
    '지원서 링크 이메일 발송': { en: 'Email Application Link', es: 'Enviar Enlace por Correo' },
    '지원서 작성 링크 열기': { en: 'Open Application Link', es: 'Abrir Enlace de Solicitud' },
    '지원자가 작성할 링크 생성': { en: 'Create Applicant Link', es: 'Crear Enlace para Solicitante' },
    '직원만 등록 (계정 없음)': { en: 'Register Employee Only (No Account)', es: 'Solo Registrar Empleado (Sin Cuenta)' },
    '직접 인사등록 (+로그인)': { en: 'Direct HR Registration (+Login)', es: 'Registro Directo (+Acceso)' },
    '직접 인사등록 — 직원 + 로그인 계정 한 번에': { en: 'Direct HR Registration — Employee + Login at once', es: 'Registro Directo — Empleado + Acceso' },

    // ── 안내문 / 플레이스홀더 (추가) ──
    '비우면 자동 추론': { en: 'Auto-inferred if blank', es: 'Inferido automáticamente si vacío' },
    '자동 생성': { en: 'Auto-generate', es: 'Generar Automáticamente' },
    '예: A Company': { en: 'e.g. A Company', es: 'ej. A Company' },
    '이 장치에서 기억하기': { en: 'Remember on this device', es: 'Recordar en este dispositivo' },
    '임시 비밀번호를 입력하세요.': { en: 'Enter a temporary password.', es: 'Ingrese una contraseña temporal.' },
    '비워두면 이메일 또는 QR Applicant로 표시됩니다.': { en: 'If blank, shown as email or QR Applicant.', es: 'Si vacío, se muestra como correo o QR Applicant.' },
    '현장 관리에 등록된 현장을 선택하세요': { en: 'Select a site registered in Site Management', es: 'Seleccione una obra registrada en Gestión de Obras' }
  }, window.__ADMIN_I18N_DICT || {});

  function translate(str) {
    var key = str.trim();
    if (!key) return null;
    var entry = DICT[key];
    if (!entry) return null;
    var loc = window.__adminLocale;
    var val = entry[loc];
    if (!val) return null;
    // 앞뒤 공백 보존
    return str.replace(key, val);
  }

  var SKIP = { SCRIPT: 1, STYLE: 1, TEXTAREA: 1, INPUT: 1, SELECT: 1, OPTION: 1, CODE: 1, PRE: 1 };

  function translateRoot(root) {
    if (window.__adminLocale === 'ko' || !root) return;
    try {
      var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
        acceptNode: function (node) {
          if (!node.nodeValue || !node.nodeValue.trim()) return NodeFilter.FILTER_REJECT;
          var p = node.parentNode;
          if (p && SKIP[p.nodeName]) return NodeFilter.FILTER_REJECT;
          return NodeFilter.FILTER_ACCEPT;
        }
      });
      var batch = [];
      var n;
      while ((n = walker.nextNode())) batch.push(n);
      batch.forEach(function (node) {
        var t = translate(node.nodeValue);
        if (t !== null && t !== node.nodeValue) node.nodeValue = t;
      });
      // placeholder 속성도 번역
      var ph = root.querySelectorAll ? root.querySelectorAll('[placeholder]') : [];
      ph.forEach && ph.forEach(function (el) {
        var t = translate(el.getAttribute('placeholder') || '');
        if (t !== null) el.setAttribute('placeholder', t);
      });
    } catch (e) { /* noop */ }
  }

  function injectSwitcher() {
    if (document.getElementById('admin-lang-switcher')) return;
    var box = document.createElement('div');
    box.id = 'admin-lang-switcher';
    box.style.cssText = 'position:fixed;bottom:16px;right:16px;z-index:99999;background:rgba(17,24,39,.92);border:1px solid rgba(255,255,255,.15);border-radius:10px;padding:6px 8px;box-shadow:0 6px 20px rgba(0,0,0,.35)';
    var sel = document.createElement('select');
    sel.style.cssText = 'background:transparent;color:#e5e7eb;border:none;font-size:13px;font-weight:700;cursor:pointer;outline:none';
    [['ko', '🇰🇷 한국어'], ['en', '🇺🇸 English'], ['es', '🇪🇸 Español']].forEach(function (o) {
      var opt = document.createElement('option');
      opt.value = o[0]; opt.textContent = o[1];
      opt.style.color = '#111';
      if (o[0] === window.__adminLocale) opt.selected = true;
      sel.appendChild(opt);
    });
    sel.addEventListener('change', function () {
      setCookie(COOKIE, sel.value);
      try { localStorage.setItem('smartCompanyLanguage', sel.value); } catch (e) {}
      window.location.reload(); // 서버 로케일(Filament UI) + DOM 번역 동시 갱신
    });
    box.appendChild(sel);
    document.body.appendChild(box);
  }

  function boot() {
    window.__adminLocale = currentLocale();
    injectSwitcher();
    translateRoot(document.body);

    if (window.__adminLocale === 'ko') return;
    var pending = false;
    var obs = new MutationObserver(function (muts) {
      if (pending) return;
      pending = true;
      requestAnimationFrame(function () {
        pending = false;
        muts.forEach(function (m) {
          m.addedNodes && m.addedNodes.forEach(function (node) {
            if (node.nodeType === 1) translateRoot(node);
            else if (node.nodeType === 3) {
              var t = translate(node.nodeValue || '');
              if (t !== null && t !== node.nodeValue) node.nodeValue = t;
            }
          });
        });
      });
    });
    obs.observe(document.body, { childList: true, subtree: true });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
