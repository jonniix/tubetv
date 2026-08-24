(function(){
  var deferredPrompt=null,buttons=[],installRequested=false,wakeLock=null;
  function isStandalone(){return !!((window.matchMedia&&window.matchMedia('(display-mode: fullscreen), (display-mode: standalone)').matches)||window.navigator.standalone===true)}
  function update(){buttons.forEach(function(button){button.hidden=isStandalone();button.textContent=deferredPrompt?'Installa TubeTV':'Scarica app TV'})}
  function closeHelp(){var old=document.getElementById('tv-install-message');if(old&&old.parentNode)old.parentNode.removeChild(old)}
  function showHelp(){
    closeHelp();var box=document.createElement('div');box.id='tv-install-message';box.style.cssText='position:fixed;inset:0;z-index:2147483647;display:grid;place-items:center;padding:28px;background:rgba(0,0,0,.82);font-family:Arial;color:#fff';
    box.innerHTML='<div style="width:min(680px,92vw);padding:30px;border:1px solid #ffffff2b;border-radius:24px;background:#101620;box-shadow:0 25px 80px #000"><div style="font-size:25px;font-weight:950;margin-bottom:13px"><span style="color:#ef233c">TUBE</span>TV per Android TV</div><div style="color:#c8d1dd;font-size:17px;line-height:1.55">Il browser di questo box non offre l’installazione automatica. Premi il tasto menu del browser e scegli <b style="color:#fff">Installa app</b> oppure <b style="color:#fff">Aggiungi alla schermata Home</b>.<br><br>Se queste voci non compaiono, aggiorna Google Chrome dal Play Store del box e riapri <b style="color:#fff">/tv</b>.</div><button id="tv-install-close" style="margin-top:24px;padding:13px 22px;border:0;border-radius:999px;background:#ef233c;color:#fff;font-size:16px;font-weight:900">Ho capito</button></div>';
    document.body.appendChild(box);document.getElementById('tv-install-close').onclick=closeHelp;setTimeout(function(){try{document.getElementById('tv-install-close').focus()}catch(e){}},30)
  }
  function promptInstall(){if(isStandalone()){showHelp();return}installRequested=true;if(!deferredPrompt){showHelp();return}try{deferredPrompt.prompt();if(deferredPrompt.userChoice&&deferredPrompt.userChoice.then)deferredPrompt.userChoice.then(function(){deferredPrompt=null;installRequested=false;update()})}catch(e){showHelp()}}
  function keepScreenAwake(){if(!navigator.wakeLock||document.visibilityState!=='visible'||wakeLock)return;navigator.wakeLock.request('screen').then(function(lock){wakeLock=lock;lock.addEventListener('release',function(){wakeLock=null})}).catch(function(){wakeLock=null})}
  window.addEventListener('beforeinstallprompt',function(event){event.preventDefault();deferredPrompt=event;update();if(installRequested)promptInstall()});
  window.addEventListener('appinstalled',function(){deferredPrompt=null;installRequested=false;update();closeHelp()});
  document.addEventListener('visibilitychange',keepScreenAwake);
  document.addEventListener('pointerdown',keepScreenAwake,{once:true});
  document.addEventListener('keydown',keepScreenAwake,{once:true});
  document.addEventListener('DOMContentLoaded',function(){buttons=Array.prototype.slice.call(document.querySelectorAll('[data-tv-install]'));buttons.forEach(function(button){button.addEventListener('click',promptInstall)});update();keepScreenAwake();if('serviceWorker'in navigator)navigator.serviceWorker.register('./tv-sw.js?v=4',{scope:'./'}).then(function(reg){try{reg.update()}catch(e){}}).catch(function(){})});
}());
