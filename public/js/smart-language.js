(function () {
  'use strict';

  const STORAGE_KEY = 'smartCompanyLanguage';
  const CP1252 = {
    '€': 0x80, '‚': 0x82, 'ƒ': 0x83, '„': 0x84, '…': 0x85, '†': 0x86, '‡': 0x87,
    'ˆ': 0x88, '‰': 0x89, 'Š': 0x8A, '‹': 0x8B, 'Œ': 0x8C, 'Ž': 0x8E,
    '‘': 0x91, '’': 0x92, '“': 0x93, '”': 0x94, '•': 0x95, '–': 0x96, '—': 0x97,
    '˜': 0x98, '™': 0x99, 'š': 0x9A, '›': 0x9B, 'œ': 0x9C, 'ž': 0x9E, 'Ÿ': 0x9F,
  };

  // 고객사 이름은 배포마다 다르다. 사전에 이름을 박아 두면 다른 고객의 화면에서는
  // 키가 안 맞아 그 줄만 번역되지 않는다 — 한글이 그대로 남는다.
  const ORG = (typeof window !== 'undefined' && window.ORG_NAME) ? window.ORG_NAME : 'ERP';
  function b(v) { return typeof v === 'string' ? v.split('{ORG}').join(ORG) : v; }
  function bMap(o) { const r = {}; Object.keys(o).forEach(function (k) { r[b(k)] = b(o[k]); }); return r; }
  function bPairs(a) { return a.map(function (p) { return [b(p[0]), b(p[1])]; }); }

  const exactEn = new Map(Object.entries(bMap({
    // ── 메뉴 이름 ────────────────────────────────────────────────────
    // 사이드바와 휴대폰 '더보기' 타일. 여기가 한글로 남으면 영어를 골라도
    // 첫 화면부터 한국어라, 언어를 바꾼 보람이 없다.
    // ── 새 메뉴 구조 (2026-08-13 재편) ──────────────────────────────
    // 갈래 제목. 메뉴를 "하는 일" 순서로 묶으면서 생겼다.
    '오늘': 'Today',
    '현장': 'Site',
    '사람': 'People',
    '물자 · 돈': 'Materials & Money',
    '문서': 'Documents',
    '설정': 'Settings',
    // 최상위 메뉴
    '알림': 'Alerts',
    '공정 관리': 'Schedule (WBS)',
    '작업안전': 'Safety',
    '인원': 'People',
    '급여 / 정산': 'Payroll',
    '자재 · 장비': 'Materials & Equipment',
    '재무': 'Finance',
    '문서함': 'Documents',
    // 하위 메뉴
    '구매 / 렌트': 'Purchase / Rental',
    '차량 관리': 'Vehicles',
    '숙소 관리': 'Housing',
    '내 출퇴근 기록': 'My Attendance',
    '내 출퇴근': 'My Attendance',
    '출퇴근': 'Attendance',
    '출퇴근 기록': 'Attendance Records',
    'AI 통합 문서함': 'AI Document Hub',
    '현장 상황실': 'Field Operations Room',
    '문서통합관리': 'Document Management',
    '계정 · 권한 관리': 'Accounts & Permissions',
    '입사지원 · 온보딩': 'Applications & Onboarding',
    '직원 등록 · 관리': 'Employee Registration',
    '품목 · 분류': 'Items & Categories',
    '원청 계약 · 서류': 'Client Contracts & Documents',
    '현장 · 프로젝트': 'Sites & Projects',
    '조직 설정': 'Organization Settings',
    '임금 프로필': 'Pay Profiles',
    '메신저 관리': 'Messenger Admin',
    '대시보드': 'Dashboard',
    'AI 지휘실': 'AI Command Center',
    'AI 작업안전': 'AI Safety',
    '차량관리': 'Vehicles',
    '급여정산': 'Payroll',
    '자재장비': 'Materials & Equipment',
    '장비스캔(AI)': 'Equipment Scan (AI)',
    '숙소관리': 'Housing',
    '구매/렌트': 'Purchase / Rental',
    '항공권': 'Flights',
    '사무실비품': 'Office Supplies',
    '계정·권한': 'Accounts',
    '입사지원': 'Applications',
    '직원 등록': 'Employees',
    '품목·분류': 'Items',
    '원청 계약': 'Contracts',
    '현장·프로젝트': 'Sites',
    'AI 스캔등록': 'AI Scan',
    'í˜„ìž¥': 'Site',
    'í†µí•© ë·° (Global)': 'Global View',
    'ë°ì´í„° ë¡œë“œ ì¤‘...': 'Loading data...',
    'ëŒ€ì‹œë³´ë“œ (Overview)': 'Dashboard (Overview)',
    'AI í˜„ìž¥ ì§€íœ˜ì‹¤': 'AI Command Center',
    'í†µí•© ë¶„ì„ (Analytics)': 'Analytics',
    'ðŸ”” í†µí•© ì•Œë¦¼ ì„¼í„°': 'Alert Center',
    'AI ìž‘ì—…ì•ˆì „ê´€ë¦¬': 'AI Safety Management',
    'ì¸ì›ê´€ë¦¬': 'HR Management',
    'ê¸‰ì—¬/ì •ì‚° (Payroll)': 'Payroll / Settlement',
    'ê³µì • ê´€ë¦¬ (WBS)': 'WBS Management',
    'ìž¬ë¬´ (Finance)': 'Finance',
    'ìžìž¬/ìž¥ë¹„ (Inventory)': 'Inventory',
    '{ORG} 통합관리': '{ORG} Operations',
    'ì°¨ëŸ‰ ê´€ë¦¬': 'Vehicle Management',
    'ìž¥ë¹„ ë Œíƒˆ ê´€ë¦¬': 'Equipment Rental',
    'ìˆ™ì†Œ ê´€ë¦¬': 'Housing Management',
    'êµ¬ë§¤/ë ŒíŠ¸ ê´€ë¦¬': 'Vendor / Rental Management',
    'í•­ê³µê¶Œ ê´€ë¦¬': 'Flight Management',
    'í˜„ìž¥ì‚¬ë¬´ì‹¤ ë¹„í’ˆ': 'Office Supplies',
    'AI ìŠ¤ìº” í†µí•© ë“±ë¡': 'AI Scan Registration',
    'ì¸ì›, ìž¥ë¹„, ê±°ëž˜ID ê²€ìƒ‰...': 'Search people, equipment, transaction ID...',
    'ì „ì²´': 'All',
    'ê¸´ê¸‰': 'Critical',
    'ì£¼ì˜': 'Warning',
    'ì¼ë°˜': 'Normal',
    'ë¯¸ì²˜ë¦¬': 'Open',
    'ì²˜ë¦¬ì¤‘': 'In Progress',
    'ì²˜ë¦¬ì™„ë£Œ': 'Resolved',
    'ì™„ë£Œ': 'Done',
    'ìŠ¹ì¸ëŒ€ê¸°': 'Pending Approval',
    'ì •ìƒ': 'Normal',
    'ìš´í–‰ê°€ëŠ¥': 'Operable',
    'ìš´í–‰ë¶ˆê°€': 'Inoperable',
    'ë³´ê´€ì¤‘': 'Stored',
    'ë¶ˆì¶œì¤‘': 'Checked Out',
    'ìˆ˜ë¦¬í•„ìš”': 'Repair Needed',
    'ì†ìƒ': 'Damaged',
    'ë§Œë£Œìž„ë°•': 'Expiring Soon',
    'ê·¼ë¬´ì¤‘': 'Working',
    'ì „ì› ì¶œê·¼ ì™„ë£Œ': 'All workers checked in',
    'ì¶œê·¼ ì™„ë£Œ': 'Checked In',
    'ë¯¸ì¶œê·¼': 'Not Checked In',
    'ë§ˆìŠ¤í„° ì‹œíŠ¸': 'Master Sheet',
    'ì‹ ê·œ ë“±ë¡': 'New Record',
    'ì•Œë¦¼': 'Notifications',
    'ì„¤ì •': 'Settings',
    'ë‹«ê¸°': 'Close',
    'ì €ìž¥ì¤‘...': 'Saving...',
    'ë¡œë”©ì¤‘...': 'Loading...',
    'ìƒˆë¡œê³ ì¹¨': 'Refresh',
    '엑셀 다운로드': 'Excel Download',
    'AI 사진 등록': 'AI Photo Registration'
  })));

  const replacementsEn = bPairs([
    ['{ORG} Â· ì‹¤ì‹œê°„ í˜„ìž¥ ìš´ì˜ í˜„í™©', '{ORG} Â· Live field operations'],
    ['ê¸´ê¸‰ ì²˜ë¦¬ í•„ìš”', 'Urgent Action Items'],
    ['í”„ë¡œì íŠ¸ í˜„í™©', 'Project Status'],
    ['ì˜¤ëŠ˜ì˜ ê²°ì • í', "Today's Decision Queue"],
    ['ë¯¸ì²­êµ¬ ë°©ì§€ ì²´í¬', 'Unbilled Work Check'],
    ['AI ìš´ì˜ ë¸Œë¦¬í•‘', 'AI Operations Brief'],
    ['ë¬¸ì„œ ìŠ¤ìº”', 'Document Scan'],
    ['ì´ˆì•ˆ ë§Œë“¤ê¸°', 'Create Draft'],
    ['ì§€ìš°ê¸°', 'Clear'],
    ['ëŒ€ìƒ', 'Target'],
    ['ë‚´ìš©', 'Summary'],
    ['ë‹´ë‹¹', 'Owner'],
    ['ìƒíƒœ', 'Status'],
    ['ë‚ ì§œ', 'Date'],
    ['êµ¬ë¶„', 'Type'],
    ['í˜„ìž¥ë³„ ì¶œê·¼ í˜„í™©', 'Attendance by Site'],
    ['ì „ì²´ ì¶œê·¼ ì¸ì›', 'Total Present'],
    ['ì „ì²´ ì¸ì›', 'Total Workers'],
    ['ì—°ë™ í˜„ìž¥ ìˆ˜', 'Connected Sites'],
    ['í†µí•© ì¶œí‡´ê·¼ ëª©ë¡', 'Integrated Attendance List'],
    ['ì¸ì› ë§ˆìŠ¤í„°', 'Personnel Master'],
    ['ì„±ëª…', 'Name'],
    ['ì†Œì†', 'Company'],
    ['íšŒì‚¬', 'Company'],
    ['íŒ€', 'Team'],
    ['ì²´í¬ì¸', 'Check In'],
    ['í‡´ê·¼', 'Check Out'],
    ['ì§ì¢…', 'Role'],
    ['ë¹„ìžë§Œë£Œ', 'Visa Expiry'],
    ['ì•ˆì „êµìœ¡', 'Safety Training'],
    ['ì¸ì›ID', 'Personnel ID'],
    ['ì „ì²´ í˜„ìž¥', 'All Sites'],
    ['í†µí•© í˜„í™©', 'Global Overview'],
    ['ëª¨ë“  ì—°ë™ í˜„ìž¥ì˜ ì¶œí‡´ê·¼ ë°ì´í„°ë¥¼ í†µí•© ì§‘ê³„í•©ë‹ˆë‹¤', 'Aggregated attendance across all connected sites'],
    ['í˜„ìž¥ ì¸ì›', 'Field Staff'],
    ['ì¤‘ìž¥ë¹„ ê°€ë™', 'Equipment Operable'],
    ['MTD ì§€ì¶œ', 'MTD Spend'],
    ['ë¯¸ì²˜ë¦¬ ì•ˆì „ì´ìŠˆ', 'Open Safety Issues'],
    ['ìˆ™ì†Œ ê°€ë™', 'Housing Occupancy'],
    ['íšŒìˆ˜ í•„ìš” ê¸ˆì•¡', 'Revenue at Risk'],
    ['ì•ˆì „ ë¸”ë¡œì»¤', 'Safety Blockers'],
    ['ì¼ì • ìœ„í—˜ Job', 'Schedule Risk Jobs'],
    ['ê²°ì • ëŒ€ê¸°', 'Decision Queue'],
    ['ìž¬ë¬´ / ë¹„ìš©', 'Finance / Costs'],
    ['ìžìž¬ / ìž¥ë¹„ ê´€ë¦¬', 'Inventory / Equipment'],
    ['ì¤‘ìž¥ë¹„ ì¼ì¼ì ê²€ í˜„í™©', 'Daily equipment inspection status'],
    ['ê³µêµ¬ ë¶ˆì¶œ/ë°˜ë‚© ì¶”ì ', 'Tool checkout / return tracking'],
    ['ì „ì²´ ìž¥ë¹„', 'Total Equipment'],
    ['ê³µêµ¬ ë¶ˆì¶œì¤‘', 'Tools Checked Out'],
    ['ê³µêµ¬ ì´ìƒ', 'Tool Issues'],
    ['ìž¥ë¹„ID', 'Equipment ID'],
    ['ìž¥ë¹„', 'Equipment'],
    ['ì ê²€ìž', 'Inspector'],
    ['ìµœì¢…ì ê²€', 'Last Check'],
    ['í˜„ìž¬ìƒíƒœ', 'Current Status'],
    ['ê±°ëž˜ì²˜ ì´ë©”ì¼ í†µì‹ ', 'Vendor Email Communication'],
    ['AI íŽ¸ì§€ ë¹„ì„œ', 'AI Writing Assistant'],
    ['ì˜ì–´ë¡œ ë²ˆì—­', 'Translate to English'],
    ['AI ì´ˆì•ˆ', 'AI Draft'],
    ['ë°œì†¡', 'Send']
  ]);

  function cp1252Encode(text) {
    const bytes = [];
    for (const ch of text) {
      const code = ch.codePointAt(0);
      if (CP1252[ch] !== undefined) bytes.push(CP1252[ch]);
      else if (code <= 255) bytes.push(code);
      else return null;
    }
    return new Uint8Array(bytes);
  }

  function looksMojibake(text) {
    return /[ÃÂâ€ðŸìíëêÌÍÎÏÐÑÒÓÔÕÖØÙÚÛÜÝÞß]/.test(text);
  }

  function scoreRepair(text) {
    const hangul = (text.match(/[\uac00-\ud7a3]/g) || []).length;
    const emoji = (text.match(/[\u{1f300}-\u{1faff}]/gu) || []).length;
    const mojibake = (text.match(/[ÃÂâ€ðŸìíëêÌÍÎÏÐÑÒÓÔÕÖØÙÚÛÜÝÞß]/g) || []).length;
    const replacement = (text.match(/\uFFFD/g) || []).length;
    return hangul * 5 + emoji * 3 - mojibake * 2 - replacement * 10;
  }

  function repairOnce(text) {
    if (!looksMojibake(text)) return text;
    const bytes = cp1252Encode(text);
    if (!bytes) return text;
    const decoded = new TextDecoder('utf-8', { fatal: false }).decode(bytes);
    if (!decoded || decoded.includes('\uFFFD')) return text;
    return scoreRepair(decoded) > scoreRepair(text) ? decoded : text;
  }

  function repairText(text) {
    let current = text;
    for (let i = 0; i < 3; i += 1) {
      const next = repairOnce(current);
      if (next === current) break;
      current = next;
    }
    return current;
  }

  const extraReplacementsEn = [
    ['📍 현장', '📍 Site'],
    ['🌐 통합 뷰 (Global)', '🌐 Global View'],
    ['퀵 액션 센터', 'Quick Action Center'],
    ['현장 인원', 'Field Staff'],
    ['총원 5명', 'Total 5 people'],
    ['5 명', '5 people'],
    ['5명', '5 people'],
    ['총원', 'Total'],
    ['중장비 가동', 'Equipment Operable'],
    ['4/5 대', '4/5 units'],
    ['수리대기 1대', 'Repair waiting 1 unit'],
    ['수리대기', 'Repair waiting'],
    ['승인대기', 'Pending approval'],
    ['3 건', '3 issues'],
    ['무사고 47일', 'Accident-free 47 days'],
    ['무사고', 'Accident-free'],
    ['잔여 3개', 'Available 3 rooms'],
    ['잔여', 'Available'],
    ['긴급 처리 필요', 'Urgent Action Items'],
    ['프로젝트 현황', 'Project Status'],
    ['완료 예정', 'Due'],
    ['오늘의 결정 큐', "Today's Decision Queue"],
    ['미청구 방지 체크', 'Unbilled Work Check'],
    ['AI 운영 브리핑', 'AI Operations Brief'],
    ['AI 현장 지휘실', 'AI Field Command Center'],
    ['샘플 데이터 없음.', 'No sample data.'],
    ['경영 브리핑', 'Executive Brief'],
    ['현장 운영판', 'Field Operations Board'],
    ['리스크 관제', 'Risk Control'],
    ['결정 대기', 'Decisions Pending'],
    ['승인 대기 비용', 'Cost Pending Approval'],
    ['안전 블로커', 'Safety Blockers'],
    ['일정 위험 PROJECT', 'Schedule-risk Projects'],
    ['오늘의 의사결정 큐', "Today's Decision Queue"],
    ['오늘 인원', "Today's Workforce"],
    ['비용 승인·정산', 'Cost Approval & Settlement'],
    ['실데이터 운영 브리핑', 'Live-data Operations Brief'],
    ['팀별 오늘 운영판', 'Team Operations Board'],
    ['오늘 WBS 작업 · TBM Gate', "Today's WBS Work · TBM Gate"],
    ['장비 반납·검사', 'Equipment Returns & Inspections'],
    ['문서 기한·후속조치', 'Document Deadlines & Actions'],
    ['통합 알림 피드', 'Unified Alert Feed'],
    ['데이터 출처·최신성', 'Data Sources & Freshness'],
    ['통합 출퇴근 목록', 'Integrated Attendance List'],
    ['인원 마스터', 'Personnel Master'],
    ['중장비', 'Heavy Equipment'],
    ['장비', 'Equipment'],
    ['대상', 'Target'],
    ['구분', 'Type'],
    ['내용', 'Summary'],
    ['담당', 'Owner'],
    ['상태', 'Status'],
    ['날짜', 'Date'],
    ['긴급', 'Critical'],
    ['주의', 'Warning'],
    ['미처리', 'Open'],
    ['완료', 'Done']
  ];

  const exactEs = new Map(Object.entries(bMap({
    // ── 메뉴 이름 (영어를 거쳐 온다) ─────────────────────────────────
    // ── 새 메뉴 구조 (2026-08-13 재편) ──────────────────────────────
    'Today': 'Hoy',
    'Site': 'Obra',
    'People': 'Personal',
    'Materials & Money': 'Materiales y dinero',
    'Documents': 'Documentos',
    'Settings': 'Ajustes',
    'Alerts': 'Alertas',
    'Schedule (WBS)': 'Programación (WBS)',
    'Safety': 'Seguridad',
    'Payroll': 'Nómina',
    'Materials & Equipment': 'Materiales y equipos',
    'Finance': 'Finanzas',
    'Purchase / Rental': 'Compra / Alquiler',
    'Vehicles': 'Vehículos',
    'Housing': 'Alojamiento',
    'My Attendance': 'Mi asistencia',
    'Attendance Records': 'Registros de asistencia',
    'AI Document Hub': 'Centro de documentos IA',
    'Field Operations Room': 'Sala de operaciones',
    'Document Management': 'Gestión documental',
    'Accounts & Permissions': 'Cuentas y permisos',
    'Applications & Onboarding': 'Solicitudes e incorporación',
    'Employee Registration': 'Registro de empleados',
    'Items & Categories': 'Artículos y categorías',
    'Client Contracts & Documents': 'Contratos y documentos del cliente',
    'Sites & Projects': 'Obras y proyectos',
    'Organization Settings': 'Configuración de la organización',
    'Pay Profiles': 'Perfiles salariales',
    'Messenger Admin': 'Gestión de mensajería',
    'Dashboard': 'Panel',
    'AI Safety': 'Seguridad IA',
    'Vehicles': 'Vehículos',
    'Payroll': 'Nómina',
    'Materials & Equipment': 'Materiales y equipos',
    'Equipment Scan (AI)': 'Escaneo de equipos (IA)',
    'Housing': 'Alojamiento',
    'Purchase / Rental': 'Compra / Renta',
    'Flights': 'Vuelos',
    'Accounts': 'Cuentas',
    'Applications': 'Solicitudes',
    'Employees': 'Empleados',
    'Items': 'Artículos',
    'Contracts': 'Contratos',
    'Sites': 'Obras',
    'AI Scan': 'Escaneo IA',
    'Site': 'Obra',
    'Global View': 'Vista global',
    'Loading data...': 'Cargando datos...',
    'Dashboard (Overview)': 'Panel (Resumen)',
    'Dashboard': 'Panel',
    'Overview': 'Resumen',
    'Executive Panel': 'Panel ejecutivo',
    'CORE': 'NÚCLEO',
    'MODULES': 'MÓDULOS',
    'NEW': 'NUEVO',
    'AI Command Center': 'Centro de comando IA',
    'Analytics': 'Analítica',
    'Alert Center': 'Centro de alertas',
    'AI Safety Management': 'Gestión de seguridad IA',
    'HR Management': 'Gestión de personal',
    'Payroll / Settlement': 'Nómina / Liquidación',
    'WBS Management': 'Gestión WBS',
    'Finance': 'Finanzas',
    'Inventory': 'Inventario',
    '{ORG} Operations': 'Operaciones {ORG}',
    'Vehicle Management': 'Gestión de vehículos',
    'Equipment Rental': 'Renta de equipos',
    'Housing Management': 'Gestión de alojamiento',
    'Vendor / Rental Management': 'Proveedores / Rentas',
    'Flight Management': 'Gestión de vuelos',
    'Office Supplies': 'Suministros de oficina',
    'AI Scan Registration': 'Registro con escaneo IA',
    'All': 'Todo',
    'people': 'personas',
    'unit': 'unidad',
    'units': 'unidades',
    'issues': 'incidencias',
    'days': 'días',
    'rooms': 'habitaciones',
    'Critical': 'Crítico',
    'Warning': 'Advertencia',
    'Normal': 'Normal',
    'Open': 'Abierto',
    'In Progress': 'En progreso',
    'Resolved': 'Resuelto',
    'Done': 'Completado',
    'Pending Approval': 'Pendiente de aprobación',
    'Operable': 'Operable',
    'Inoperable': 'No operable',
    'Stored': 'En almacén',
    'Checked Out': 'Prestado',
    'Repair Needed': 'Requiere reparación',
    'Damaged': 'Dañado',
    'Expiring Soon': 'Por vencer',
    'Working': 'Trabajando',
    'All workers checked in': 'Todo el personal registró entrada',
    'Checked In': 'Entrada registrada',
    'Not Checked In': 'Sin entrada',
    'Master Sheet': 'Hoja maestra',
    'New Record': 'Nuevo registro',
    'Notifications': 'Notificaciones',
    'Settings': 'Configuración',
    'Close': 'Cerrar',
    'Saving...': 'Guardando...',
    'Loading...': 'Cargando...',
    'Refresh': 'Actualizar',
    'Excel Download': 'Descargar Excel',
    'AI Photo Registration': 'Registro de fotos IA',
    'Language': 'Idioma',
    'Search...': 'Buscar...',
    'Search people, equipment, transaction ID...': 'Buscar personal, equipo, ID de transacción...',
    'Search everything...': 'Buscar todo...',
    'My Account': 'Mi cuenta',
    'Current Company': 'Empresa actual',
    'Your Company': 'Su empresa',
    'My Profile': 'Mi perfil',
    'View Profile': 'Ver perfil',
    'Update Profile': 'Actualizar perfil',
    'UI Settings': 'Configuración de interfaz',
    'Change Password': 'Cambiar contraseña',
    'Logout': 'Cerrar sesión',
    'Admin User': 'Usuario administrador',
    'System Administrator': 'Administrador del sistema',
    'Operations': 'Operaciones',
    'Personal Information': 'Información personal',
    'Employment Information': 'Información laboral',
    'Contact Information': 'Información de contacto',
    'Password Information': 'Información de contraseña',
    'Employee Code': 'Código de empleado',
    'First Name': 'Nombre',
    'Last Name': 'Apellido',
    'Preferred Name': 'Nombre preferido',
    'Job Title': 'Puesto',
    'Department': 'Departamento',
    'Location': 'Ubicación',
    'Company': 'Empresa',
    'Manager': 'Supervisor',
    'Login Email': 'Correo de acceso',
    'Personal Email': 'Correo personal',
    'Mobile Number': 'Teléfono móvil',
    'Direct Number': 'Teléfono directo',
    'Read-only': 'Solo lectura',
    'Not set': 'Sin definir',
    'Back to Profile': 'Volver al perfil',
    'Home': 'Inicio',
    'Save Changes': 'Guardar cambios',
    'Cancel': 'Cancelar',
    'Display Settings': 'Configuración de pantalla',
    'Interface Style': 'Estilo de interfaz',
    'New Interface (Left Sidebar)': 'Nueva interfaz (barra lateral izquierda)',
    'Classic (Top Navbar)': 'Clásica (barra superior)',
    'Theme': 'Tema',
    'Auto (System)': 'Automático (sistema)',
    'Dark': 'Oscuro',
    'Light': 'Claro',
    'Timezone': 'Zona horaria',
    'Document/Folder Settings': 'Configuración de documentos/carpetas',
    'Default View Mode': 'Vista predeterminada',
    'Grid': 'Cuadrícula',
    'List': 'Lista',
    'Default Sort By': 'Ordenar por defecto',
    'Upload Date': 'Fecha de carga',
    'Project': 'Proyecto',
    'Default Sort Order': 'Orden predeterminado',
    'Descending': 'Descendente',
    'Ascending': 'Ascendente',
    'Security Notice': 'Aviso de seguridad',
    'Password Requirements': 'Requisitos de contraseña',
    'Current Password': 'Contraseña actual',
    'New Password': 'Nueva contraseña',
    'Confirm New Password': 'Confirmar nueva contraseña',
    'At least 8 characters': 'Al menos 8 caracteres',
    'At least one uppercase letter': 'Al menos una letra mayúscula',
    'At least one lowercase letter': 'Al menos una letra minúscula',
    'At least one number': 'Al menos un número',
    'Attendance Record': 'Registro de asistencia',
    'Work Log': 'Registro de trabajo',
    'To Do': 'Pendientes',
    'Calendar': 'Calendario',
    'Meetings': 'Reuniones',
    'Notes': 'Notas',
    'Files': 'Archivos',
    'Chats': 'Chats',
    'Direct Messages': 'Mensajes directos',
    'Directory': 'Directorio',
    'Work Project': 'Proyecto de trabajo',
    'Quick Action Center': 'Centro de acciones rápidas',
    'Field Staff': 'Personal de campo',
    'Total Present': 'Presentes',
    'Total Workers': 'Personal total',
    'Connected Sites': 'Obras conectadas',
    'Integrated Attendance List': 'Lista integrada de asistencia',
    'Personnel Master': 'Maestro de personal',
    'Name': 'Nombre',
    'Team': 'Equipo',
    'Check In': 'Entrada',
    'Check Out': 'Salida',
    'Role': 'Rol',
    'Visa Expiry': 'Vencimiento de visa',
    'Safety Training': 'Capacitación de seguridad',
    'Personnel ID': 'ID de personal',
    'All Sites': 'Todas las obras',
    'Global Overview': 'Resumen global',
    'Equipment Operable': 'Equipo operable',
    'MTD Spend': 'Gasto MTD',
    'Open Safety Issues': 'Incidencias de seguridad abiertas',
    'Housing Occupancy': 'Ocupación de alojamiento',
    'Revenue at Risk': 'Ingresos en riesgo',
    'Safety Blockers': 'Bloqueos de seguridad',
    'Schedule Risk Jobs': 'Trabajos con riesgo de agenda',
    'Decision Queue': 'Cola de decisiones',
    'Finance / Costs': 'Finanzas / Costos',
    'Inventory / Equipment': 'Inventario / Equipo',
    'Daily equipment inspection status': 'Estado diario de inspección de equipos',
    'Tool checkout / return tracking': 'Control de salida/devolución de herramientas',
    'Total Equipment': 'Equipo total',
    'Tools Checked Out': 'Herramientas prestadas',
    'Tool Issues': 'Incidencias de herramientas',
    'Equipment ID': 'ID de equipo',
    'Equipment': 'Equipo',
    'Inspector': 'Inspector',
    'Last Check': 'Última revisión',
    'Current Status': 'Estado actual',
    'Vendor Email Communication': 'Comunicación por correo con proveedores',
    'AI Writing Assistant': 'Asistente de redacción IA',
    'Translate to English': 'Traducir al inglés',
    'AI Draft': 'Borrador IA',
    'Send': 'Enviar'
  })));

  const replacementsEs = bPairs([
    ['{ORG} Â· Live field operations', '{ORG} Â· Operaciones de campo en vivo'],
    ['{ORG} · Live field operations', '{ORG} · Operaciones de campo en vivo'],
    ['Urgent Action Items', 'Acciones urgentes'],
    ['Project Status', 'Estado del proyecto'],
    ["Today's Decision Queue", 'Cola de decisiones de hoy'],
    ['Unbilled Work Check', 'Revisión de trabajo no facturado'],
    ['AI Operations Brief', 'Resumen operativo IA'],
    ['AI Field Command Center', 'Centro de comando de campo IA'],
    ['No sample data.', 'Sin datos de muestra.'],
    ['Executive Brief', 'Resumen ejecutivo'],
    ['Field Operations Board', 'Panel de operaciones de campo'],
    ['Risk Control', 'Control de riesgos'],
    ['Decisions Pending', 'Decisiones pendientes'],
    ['Cost Pending Approval', 'Costo pendiente de aprobación'],
    ['Safety Blockers', 'Bloqueos de seguridad'],
    ['Schedule-risk Projects', 'Proyectos con riesgo de plazo'],
    ["Today's Workforce", 'Personal de hoy'],
    ['Cost Approval & Settlement', 'Aprobación y liquidación de costos'],
    ['Live-data Operations Brief', 'Resumen operativo con datos reales'],
    ['Team Operations Board', 'Panel operativo por equipo'],
    ["Today's WBS Work · TBM Gate", 'Trabajo WBS de hoy · Control TBM'],
    ['Equipment Returns & Inspections', 'Devoluciones e inspecciones de equipos'],
    ['Document Deadlines & Actions', 'Plazos y acciones documentales'],
    ['Unified Alert Feed', 'Flujo de alertas unificadas'],
    ['Data Sources & Freshness', 'Fuentes y actualidad de datos'],
    ['Document Scan', 'Escaneo de documentos'],
    ['Create Draft', 'Crear borrador'],
    ['Clear', 'Limpiar'],
    ['Repair waiting', 'En reparación'],
    ['Pending approval', 'Pendiente de aprobación'],
    ['Accident-free', 'Sin accidentes'],
    ['Available', 'Disponible'],
    ['Target', 'Objetivo'],
    ['Summary', 'Resumen'],
    ['Owner', 'Responsable'],
    ['Status', 'Estado'],
    ['Date', 'Fecha'],
    ['Type', 'Tipo'],
    ['Attendance by Site', 'Asistencia por obra'],
    ['Aggregated attendance across all connected sites', 'Asistencia agregada de todas las obras conectadas'],
    ['What you can update:', 'Lo que puede actualizar:'],
    ['You can update your preferred name, personal email, mobile number, and direct number.', 'Puede actualizar su nombre preferido, correo personal, teléfono móvil y teléfono directo.'],
    ['Update your personal contact information', 'Actualice su información personal de contacto'],
    ['Configure your user interface defaults', 'Configure sus preferencias de interfaz'],
    ['Choose between Classic and the new left sidebar interface.', 'Elija entre la interfaz clásica y la nueva barra lateral izquierda.'],
    ['Choose light, dark, or automatic based on system settings.', 'Elija claro, oscuro o automático según el sistema.'],
    ['Select the language displayed in the interface.', 'Seleccione el idioma mostrado en la interfaz.'],
    ['Select the timezone used for displaying dates and times.', 'Seleccione la zona horaria para mostrar fechas y horas.'],
    ['Select the default view for document and folder lists.', 'Seleccione la vista predeterminada para documentos y carpetas.'],
    ['Select the default sorting criteria for document lists.', 'Seleccione el criterio de orden predeterminado.'],
    ['Choose ascending or descending order.', 'Elija orden ascendente o descendente.'],
    ['Secure your account with a strong password', 'Proteja su cuenta con una contraseña segura'],
    ['Choose a strong password that you do not use elsewhere.', 'Elija una contraseña fuerte que no use en otro lugar.'],
    ['We recommend using a combination of letters, numbers, and special characters.', 'Recomendamos combinar letras, números y caracteres especiales.'],
    ['Enter your current password', 'Ingrese su contraseña actual'],
    ['Enter your new password', 'Ingrese su nueva contraseña'],
    ['Re-enter your new password', 'Vuelva a ingresar su nueva contraseña'],
    ['Profile updated.', 'Perfil actualizado.'],
    ['Please fill in all password fields.', 'Complete todos los campos de contraseña.'],
    ['New password and confirmation do not match.', 'La nueva contraseña y la confirmación no coinciden.'],
    ['Password does not meet the requirements.', 'La contraseña no cumple los requisitos.'],
    ['Password change request is ready for backend connection.', 'La solicitud de cambio de contraseña está lista para conectarse al backend.']
  ]);

  let normalizedExactEn = null;
  let normalizedReplacementsEn = null;

  function normalizeEnglishMap() {
    if (normalizedExactEn && normalizedReplacementsEn) return;
    normalizedExactEn = new Map();
    exactEn.forEach((en, ko) => {
      const cleanEn = repairText(en);
      normalizedExactEn.set(ko, cleanEn);
      normalizedExactEn.set(repairText(ko), cleanEn);
    });
    normalizedReplacementsEn = [];
    replacementsEn.forEach(([ko, en]) => {
      const cleanEn = repairText(en);
      normalizedReplacementsEn.push([ko, cleanEn]);
      const repaired = repairText(ko);
      if (repaired !== ko) normalizedReplacementsEn.push([repaired, cleanEn]);
    });
    extraReplacementsEn.forEach(([ko, en]) => normalizedReplacementsEn.push([ko, en]));

    // 긴 말부터 바꾼다. 순서를 안 정하면 '퇴근' 이 '출퇴근' 보다 먼저 걸려서
    // "출퇴근 기록" 이 "출Check Out 기록" 이 된다.
    normalizedReplacementsEn.sort((a, b) => b[0].length - a[0].length);
  }

  /**
   * 한국어에는 낱말 사이에 띄어쓰기가 없다.
   *
   * 그래서 문자열을 그냥 바꿔치기하면 낱말 가운데를 잘라 먹는다. '퇴근'을 바꾸면
   * "출퇴근"이 "출Check Out"이 되고, '장비'를 바꾸면 "중장비"가 "중Equipment"가
   * 된다. 실제로 그렇게 나오고 있었다.
   *
   * 규칙 두 가지로 막는다.
   *
   *   앞이 한글이면 바꾸지 않는다 — 더 큰 낱말의 일부다(출<b>퇴근</b>, 중<b>장비</b>).
   *   뒤가 한글이면 조사일 때만 바꾼다 — "현장에서" 의 '에서' 는 낱말이 아니라
   *   문법이라 영어에는 옮길 자리가 없다. 그래서 조사는 함께 지운다.
   *
   * 조사가 아닌 한글이 붙어 있으면(현장<b>별</b>) 그냥 둔다. 번역이 안 된 한국어는
   * 읽을 수 있지만, 반쯤 잘린 낱말은 읽을 수 없다.
   */
  const PARTICLES = [
    '으로써', '으로서', '에서는', '에게서', '이라는', '라는',
    '으로', '에서', '에게', '까지', '부터', '와의', '과의', '이나', '거나',
    '만큼', '처럼', '보다', '대로', '조차', '마저', '밖에', '이란', '란',
    '은', '는', '이', '가', '을', '를', '의', '에', '도', '만', '와', '과', '로', '나',
  ];

  function isHangul(ch) {
    return !!ch && /[가-힣ㄱ-ㅎㅏ-ㅣ]/.test(ch);
  }

  /** 이 자리에서 시작하는 조사의 길이. 조사가 아니면 -1. */
  function particleLength(text, at) {
    for (const particle of PARTICLES) {
      if (!text.startsWith(particle, at)) continue;
      // 조사 뒤에 또 한글이 오면 그건 조사가 아니라 다른 낱말의 앞부분이다.
      if (isHangul(text[at + particle.length])) continue;
      return particle.length;
    }
    return -1;
  }

  function replaceKorean(text, ko, en) {
    if (!text.includes(ko)) return text;

    let out = '';
    let cursor = 0;
    while (cursor <= text.length) {
      const at = text.indexOf(ko, cursor);
      if (at === -1) {
        out += text.slice(cursor);
        break;
      }
      const end = at + ko.length;

      if (isHangul(text[at - 1])) {          // 더 큰 낱말의 일부
        out += text.slice(cursor, end);
        cursor = end;
        continue;
      }

      let drop = 0;
      if (isHangul(text[end])) {
        const tail = particleLength(text, end);
        if (tail === -1) {                    // 조사가 아니면 손대지 않는다
          out += text.slice(cursor, end);
          cursor = end;
          continue;
        }
        drop = tail;
      }

      out += text.slice(cursor, at) + en;
      cursor = end + drop;
    }
    return out;
  }

  function translateToEnglish(text) {
    normalizeEnglishMap();
    const trimmed = text.trim();
    if (!trimmed) return text;
    if (normalizedExactEn.has(trimmed)) return text.replace(trimmed, normalizedExactEn.get(trimmed));
    const unitOnly = { '명': 'people', '대': 'units', '건': 'issues', '일': 'days', '개': 'rooms' };
    if (unitOnly[trimmed]) return text.replace(trimmed, unitOnly[trimmed]);
    let output = text;
    for (const [ko, en] of normalizedReplacementsEn) {
      output = /[가-힣]/.test(ko) ? replaceKorean(output, ko, en) : output.split(ko).join(en);
    }
    output = output
      .replace(/(\d+)\s*명/g, '$1 people')
      .replace(/(\d+\/\d+)\s*대/g, '$1 units')
      .replace(/(\d+)\s*대/g, '$1 units')
      .replace(/(\d+)\s*건/g, '$1 issues')
      .replace(/(\d+)\s*일/g, '$1 days')
      .replace(/(\d+)\s*개/g, '$1 rooms');
    return output;
  }

  function escapeRegExp(text) {
    return String(text).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  }

  function translateToSpanish(text) {
    const english = translateToEnglish(text);
    const trimmed = english.trim();
    if (!trimmed) return english;
    if (exactEs.has(trimmed)) return english.replace(trimmed, exactEs.get(trimmed));
    let output = english;
    for (const [from, to] of replacementsEs) output = output.split(from).join(to);
    Array.from(exactEs.entries())
      .sort((a, b) => b[0].length - a[0].length)
      .forEach(([en, es]) => {
        const start = /^[A-Za-z0-9]/.test(en) ? '\\b' : '';
        const end = /[A-Za-z0-9]$/.test(en) ? '\\b' : '';
        output = output.replace(new RegExp(start + escapeRegExp(en) + end, 'g'), es);
      });
    output = output
      .replace(/(\d+)\s*people/g, '$1 personas')
      .replace(/(\d+\/\d+)\s*units/g, '$1 unidades')
      .replace(/(\d+)\s*units/g, '$1 unidades')
      .replace(/(\d+)\s*issues/g, '$1 incidencias')
      .replace(/(\d+)\s*days/g, '$1 días')
      .replace(/(\d+)\s*rooms/g, '$1 habitaciones');
    return output;
  }

  function currentLanguage() {
    const lang = localStorage.getItem(STORAGE_KEY) || 'ko';
    return ['ko', 'en', 'es'].includes(lang) ? lang : 'ko';
  }

  function localizeString(value) {
    if (typeof value !== 'string' || value.length === 0) return value;
    const repaired = repairText(value);
    const lang = currentLanguage();
    if (lang === 'en') return translateToEnglish(repaired);
    if (lang === 'es') return translateToSpanish(repaired);
    return repaired;
  }

  function localizeAttributes(root) {
    const elements = root.querySelectorAll ? root.querySelectorAll('[placeholder], [title], option, input[value], button[value]') : [];
    elements.forEach((el) => {
      ['placeholder', 'title', 'value'].forEach((attr) => {
        if (!el.hasAttribute || !el.hasAttribute(attr)) return;
        if (attr === 'value' && !['button', 'submit', 'reset'].includes((el.getAttribute('type') || '').toLowerCase())) return;
        const before = el.getAttribute(attr);
        const next = localizeString(before);
        if (next !== before) el.setAttribute(attr, next);
      });
    });
  }

  function shouldSkip(node) {
    const parent = node.parentElement;
    if (!parent) return true;
    return !!parent.closest('script, style, code, pre, textarea, [data-no-i18n]');
  }

  function localizeTextNodes(root) {
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
      acceptNode(node) {
        if (shouldSkip(node)) return NodeFilter.FILTER_REJECT;
        if (!node.nodeValue || !node.nodeValue.trim()) return NodeFilter.FILTER_REJECT;
        return NodeFilter.FILTER_ACCEPT;
      }
    });
    const nodes = [];
    while (walker.nextNode()) nodes.push(walker.currentNode);
    nodes.forEach((node) => {
      const next = localizeString(node.nodeValue);
      if (next !== node.nodeValue) node.nodeValue = next;
    });
  }

  function updateLanguageSwitcher() {
    const select = document.getElementById('language-switcher');
    if (select && select.value !== currentLanguage()) select.value = currentLanguage();
  }

  function applyLanguage(root = document.body) {
    if (!root) return;
    localizeTextNodes(root);
    localizeAttributes(root);
    updateLanguageSwitcher();
  }

  let scheduled = false;
  function scheduleApply() {
    if (scheduled) return;
    scheduled = true;
    requestAnimationFrame(() => {
      scheduled = false;
      applyLanguage(document.body);
    });
  }

    window.smartCompanySetLanguage = function (lang) {
    var safe = ['ko', 'en', 'es'].includes(lang) ? lang : 'ko';
    localStorage.setItem(STORAGE_KEY, safe);
    // 서버 로케일(관리자/Filament)도 같이 따라오도록 평문 쿠키로 공유.
    document.cookie = 'app_locale=' + safe + ';path=/;max-age=31536000;samesite=lax';
    window.location.reload();
  };

  // 번역 규칙만 따로 확인할 수 있게 열어 둔다. 화면 동작에는 쓰이지 않는다.
  // 낱말 가운데를 잘라 먹는 사고를 눈으로만 잡으면, 사전에 짧은 말을 하나 더
  // 넣는 순간 조용히 되살아난다.
  if (typeof window !== 'undefined') {
    window.__i18n = { translateToEnglish, translateToSpanish, replaceKorean };
  }

  // 브라우저가 아닌 곳(테스트)에서는 화면에 손대지 않는다.
  if (typeof document === 'undefined') return;

  document.addEventListener('DOMContentLoaded', () => {
    updateLanguageSwitcher();
    applyLanguage(document.body);
    document.addEventListener('change', (event) => {
      if (event.target && event.target.id === 'language-switcher') window.smartCompanySetLanguage(event.target.value);
    });
    const observer = new MutationObserver(scheduleApply);
    observer.observe(document.body, { childList: true, subtree: true, characterData: true });
    setTimeout(() => applyLanguage(document.body), 300);
    setTimeout(() => applyLanguage(document.body), 1000);
  });
})();
