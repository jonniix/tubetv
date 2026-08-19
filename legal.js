function loadAdvertisingScripts(){
  if(document.querySelector('script[data-adsense-loaded="1"]'))return;
  const s=document.createElement('script');
  s.async=true;
  s.src='https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-7293180739017211';
  s.crossOrigin='anonymous';
  s.dataset.adsenseLoaded='1';
  document.head.appendChild(s);
}
function setCookieConsent(value){
  localStorage.setItem('tutv_cookie_consent',value);
  document.getElementById('cookie-banner')?.classList.remove('open');
  if(value==='accepted')loadAdvertisingScripts();
}
function initCookieConsent(){
  const consent=localStorage.getItem('tutv_cookie_consent');
  if(consent==='accepted')loadAdvertisingScripts();
  if(!consent)document.getElementById('cookie-banner')?.classList.add('open');
}
document.addEventListener('DOMContentLoaded',initCookieConsent);
