(function () {
  'use strict';
  // Only the home page after server-verified connection clears the previous worker identity.
  // Previewing/cancelling a QR must leave the current user's device and drafts untouched.
  if(document.currentScript?.dataset.personalDeviceVerified==='1'){
    ['dasolWorkerDevice','workerJoinLastPerson'].forEach(function(key){
      try{localStorage.removeItem(key);}catch(_){}
    });
  }
  const form=document.getElementById('personal-connect-form');
  if(!form)return;
  let submitted=false;
  form.addEventListener('submit',function(event){
    if(submitted){event.preventDefault();return;}
    submitted=true;
    const button=form.querySelector('button[type="submit"]');
    if(button)button.disabled=true;
    const message=document.getElementById('connect-status');
    if(message)message.textContent='연결하고 있습니다…';
  });
  // A public GET only previews the invitation. CSRF-protected POST consumes it.
  if(form.dataset.autoConnect==='1')form.requestSubmit();
})();
