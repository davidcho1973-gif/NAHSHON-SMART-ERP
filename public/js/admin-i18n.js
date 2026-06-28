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

  // 한글 → { en, es }. 키 재사용(예 '상태')으로 전 화면 공통 적용.
  var DICT = {
    // 네비게이션 / 그룹
    'SMART COMPANY': { en: 'SMART COMPANY', es: 'SMART COMPANY' },
    '대시보드': { en: 'Dashboard', es: 'Panel' },
    '현장 / PROJECT 관리': { en: 'Sites / Projects', es: 'Obras / Proyectos' },
    '현장 관리 (Sites)': { en: 'Site Management', es: 'Gestión de Obras' },
    '현장 관리': { en: 'Site Management', es: 'Gestión de Obras' },
    'PROJECT 관리': { en: 'Project Management', es: 'Gestión de Proyectos' },
    '인원 관리': { en: 'Personnel', es: 'Personal' },
    '직원 관리 (Employees)': { en: 'Employees', es: 'Empleados' },
    '회원 등록': { en: 'Member Registration', es: 'Registro de Miembros' },
    '회원 등록 (Applicants)': { en: 'Applicants', es: 'Solicitantes' },
    '접근 제어': { en: 'Access Control', es: 'Control de Acceso' },
    '접근 제어 (Access)': { en: 'Access Control', es: 'Control de Acceso' },
    '급여 (Payroll)': { en: 'Payroll', es: 'Nómina' },
    '임금 프로필 (Pay Profiles)': { en: 'Pay Profiles', es: 'Perfiles de Pago' },
    '문서 관리': { en: 'Documents', es: 'Documentos' },
    '거래처 마스터': { en: 'Vendor Master', es: 'Maestro de Proveedores' },
    // 공통 액션
    '저장': { en: 'Save', es: 'Guardar' },
    '저장 후 계속 편집': { en: 'Save & continue editing', es: 'Guardar y seguir editando' },
    '취소': { en: 'Cancel', es: 'Cancelar' },
    '삭제': { en: 'Delete', es: 'Eliminar' },
    '수정': { en: 'Edit', es: 'Editar' },
    '편집': { en: 'Edit', es: 'Editar' },
    '생성': { en: 'Create', es: 'Crear' },
    '추가': { en: 'Add', es: 'Añadir' },
    '검색': { en: 'Search', es: 'Buscar' },
    '필터': { en: 'Filters', es: 'Filtros' },
    '내보내기': { en: 'Export', es: 'Exportar' },
    '확인': { en: 'Confirm', es: 'Confirmar' },
    '닫기': { en: 'Close', es: 'Cerrar' },
    '새 회사 추가': { en: 'Add New Company', es: 'Añadir Empresa' },
    '관리': { en: 'Manage', es: 'Gestionar' },
    '보기': { en: 'View', es: 'Ver' },
    '활성': { en: 'Active', es: 'Activo' },
    '비활성': { en: 'Inactive', es: 'Inactivo' },
    // 공통 필드
    '현장 코드': { en: 'Site Code', es: 'Código de Obra' },
    '현장명': { en: 'Site Name', es: 'Nombre de Obra' },
    '코드': { en: 'Code', es: 'Código' },
    '이름': { en: 'Name', es: 'Nombre' },
    '성명': { en: 'Full Name', es: 'Nombre Completo' },
    '상태': { en: 'Status', es: 'Estado' },
    '회사': { en: 'Company', es: 'Empresa' },
    '대표 관리 회사 (소속사)': { en: 'Managing Company (Employer)', es: 'Empresa Gestora (Empleador)' },
    '원청사 (발주처/Client)': { en: 'Client (General Contractor)', es: 'Cliente (Contratista)' },
    '국가 (Country)': { en: 'Country', es: 'País' },
    '국가': { en: 'Country', es: 'País' },
    '타임존': { en: 'Timezone', es: 'Zona Horaria' },
    '현장 주소': { en: 'Site Address', es: 'Dirección de Obra' },
    '주소': { en: 'Address', es: 'Dirección' },
    '직원': { en: 'Employee', es: 'Empleado' },
    '직종': { en: 'Trade', es: 'Oficio' },
    '팀': { en: 'Team', es: 'Equipo' },
    '소속': { en: 'Affiliation', es: 'Afiliación' },
    '이메일': { en: 'Email', es: 'Correo' },
    '전화번호': { en: 'Phone', es: 'Teléfono' },
    '연락처': { en: 'Contact', es: 'Contacto' },
    '국적': { en: 'Nationality', es: 'Nacionalidad' },
    '비자만료': { en: 'Visa Expiry', es: 'Vencim. Visa' },
    '안전교육': { en: 'Safety Training', es: 'Capacitación' },
    '인원ID': { en: 'Person ID', es: 'ID Personal' },
    '직원 번호': { en: 'Employee No.', es: 'N.º de Empleado' },
    '배지 번호': { en: 'Badge No.', es: 'N.º de Credencial' },
    '역할': { en: 'Role', es: 'Rol' },
    '권한': { en: 'Permission', es: 'Permiso' },
    '접근 권한': { en: 'Access Role', es: 'Rol de Acceso' },
    '접근 범위': { en: 'Access Scope', es: 'Alcance de Acceso' },
    '계정 상태': { en: 'Account Status', es: 'Estado de Cuenta' },
    '시작일': { en: 'Start Date', es: 'Fecha de Inicio' },
    '종료일': { en: 'End Date', es: 'Fecha de Fin' },
    '생성일': { en: 'Created', es: 'Creado' },
    '수정일': { en: 'Updated', es: 'Actualizado' },
    '기본정보': { en: 'Basic Info', es: 'Información Básica' },
    '계약': { en: 'Contract', es: 'Contrato' },
    '계약 회사': { en: 'Contract Company', es: 'Empresa Contratante' },
    '팀/조직': { en: 'Teams / Org', es: 'Equipos / Org.' },
    '재무 WBS': { en: 'Finance / WBS', es: 'Finanzas / EDT' },
    '규정/리소스': { en: 'Compliance / Resources', es: 'Normas / Recursos' },
    '현장 일정': { en: 'Site Schedule', es: 'Cronograma' },
    '벤더 등급': { en: 'Vendor Tier', es: 'Nivel de Proveedor' },
    '계약 유형': { en: 'Contract Type', es: 'Tipo de Contrato' },
    '공종': { en: 'Construction Type', es: 'Tipo de Construcción' }
  };

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
