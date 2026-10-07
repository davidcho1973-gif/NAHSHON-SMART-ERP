(function (global) {
  'use strict';
  // One editor is used by account changes and invitations. Server validates every value again.
  function attach(wrap, catalog, sites, saved) {
    var esc = global.AdminUI.esc;
    var panel = document.createElement('div');
    panel.style.cssText = 'grid-column:1/-1;margin:16px 0;overflow:auto';
    panel.innerHTML = '<h3>사무실 담당 업무</h3><div class="job-duties"></div>' +
      '<h3>담당 현장 선택</h3><p>회사 범위는 소속 회사 전체 현장입니다. 공정팀장은 선택한 현장에서 담당 공정의 모든 팀을 관리합니다. 작업반장은 담당 팀을 관리합니다.</p><div class="job-sites"></div>' +
      '<label class="job-trade-wrap">담당 공정 <select name="jobTrade" aria-label="담당 공정"></select></label>' +
      '<h3>업무별 세부 권한</h3><p>조회·작성·승인·지급·삭제·다운로드를 따로 선택합니다. 직책을 바꾸면 기본 권한으로 설정됩니다.</p>' +
      '<table style="width:100%;font-size:12px;border-collapse:collapse"><thead><tr><th>업무</th>' +
      Object.values(catalog.actions).map(function (label) { return '<th>'+esc(label)+'</th>'; }).join('') + '</tr></thead><tbody>' +
      Object.entries(catalog.modules).filter(function (entry) { return entry[0] !== 'system'; }).map(function (entry) {
        return '<tr><td style="padding:8px">'+esc(entry[1])+(entry[0]==='purchasing'?'<small style="display:block">조회: 본인 구매신청 · 작성: 발주 처리</small>':'')+'</td>'+Object.entries(catalog.actions).map(function (action) {
          var disabled = action[0] === 'pay' && !['payroll','finance'].includes(entry[0]);
          return '<td style="text-align:center"><input type="checkbox" aria-label="'+esc(entry[1]+' '+action[1])+'" data-job-module="'+entry[0]+'" data-job-action="'+action[0]+'"'+(disabled?' disabled':'')+'></td>';
        }).join('')+'</tr>';
      }).join('')+'</tbody></table>';
    var anchor=wrap.querySelector('form') || wrap.querySelector('.admin-form-grid') || wrap.querySelector('[name="jobRole"]').parentElement.parentElement;
    anchor.appendChild(panel);
    panel.querySelector('.job-duties').innerHTML=Object.entries(catalog.duties).map(function(entry){return '<label style="display:inline-block;margin:6px"><input type="checkbox" data-job-duty="'+entry[0]+'"> '+esc(entry[1].label)+'</label>';}).join('');
    function renderTrade(){
      var company=wrap.querySelector('[name="companyId"]').value;
      var ids=Array.from(panel.querySelectorAll('[data-job-site]:checked')).map(function(el){return Number(el.dataset.jobSite);});
      var field=panel.querySelector('[name="jobTrade"]'), previous=field.value || saved && saved.jobTrade || '';
      var trades=Array.from(new Set((catalog.trades||[]).filter(function(t){return String(t.companyId)===String(company) && (!ids.length || ids.includes(Number(t.siteId)));}).map(function(t){return t.value;})));
      field.innerHTML='<option value="">담당 공정 선택</option>'+trades.map(function(t){return '<option value="'+esc(t)+'">'+esc(t)+'</option>';}).join('');
      field.value=trades.includes(previous)?previous:'';
      panel.querySelector('.job-trade-wrap').hidden=wrap.querySelector('[name="scope"]').value!=='trade';
    }
    function renderSites(){
      var company=wrap.querySelector('[name="companyId"]').value;
      var selected=Array.from(panel.querySelectorAll('[data-job-site]:checked')).map(function(el){return Number(el.dataset.jobSite);});
      panel.querySelector('.job-sites').innerHTML=sites.filter(function(s){return String(s.companyId)===String(company);}).map(function(s){return '<label style="display:block;margin:6px"><input type="checkbox" data-job-site="'+s.value+'"'+((saved && saved.siteIds || selected).includes(Number(s.value))?' checked':'')+'> '+esc(s.label)+'</label>';}).join('');
      if(saved)delete saved.siteIds;
      renderTrade();
    }
    function defaults(){
      var job=wrap.querySelector('[name="jobRole"]').value;
      var permissions=JSON.parse(JSON.stringify((catalog.jobs[job] || {}).permissions || {}));
      panel.querySelector('.job-duties').parentElement.querySelector('h3').hidden=job!=='office';
      panel.querySelector('.job-duties').hidden=job!=='office';
      if(job==='office')panel.querySelectorAll('[data-job-duty]:checked').forEach(function(el){
        Object.entries(catalog.duties[el.dataset.jobDuty].permissions).forEach(function(entry){permissions[entry[0]]=Array.from(new Set((permissions[entry[0]]||[]).concat(entry[1])));});
      });
      paint(permissions);
    }
    function paint(permissions){panel.querySelectorAll('[data-job-module]').forEach(function(el){el.checked=!el.disabled && (permissions[el.dataset.jobModule]||[]).includes(el.dataset.jobAction);});}
    panel.querySelectorAll('[data-job-duty]').forEach(function(el){el.checked=Boolean(saved && (saved.duties||[]).includes(el.dataset.jobDuty));el.addEventListener('change',defaults);});
    wrap.querySelector('[name="jobRole"]').addEventListener('change',function(){defaults();wrap.querySelector('[name="scope"]').value=(catalog.jobs[this.value]||{}).scope||'site';renderTrade();});
    wrap.querySelector('[name="scope"]').addEventListener('change',renderTrade);
    wrap.querySelector('[name="companyId"]').addEventListener('change',renderSites);
    panel.addEventListener('change',function(ev){var el=ev.target;if(el.dataset.jobSite)renderTrade();if(el.dataset.jobAction && el.dataset.jobAction!=='view' && el.checked){panel.querySelector('[data-job-module="'+el.dataset.jobModule+'"][data-job-action="view"]').checked=true;}});
    defaults();if(saved && saved.permissions)paint(saved.permissions);renderSites();
    return {read:function(){
      var result={jobRole:wrap.querySelector('[name="jobRole"]').value,jobTrade:wrap.querySelector('[name="scope"]').value==='trade'?panel.querySelector('[name="jobTrade"]').value:null,jobDuties:[],jobPermissions:{},siteIds:[]};
      if(result.jobRole==='office')panel.querySelectorAll('[data-job-duty]:checked').forEach(function(el){result.jobDuties.push(el.dataset.jobDuty);});
      panel.querySelectorAll('[data-job-module]:checked').forEach(function(el){(result.jobPermissions[el.dataset.jobModule] ||= []).push(el.dataset.jobAction);});
      panel.querySelectorAll('[data-job-site]:checked').forEach(function(el){result.siteIds.push(Number(el.dataset.jobSite));});
      return result;
    }};
  }
  global.JobPermissionEditor={attach:attach};
})(window);
