(function () {
  'use strict';

  const STORAGE_KEY = 'smartCompanyLanguage';
  const CP1252 = {
    '€': 0x80, '‚': 0x82, 'ƒ': 0x83, '„': 0x84, '…': 0x85, '†': 0x86, '‡': 0x87,
    'ˆ': 0x88, '‰': 0x89, 'Š': 0x8A, '‹': 0x8B, 'Œ': 0x8C, 'Ž': 0x8E,
    '‘': 0x91, '’': 0x92, '“': 0x93, '”': 0x94, '•': 0x95, '–': 0x96, '—': 0x97,
    '˜': 0x98, '™': 0x99, 'š': 0x9A, '›': 0x9B, 'œ': 0x9C, 'ž': 0x9E, 'Ÿ': 0x9F,
  };

  const exactEn = new Map(Object.entries({
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
    'NASON í†µí•©ê´€ë¦¬': 'NASON Operations',
    'ì°¨ëŸ‰ ê´€ë¦¬': 'Vehicle Management',
    'ìž¥ë¹„ ë Œíƒˆ ê´€ë¦¬': 'Equipment Rental',
    'ìˆ™ì†Œ ê´€ë¦¬': 'Housing Management',
    'êµ¬ë§¤/ë ŒíŠ¸ ê´€ë¦¬': 'Vendor / Rental Management',
    'í•­ê³µê¶Œ ê´€ë¦¬': 'Flight Management',
    'í˜„ìž¥ì‚¬ë¬´ì‹¤ ë¹„í’ˆ': 'Office Supplies',
    'AI ìŠ¤ìº” í†µí•© ë“±ë¡': 'AI Scan Registration',
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
    'ìƒˆë¡œê³ ì¹¨': 'Refresh'
  }));

  const replacementsEn = [
    ['NAHSHON MEP Â· ì‹¤ì‹œê°„ í˜„ìž¥ ìš´ì˜ í˜„í™©', 'NAHSHON MEP Â· Live field operations'],
    ['ê¸´ê¸‰ ì²˜ë¦¬ í•„ìš”', 'Urgent Action Items'],
    ['í”„ë¡œì íŠ¸ í˜„í™©', 'Project Status'],
    ['ì˜¤ëŠ˜ì˜ ê²°ì • í', "Today's Decision Queue"],
    ['ë¯¸ì²­êµ¬ ë°©ì§€ ì²´í¬', 'Unbilled Work Check'],
    ['AI ìš´ì˜ ë¸Œë¦¬í•‘', 'AI Operations Brief'],
    ['1ë¶„ í˜„ìž¥ ìž…ë ¥', '1-Minute Field Input'],
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
  ];

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
  }

  function translateToEnglish(text) {
    normalizeEnglishMap();
    const trimmed = text.trim();
    if (!trimmed) return text;
    if (normalizedExactEn.has(trimmed)) return text.replace(trimmed, normalizedExactEn.get(trimmed));
    const unitOnly = { '명': 'people', '대': 'units', '건': 'issues', '일': 'days', '개': 'rooms' };
    if (unitOnly[trimmed]) return text.replace(trimmed, unitOnly[trimmed]);
    let output = text;
    for (const [ko, en] of normalizedReplacementsEn) output = output.split(ko).join(en);
    output = output
      .replace(/(\d+)\s*명/g, '$1 people')
      .replace(/(\d+\/\d+)\s*대/g, '$1 units')
      .replace(/(\d+)\s*대/g, '$1 units')
      .replace(/(\d+)\s*건/g, '$1 issues')
      .replace(/(\d+)\s*일/g, '$1 days')
      .replace(/(\d+)\s*개/g, '$1 rooms');
    return output;
  }

  function currentLanguage() {
    return localStorage.getItem(STORAGE_KEY) || 'ko';
  }

  function localizeString(value) {
    if (typeof value !== 'string' || value.length === 0) return value;
    const repaired = repairText(value);
    return currentLanguage() === 'en' ? translateToEnglish(repaired) : repaired;
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
    localStorage.setItem(STORAGE_KEY, lang === 'en' ? 'en' : 'ko');
    window.location.reload();
  };

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