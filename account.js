(() => {
  const SITE_BASE = getSiteBase();
  const API_BASE = SITE_BASE + 'api/auth/';
  const state = {
    user: null,
    loading: true,
    modalOpen: false,
    modalTab: 'login',
    installPrompt: null,
    message: '',
    tvDevices: [],
    remotePairId: '',
    remotePairBusy: false,
    remoteSection: 'remote',
  };

  const dom = {
    navHosts: [],
    modal: null,
    modalMessage: null,
    profileRoot: null,
    profileMessage: null,
    profileForm: null,
    profileInstallButton: null,
    profileLogoutButton: null,
    remoteButton: null,
    remoteModal: null
  };

  function getSiteBase() {
    if (typeof window.getBasePath === 'function') {
      return window.getBasePath();
    }
    const path = String(location.pathname || '/');
    const index = path.lastIndexOf('/');
    return index >= 0 ? path.slice(0, index + 1) : './';
  }

  function authUrl(path) {
    return API_BASE + path;
  }

  function isProfilePage() {
    return /profile\.html$/i.test(location.pathname);
  }

  function escapeHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function initials(name) {
    const text = String(name || 'U').trim();
    if (!text) return 'U';
    return text
      .split(/\s+/)
      .slice(0, 2)
      .map(part => part.charAt(0))
      .join('')
      .toUpperCase();
  }

  function planLabel(user) {
    const subscription = user?.subscription || {};
    if (subscription.adsDisabled) return 'Premium senza ADV';
    if (subscription.plan && String(subscription.plan).toLowerCase() !== 'free') return String(subscription.plan).toUpperCase();
    return 'Piano gratuito';
  }

  function isAdFree() {
    return !!state.user?.subscription?.adsDisabled;
  }

  async function apiFetch(path, options = {}) {
    const response = await fetch(authUrl(path), {
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        ...(options.headers || {})
      },
      ...options
    });

    const text = await response.text();
    let data = null;
    try {
      data = text ? JSON.parse(text) : null;
    } catch (error) {
      data = { ok: false, error: text || 'Risposta non valida dal server.' };
    }

    if (!response.ok && data && data.ok !== true) {
      throw new Error(data.error || 'Richiesta non riuscita.');
    }

    return data || { ok: false, error: 'Risposta vuota dal server.' };
  }

  function showBanner(message, kind = 'info') {
    state.message = message || '';
    if (dom.modal) {
      dom.modal.querySelectorAll('[data-auth-message]').forEach(node => {
        node.textContent = state.message;
        node.dataset.kind = kind;
      });
    }
    if (dom.profileMessage) {
      dom.profileMessage.textContent = state.message;
      dom.profileMessage.dataset.kind = kind;
    }
  }

  function ensureStyles() {
    if (document.getElementById('tt-account-styles')) return;
    const style = document.createElement('style');
    style.id = 'tt-account-styles';
    style.textContent = `
      .tt-account-entry { position: relative; display: flex; align-items: center; }
      .tt-account-button {
        display: inline-flex; align-items: center; gap: .6rem;
        border: 1px solid rgba(255,255,255,.14);
        background: rgba(12,16,24,.78);
        color: #fff; border-radius: 999px; padding: .55rem .9rem;
        font-weight: 800; letter-spacing: .01em; cursor: pointer;
        backdrop-filter: blur(14px); box-shadow: 0 16px 30px rgba(0,0,0,.18);
      }
      .tt-account-button:hover { border-color: rgba(255,255,255,.28); transform: translateY(-1px); }
      .tt-account-avatar {
        width: 2rem; height: 2rem; border-radius: 50%; display: grid; place-items: center;
        background: linear-gradient(135deg, #ffb703, #fb8500); color: #120d06; font-size: .8rem; font-weight: 900;
      }
      .tt-account-name { max-width: 12rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
      .tt-account-menu {
        position: absolute; top: calc(100% + .7rem); right: 0; min-width: 18rem; z-index: 2200;
        padding: .65rem; border-radius: 22px; background: rgba(9,12,18,.96);
        border: 1px solid rgba(255,255,255,.12); box-shadow: 0 28px 60px rgba(0,0,0,.34);
        display: none;
      }
      .tt-account-menu.open { display: block; }
      .tt-account-menu button, .tt-account-menu a {
        width: 100%; display: flex; align-items: center; justify-content: space-between; gap: .75rem;
        padding: .8rem .9rem; border-radius: 16px; color: #fff; text-decoration: none;
        background: transparent; border: 0; font-weight: 700; cursor: pointer; text-align: left;
      }
      .tt-account-menu button:hover, .tt-account-menu a:hover { background: rgba(255,255,255,.08); }
      .tt-account-menu .tt-account-muted { color: rgba(255,255,255,.66); font-size: .86rem; font-weight: 600; }
      .tt-auth-modal {
        position: fixed; inset: 0; z-index: 3000; display: none; align-items: center; justify-content: center;
        padding: 1rem; background: rgba(2,4,10,.78); backdrop-filter: blur(16px);
      }
      .tt-auth-modal.open { display: flex; }
      .tt-auth-panel, .tt-profile-shell {
        width: min(980px, 100%); border-radius: 28px; background: rgba(11,15,22,.98); color: #fff;
        border: 1px solid rgba(255,255,255,.12); box-shadow: 0 30px 80px rgba(0,0,0,.42);
      }
      .tt-auth-panel { overflow: hidden; }
      .tt-auth-top { display: flex; align-items: start; justify-content: space-between; gap: 1rem; padding: 1.4rem 1.4rem 0; }
      .tt-auth-title { font-size: 1.35rem; font-weight: 900; margin: 0; }
      .tt-auth-sub { margin: .35rem 0 0; color: rgba(255,255,255,.72); }
      .tt-auth-close, .tt-profile-close, .tt-action-btn {
        border: 0; cursor: pointer; border-radius: 999px; font-weight: 800;
      }
      .tt-auth-close, .tt-profile-close { background: rgba(255,255,255,.08); color: #fff; padding: .65rem .9rem; }
      .tt-auth-tabs { display: flex; gap: .55rem; padding: 1rem 1.4rem 0; }
      .tt-auth-tab {
        border: 0; cursor: pointer; color: rgba(255,255,255,.8); background: rgba(255,255,255,.06);
        padding: .75rem 1rem; border-radius: 999px; font-weight: 800;
      }
      .tt-auth-tab.active { background: linear-gradient(135deg, #ffb703, #fb8500); color: #111; }
      .tt-auth-body { display: grid; grid-template-columns: 1fr 1fr; gap: 1.2rem; padding: 1.2rem 1.4rem 1.4rem; }
      .tt-auth-card {
        background: rgba(255,255,255,.04); border: 1px solid rgba(255,255,255,.09); border-radius: 24px; padding: 1.15rem;
      }
      .tt-auth-card h3, .tt-profile-card h3 { margin: 0 0 .5rem; font-size: 1.1rem; }
      .tt-auth-form, .tt-profile-form { display: grid; gap: .9rem; }
      .tt-field { display: grid; gap: .35rem; }
      .tt-field label { font-size: .84rem; color: rgba(255,255,255,.74); font-weight: 700; }
      .tt-field input, .tt-field select, .tt-field textarea {
        width: 100%; border-radius: 16px; border: 1px solid rgba(255,255,255,.12);
        background: rgba(7,10,15,.9); color: #fff; padding: .9rem 1rem; font: inherit;
      }
      .tt-field input:focus, .tt-field select:focus, .tt-field textarea:focus { outline: 2px solid rgba(255,183,3,.45); outline-offset: 1px; }
      .tt-checkline { display: flex; align-items: center; gap: .65rem; color: rgba(255,255,255,.76); font-size: .92rem; }
      .tt-action-btn {
        display: inline-flex; align-items: center; justify-content: center; gap: .4rem; padding: .92rem 1.1rem;
        background: linear-gradient(135deg, #ffb703, #fb8500); color: #111; box-shadow: 0 16px 30px rgba(251,133,0,.24);
      }
      .tt-action-btn.secondary { background: rgba(255,255,255,.08); color: #fff; box-shadow: none; }
      .tt-auth-message, .tt-profile-message {
        min-height: 1.2rem; color: #ffcf66; font-weight: 700; margin-bottom: .4rem;
      }
      .tt-auth-message[data-kind="error"], .tt-profile-message[data-kind="error"] { color: #ff8a8a; }
      .tt-auth-message[data-kind="success"], .tt-profile-message[data-kind="success"] { color: #9af0bf; }
      .tt-profile-page {
        min-height: 100vh; background: radial-gradient(circle at top, rgba(255,183,3,.16), transparent 34%), linear-gradient(180deg, #09101b 0%, #06080d 100%);
        color: #fff;
      }
      .tt-profile-hero {
        display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 1.25rem 1.3rem;
        border-bottom: 1px solid rgba(255,255,255,.08); backdrop-filter: blur(14px);
        position: sticky; top: 0; background: rgba(5,7,12,.82); z-index: 10;
      }
      .tt-profile-hero h1 { margin: 0; font-size: clamp(1.35rem, 2vw, 2rem); font-weight: 950; }
      .tt-profile-hero p { margin: .25rem 0 0; color: rgba(255,255,255,.72); }
      .tt-profile-shell { width: min(1120px, calc(100% - 2rem)); margin: 1.4rem auto 2.5rem; padding: 1.2rem; }
      .tt-profile-grid { display: grid; grid-template-columns: 1.2fr .8fr; gap: 1rem; }
      .tt-profile-card { background: rgba(255,255,255,.05); border: 1px solid rgba(255,255,255,.09); border-radius: 24px; padding: 1.1rem; }
      .tt-profile-summary { display: flex; align-items: center; gap: 1rem; margin-bottom: 1rem; }
      .tt-profile-badge { display: inline-flex; padding: .35rem .7rem; border-radius: 999px; background: rgba(255,183,3,.12); color: #ffcf66; font-weight: 800; font-size: .84rem; }
      .tt-profile-meta { color: rgba(255,255,255,.7); font-size: .95rem; }
      .tt-profile-stacked { display: grid; gap: .7rem; }
      .tt-profile-actions { display: flex; flex-wrap: wrap; gap: .7rem; }
      .tt-profile-note { margin-top: .35rem; color: rgba(255,255,255,.68); font-size: .92rem; }
      .tt-profile-install { display: none; }
      .tt-profile-install.visible { display: inline-flex; }
      .tt-remote-fab { position:fixed;right:16px;bottom:calc(82px + env(safe-area-inset-bottom));z-index:2450;width:58px;height:58px;border-radius:19px;border:1px solid rgba(255,255,255,.17);background:linear-gradient(145deg,#ffbd18,#fb8500);color:#150f07;display:none;place-items:center;box-shadow:0 18px 45px rgba(0,0,0,.4);cursor:pointer }
      .tt-remote-fab.visible { display:grid; }.tt-remote-fab svg{width:28px;height:28px}.tt-remote-fab i{position:absolute;right:5px;top:5px;width:9px;height:9px;border-radius:50%;background:#3ee58d;border:2px solid #171009}
      .tt-remote-modal{position:fixed;inset:0;z-index:3500;display:none;align-items:flex-end;justify-content:center;background:rgba(2,4,9,.76);backdrop-filter:blur(13px)}.tt-remote-modal.open{display:flex}
      .tt-remote-panel{width:min(440px,100%);max-height:94svh;overflow:auto;border-radius:30px 30px 0 0;padding:20px 20px calc(20px + env(safe-area-inset-bottom));background:#0d1119;border:1px solid rgba(255,255,255,.12);box-shadow:0 -25px 70px rgba(0,0,0,.5)}
      .tt-remote-head{display:flex;align-items:center;justify-content:space-between;gap:12px}.tt-remote-head h2{margin:0;font-size:21px}.tt-remote-close{border:0;border-radius:999px;background:rgba(255,255,255,.08);color:#fff;padding:9px 12px;font-weight:800}.tt-remote-device{width:100%;margin:15px 0;padding:12px 14px;border-radius:15px;border:1px solid rgba(255,255,255,.1);background:#080b11;color:#fff;font:700 14px system-ui}
      .tt-remote-shortcuts,.tt-remote-media{display:grid;gap:9px}.tt-remote-shortcuts{grid-template-columns:repeat(4,1fr)}.tt-remote-media{grid-template-columns:repeat(2,1fr)}.tt-remote-key{min-height:50px;border:1px solid rgba(255,255,255,.09);border-radius:16px;background:rgba(255,255,255,.065);color:#fff;font:800 13px system-ui;cursor:pointer}.tt-remote-key:active{transform:scale(.95);background:rgba(255,183,3,.22)}.tt-remote-key.accent{background:linear-gradient(135deg,#ffb703,#fb8500);color:#171009}
      .tt-remote-pad{width:220px;height:220px;margin:17px auto;display:grid;grid-template:repeat(3,1fr)/repeat(3,1fr);gap:8px}.tt-remote-pad .tt-remote-key{border-radius:22px;font-size:22px}.tt-remote-pad [data-command="UP"]{grid-column:2}.tt-remote-pad [data-command="LEFT"]{grid-row:2;grid-column:1}.tt-remote-pad [data-command="OK"]{grid-row:2;grid-column:2;border-radius:50%;font-size:13px}.tt-remote-pad [data-command="RIGHT"]{grid-row:2;grid-column:3}.tt-remote-pad [data-command="DOWN"]{grid-row:3;grid-column:2}.tt-remote-status{text-align:center;min-height:20px;margin-top:11px;color:rgba(255,255,255,.62);font-size:12px}.tt-remote-empty{margin:15px 0;padding:18px;border:1px dashed rgba(255,255,255,.16);border-radius:18px;background:rgba(255,255,255,.035);color:rgba(255,255,255,.72);text-align:center;line-height:1.5}.tt-remote-empty strong{display:block;color:#fff;margin-bottom:5px}.tt-remote-pair{margin-top:16px;padding-top:15px;border-top:1px solid rgba(255,255,255,.09)}.tt-remote-pair label{display:block;margin-bottom:8px;color:rgba(255,255,255,.72);font-size:12px;font-weight:800}.tt-remote-pair-row{display:grid;grid-template-columns:1fr auto;gap:8px}.tt-remote-pair input{min-width:0;border:1px solid rgba(255,255,255,.12);border-radius:14px;background:#080b11;color:#fff;padding:12px;text-align:center;font:900 18px system-ui;letter-spacing:.18em}.tt-remote-pair button{border:0;border-radius:14px;padding:0 14px;background:#ffb703;color:#171009;font-weight:900}
      .tt-remote-auth{margin:10px 0 14px;padding:14px;border:1px solid rgba(255,183,3,.24);border-radius:17px;background:rgba(255,183,3,.06)}.tt-remote-auth strong,.tt-remote-auth small{display:block}.tt-remote-auth small{margin-top:5px;color:rgba(255,255,255,.62)}.tt-remote-auth-row{display:grid;grid-template-columns:1fr auto;gap:8px;margin-top:11px}.tt-remote-auth input{min-width:0;padding:11px;border:1px solid rgba(255,255,255,.14);border-radius:12px;background:#080b11;color:#fff;text-align:center;font:900 20px system-ui;letter-spacing:.2em}.tt-remote-auth button,.tt-channel-open{border:0;border-radius:12px;padding:0 13px;background:#ffb703;color:#171009;font-weight:900}.tt-smart-guide{margin:14px 0;padding-top:13px;border-top:1px solid rgba(255,255,255,.1)}.tt-smart-guide h3{margin:0 0 9px;font-size:15px}.tt-channel-search{width:100%;padding:12px 13px;border:1px solid rgba(255,255,255,.12);border-radius:14px;background:#080b11;color:#fff;font:700 14px system-ui}.tt-channel-results{display:grid;gap:7px;max-height:240px;overflow:auto;margin-top:9px}.tt-channel-card{display:grid;grid-template-columns:42px 1fr auto;align-items:center;gap:10px;width:100%;padding:9px;border:1px solid rgba(255,255,255,.08);border-radius:14px;background:rgba(255,255,255,.045);color:#fff;text-align:left}.tt-channel-card img,.tt-channel-fallback{width:42px;height:42px;object-fit:contain;border-radius:9px;background:#080b11}.tt-channel-fallback{display:grid;place-items:center;font-weight:900}.tt-channel-copy{min-width:0}.tt-channel-copy strong,.tt-channel-copy small{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.tt-channel-copy small{margin-top:4px;color:rgba(255,255,255,.55);font-size:11px}.tt-channel-guide{grid-column:1/-1;padding:9px;border-top:1px solid rgba(255,255,255,.08);color:rgba(255,255,255,.72);font-size:11px}.tt-channel-guide b{display:block;color:#fff;margin-bottom:3px}
      .tt-remote-panel{background:radial-gradient(circle at 85% 0,rgba(230,57,70,.14),transparent 28%),linear-gradient(180deg,#111722,#090c12);scrollbar-width:none}.tt-remote-panel::-webkit-scrollbar{display:none}.tt-remote-head{position:sticky;top:-20px;z-index:4;margin:-20px -20px 0;padding:20px;background:rgba(12,16,24,.94);backdrop-filter:blur(18px);border-bottom:1px solid rgba(255,255,255,.07)}.tt-remote-head h2{letter-spacing:-.035em}.tt-remote-device{margin-bottom:10px}.tt-remote-tabs{position:sticky;top:61px;z-index:3;display:grid;grid-template-columns:repeat(4,1fr);gap:6px;margin:10px -4px 15px;padding:6px;border:1px solid rgba(255,255,255,.08);border-radius:18px;background:rgba(5,8,13,.92);backdrop-filter:blur(16px)}.tt-remote-tab{display:grid;place-items:center;gap:4px;min-height:55px;padding:6px 2px;border:0;border-radius:13px;background:transparent;color:#8f9aaa;font:800 10px system-ui}.tt-remote-tab svg{width:20px;height:20px;stroke:currentColor}.tt-remote-tab.active{background:linear-gradient(145deg,#ef233c,#bf1328);color:#fff;box-shadow:0 8px 22px rgba(239,35,60,.25)}.tt-remote-view{display:none;min-height:360px}.tt-remote-view.active{display:block}.tt-remote-command-view .tt-remote-shortcuts{grid-template-columns:repeat(3,1fr)}.tt-remote-command-view .tt-remote-shortcuts .tt-remote-key:last-child{grid-column:1/-1}.tt-remote-key{position:relative;overflow:hidden;background:linear-gradient(145deg,rgba(255,255,255,.09),rgba(255,255,255,.035));box-shadow:inset 0 1px rgba(255,255,255,.06)}.tt-remote-pad{position:relative;width:238px;height:238px;padding:9px;border-radius:50%;background:radial-gradient(circle,#181f2b 0 30%,#0a0e15 31% 66%,#202837 67% 68%,#080b11 69%);box-shadow:0 22px 44px rgba(0,0,0,.35),inset 0 0 0 1px rgba(255,255,255,.08)}.tt-remote-pad .tt-remote-key{background:transparent;border:0;box-shadow:none}.tt-remote-pad [data-command="OK"]{background:linear-gradient(145deg,#f7f8fa,#aeb7c4);color:#090c11;box-shadow:0 8px 20px rgba(0,0,0,.35)}.tt-section-title{display:flex;align-items:end;justify-content:space-between;gap:10px;margin:4px 2px 13px}.tt-section-title h3{margin:0;font-size:19px;letter-spacing:-.03em}.tt-section-title small{color:#778294}.tt-category-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:9px}.tt-category-card{display:flex;align-items:center;gap:11px;min-height:75px;padding:12px;border:1px solid rgba(255,255,255,.08);border-radius:18px;background:linear-gradient(145deg,#161d29,#0d1118);color:#fff;text-align:left}.tt-category-card svg{width:27px;height:27px;flex:0 0 27px;stroke:#ff5669}.tt-category-card span{min-width:0}.tt-category-card strong,.tt-category-card small{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.tt-category-card small{margin-top:4px;color:#7f8a9b;font-size:10px}.tt-category-card.active{border-color:#ef233c;background:rgba(239,35,60,.12)}.tt-phone-search{width:100%;margin-bottom:10px;padding:13px 14px;border:1px solid rgba(255,255,255,.1);border-radius:15px;background:#070a10;color:#fff;font:700 14px system-ui}.tt-phone-list{display:grid;gap:8px;max-height:47svh;overflow:auto;padding-bottom:8px}.tt-phone-channel{display:grid;grid-template-columns:45px minmax(0,1fr) auto;align-items:center;gap:10px;padding:10px;border:1px solid rgba(255,255,255,.075);border-radius:16px;background:rgba(255,255,255,.045)}.tt-phone-channel img,.tt-phone-logo{width:45px;height:45px;object-fit:contain;border-radius:10px;background:#070a10}.tt-phone-logo{display:grid;place-items:center;color:#ef5365;font-weight:950}.tt-phone-channel-copy{min-width:0}.tt-phone-channel-copy strong,.tt-phone-channel-copy small{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.tt-phone-channel-copy small{margin-top:4px;color:#7f8a9b;font-size:10px}.tt-phone-actions{display:flex;gap:5px}.tt-phone-action{display:grid;place-items:center;width:36px;height:36px;border:0;border-radius:11px;background:rgba(255,255,255,.09);color:#fff}.tt-phone-action.primary{background:#ef233c}.tt-phone-action svg{width:17px;height:17px;stroke:currentColor}.tt-guide-preview{grid-column:1/-1;margin-top:2px;padding:11px;border-radius:12px;background:#080b11}.tt-guide-row{display:grid;grid-template-columns:48px 1fr auto;gap:8px;padding:9px 0;border-bottom:1px solid rgba(255,255,255,.07);font-size:11px}.tt-guide-row:last-child{border:0}.tt-guide-row time{color:#ffb703;font-weight:900}.tt-guide-row b{font-size:12px}.tt-guide-live{align-self:start;padding:3px 6px;border-radius:99px;background:#ef233c;color:#fff;font-size:8px;font-weight:950}.tt-program-hero{display:flex;align-items:center;gap:12px;margin-bottom:11px;padding:14px;border:1px solid rgba(239,35,60,.2);border-radius:18px;background:linear-gradient(120deg,rgba(239,35,60,.14),rgba(255,255,255,.035))}.tt-program-hero img,.tt-program-hero .tt-phone-logo{width:52px;height:52px}.tt-program-hero strong,.tt-program-hero small{display:block}.tt-program-hero small{margin-top:4px;color:#8c97a8}.tt-smart-guide{display:none!important}
      .tt-remote-brand{display:block!important}.tt-remote-brand h2{font-size:24px;font-weight:950;letter-spacing:-.055em}.tt-brand-tube{color:#ef233c}.tt-brand-tv{color:#fff}.tt-remote-auth{display:none!important}.tt-category-back{display:flex;align-items:center;gap:8px;margin:0 0 14px;padding:10px 13px;border:1px solid rgba(255,255,255,.1);border-radius:13px;background:rgba(255,255,255,.06);color:#fff;font:850 13px system-ui}.tt-category-back svg{width:18px;height:18px;stroke:currentColor}.tt-category-page-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:12px}.tt-category-page-head h3{margin:0;font-size:21px}.tt-category-page-head small{color:#7f8a9b}
      @media (max-width: 900px) {
        .tt-auth-body, .tt-profile-grid { grid-template-columns: 1fr; }
        .tt-account-name { max-width: 8rem; }
      }
      @media (max-width: 640px) {
        .tt-auth-panel, .tt-profile-shell { border-radius: 22px; }
        .tt-auth-top, .tt-auth-tabs, .tt-auth-body { padding-left: 1rem; padding-right: 1rem; }
        .tt-profile-shell { width: calc(100% - 1rem); padding: .9rem; }
        .tt-profile-hero { padding: 1rem .8rem; }
      }
    `;
    document.head.appendChild(style);
  }

  function buildAuthModal() {
    if (dom.modal) return dom.modal;
    const modal = document.createElement('div');
    modal.id = 'tt-auth-modal';
    modal.className = 'tt-auth-modal';
    modal.innerHTML = `
      <div class="tt-auth-panel" role="dialog" aria-modal="true" aria-labelledby="tt-auth-title">
        <div class="tt-auth-top">
          <div>
            <h2 class="tt-auth-title" id="tt-auth-title">Accedi o crea un account</h2>
            <p class="tt-auth-sub">Un profilo unico per sincronizzare preferenze, abbonamenti e download app.</p>
          </div>
          <button class="tt-auth-close" type="button" data-auth-close>Chiudi</button>
        </div>
        <div class="tt-auth-tabs" role="tablist">
          <button class="tt-auth-tab active" type="button" data-auth-tab="login">Accedi</button>
          <button class="tt-auth-tab" type="button" data-auth-tab="register">Registrati</button>
        </div>
        <div class="tt-auth-body">
          <div class="tt-auth-card" data-auth-panel="login">
            <h3>Accedi</h3>
            <div class="tt-auth-message" data-auth-message></div>
            <form class="tt-auth-form" data-auth-form="login">
              <div class="tt-field">
                <label for="tt-login-email">Email</label>
                <input id="tt-login-email" name="email" type="email" autocomplete="email" required>
              </div>
              <div class="tt-field">
                <label for="tt-login-password">Password</label>
                <input id="tt-login-password" name="password" type="password" autocomplete="current-password" required>
              </div>
              <button class="tt-action-btn" type="submit">Entra nel profilo</button>
            </form>
          </div>
          <div class="tt-auth-card" data-auth-panel="register">
            <h3>Registrati</h3>
            <div class="tt-auth-message" data-auth-message></div>
            <form class="tt-auth-form" data-auth-form="register">
              <div class="tt-field">
                <label for="tt-register-name">Nome</label>
                <input id="tt-register-name" name="name" type="text" autocomplete="name" required>
              </div>
              <div class="tt-field">
                <label for="tt-register-email">Email</label>
                <input id="tt-register-email" name="email" type="email" autocomplete="email" required>
              </div>
              <div class="tt-field">
                <label for="tt-register-password">Password</label>
                <input id="tt-register-password" name="password" type="password" autocomplete="new-password" required>
              </div>
              <div class="tt-field">
                <label for="tt-register-confirm">Conferma password</label>
                <input id="tt-register-confirm" name="confirmPassword" type="password" autocomplete="new-password" required>
              </div>
              <label class="tt-checkline"><input type="checkbox" name="consent" value="1" required> Accetto termini e privacy</label>
              <button class="tt-action-btn" type="submit">Crea account</button>
            </form>
          </div>
        </div>
      </div>
    `;
    document.body.appendChild(modal);
    dom.modal = modal;
    dom.modalMessage = modal.querySelector('[data-auth-message]');
    modal.addEventListener('click', event => {
      if (event.target === modal) {
        closeModal();
      }
    });
    modal.querySelectorAll('[data-auth-tab]').forEach(button => {
      button.addEventListener('click', () => setModalTab(button.dataset.authTab || 'login'));
    });
    modal.querySelector('[data-auth-close]')?.addEventListener('click', closeModal);
    modal.querySelector('[data-auth-form="login"]')?.addEventListener('submit', submitLogin);
    modal.querySelector('[data-auth-form="register"]')?.addEventListener('submit', submitRegister);
    return modal;
  }

  function openModal(tab = 'login') {
    buildAuthModal();
    setModalTab(tab);
    dom.modal.classList.add('open');
    state.modalOpen = true;
    document.body.style.overflow = 'hidden';
  }

  function closeModal() {
    if (!dom.modal) return;
    dom.modal.classList.remove('open');
    state.modalOpen = false;
    document.body.style.overflow = '';
  }

  function setModalTab(tab) {
    state.modalTab = tab === 'register' ? 'register' : 'login';
    if (!dom.modal) return;
    dom.modal.querySelectorAll('[data-auth-tab]').forEach(button => {
      button.classList.toggle('active', button.dataset.authTab === state.modalTab);
    });
    dom.modal.querySelectorAll('[data-auth-panel]').forEach(panel => {
      panel.style.display = panel.dataset.authPanel === state.modalTab ? 'block' : 'none';
    });
    dom.modal.querySelectorAll('[data-auth-message]').forEach(message => {
      message.textContent = state.message || '';
      message.dataset.kind = message.textContent ? 'info' : '';
    });
  }

  function closeMenus() {
    document.querySelectorAll('.tt-account-menu.open').forEach(menu => menu.classList.remove('open'));
  }

  function toggleMenu(menu) {
    const shouldOpen = !menu.classList.contains('open');
    closeMenus();
    menu.classList.toggle('open', shouldOpen);
  }

  function buildNavEntry(host) {
    const existing = host.querySelector('.tt-account-entry');
    if (existing) {
      return existing;
    }

    const entry = document.createElement('div');
    entry.className = 'tt-account-entry';
    entry.innerHTML = `
      <button class="tt-account-button" type="button" data-account-toggle>
        <span class="tt-account-avatar">U</span>
        <span class="tt-account-name">Accedi / Registrati</span>
      </button>
      <div class="tt-account-menu" data-account-menu></div>
    `;
    host.appendChild(entry);

    entry.querySelector('[data-account-toggle]')?.addEventListener('click', () => {
      if (!state.user) {
        openModal('login');
        return;
      }
      toggleMenu(entry.querySelector('[data-account-menu]'));
    });

    return entry;
  }

  function renderNav() {
    dom.navHosts = Array.from(document.querySelectorAll('.nav-links'));
    dom.navHosts.forEach(host => {
      const entry = buildNavEntry(host);
      const button = entry.querySelector('[data-account-toggle]');
      const avatar = entry.querySelector('.tt-account-avatar');
      const name = entry.querySelector('.tt-account-name');
      const menu = entry.querySelector('[data-account-menu]');

      if (!state.user) {
        if (avatar) avatar.textContent = 'U';
        if (name) name.textContent = 'Accedi / Registrati';
        if (menu) {
          menu.classList.remove('open');
          menu.innerHTML = `
            <button type="button" data-open-login>Accedi <span class="tt-account-muted">entra con il tuo account</span></button>
            <a href="profile.html">Profilo <span class="tt-account-muted">preferenze e app</span></a>
          `;
          menu.querySelector('[data-open-login]')?.addEventListener('click', () => openModal('login'));
        }
        return;
      }

      const displayName = state.user.name || state.user.email || 'Utente';
      if (avatar) avatar.textContent = initials(displayName);
      if (name) name.textContent = displayName;
      if (menu) {
        menu.classList.remove('open');
        menu.innerHTML = `
          <a href="profile.html">Il mio profilo <span class="tt-account-muted">${escapeHtml(planLabel(state.user))}</span></a>
          <a href="profile.html#subscription">Abbonamenti <span class="tt-account-muted">gestione e piani</span></a>
          <a href="profile.html#download">Download app <span class="tt-account-muted">installazione PWA</span></a>
          <button type="button" data-open-settings>Impostazioni <span class="tt-account-muted">lingua e preferenze</span></button>
          <button type="button" data-logout>Esci <span class="tt-account-muted">logout sicuro</span></button>
        `;
        menu.querySelector('[data-open-settings]')?.addEventListener('click', () => {
          closeMenus();
          window.location.href = 'profile.html#settings';
        });
        menu.querySelector('[data-logout]')?.addEventListener('click', () => logout());
      }
    });
  }

  function renderProfileShell() {
    dom.profileRoot = document.getElementById('profile-app') || document.body;
    if (!dom.profileRoot) return;

    if (!isProfilePage()) return;

    if (!state.user) {
      dom.profileRoot.innerHTML = `
        <section class="tt-profile-page">
          <header class="tt-profile-hero">
            <div>
              <h1>Profilo</h1>
              <p>Accedi per gestire account, abbonamenti e download app.</p>
            </div>
            <button class="tt-profile-close" type="button" id="tt-profile-open-auth">Accedi</button>
          </header>
          <main class="tt-profile-shell">
            <div class="tt-profile-card">
              <h3>Account richiesto</h3>
              <p class="tt-profile-meta">Per vedere preferenze, abbonamenti e sincronizzazione basta entrare nel tuo account.</p>
              <div class="tt-profile-actions" style="margin-top:1rem;">
                <button class="tt-action-btn" type="button" id="tt-profile-open-login">Accedi</button>
                <button class="tt-action-btn secondary" type="button" id="tt-profile-open-register">Registrati</button>
              </div>
            </div>
          </main>
        </section>
      `;
      document.getElementById('tt-profile-open-auth')?.addEventListener('click', () => openModal('login'));
      document.getElementById('tt-profile-open-login')?.addEventListener('click', () => openModal('login'));
      document.getElementById('tt-profile-open-register')?.addEventListener('click', () => openModal('register'));
      return;
    }

    const user = state.user;
    const pref = user.preferences || {};
    const sub = user.subscription || {};
    dom.profileRoot.innerHTML = `
      <section class="tt-profile-page">
        <header class="tt-profile-hero">
          <div>
            <h1>Il mio profilo</h1>
            <p>Preferenze, abbonamenti e installazione rapida dell'app.</p>
          </div>
          <div class="tt-profile-actions">
            <button class="tt-profile-close" type="button" id="tt-profile-logout">Esci</button>
          </div>
        </header>
        <main class="tt-profile-shell">
          <div class="tt-profile-message" data-profile-message></div>
          <div class="tt-profile-grid">
            <section class="tt-profile-card">
              <div class="tt-profile-summary">
                <div class="tt-account-avatar" style="width:3rem;height:3rem;font-size:1rem;">${escapeHtml(initials(user.name || user.email))}</div>
                <div>
                  <h3 style="margin-bottom:.2rem;">${escapeHtml(user.name || 'Utente')}</h3>
                  <div class="tt-profile-meta">${escapeHtml(user.email || '')}</div>
                  <div class="tt-profile-badge" style="margin-top:.5rem;">${escapeHtml(planLabel(user))}</div>
                </div>
              </div>
              <form class="tt-profile-form" id="tt-profile-form">
                <div class="tt-field">
                  <label for="tt-profile-name">Nome</label>
                  <input id="tt-profile-name" name="name" type="text" value="${escapeHtml(user.name || '')}">
                </div>
                <div class="tt-field">
                  <label for="tt-profile-email">Email</label>
                  <input id="tt-profile-email" name="email" type="email" value="${escapeHtml(user.email || '')}">
                </div>
                <div class="tt-field">
                  <label for="tt-profile-language">Lingua</label>
                  <select id="tt-profile-language" name="language">
                    ${['it', 'en', 'es', 'fr'].map(code => `<option value="${code}" ${String(user.language || pref.language || 'it') === code ? 'selected' : ''}>${code.toUpperCase()}</option>`).join('')}
                  </select>
                </div>
                <label class="tt-checkline"><input type="checkbox" name="subtitlesDefault" ${pref.subtitlesDefault ? 'checked' : ''}> Sottotitoli di default attivi</label>
                <label class="tt-checkline"><input type="checkbox" name="autoplay" ${pref.autoplay === false ? '' : 'checked'}> Riproduzione automatica</label>
                <div class="tt-field">
                  <label for="tt-profile-theme">Tema preferito</label>
                  <select id="tt-profile-theme" name="theme">
                    ${['system', 'light', 'dark'].map(theme => `<option value="${theme}" ${String(pref.theme || 'system') === theme ? 'selected' : ''}>${theme === 'system' ? 'Sistema' : theme.charAt(0).toUpperCase() + theme.slice(1)}</option>`).join('')}
                  </select>
                </div>
                <button class="tt-action-btn" type="submit">Salva profilo</button>
              </form>
            </section>
            <aside class="tt-profile-card" id="subscription">
              <h3>Abbonamento</h3>
              <p class="tt-profile-meta">Stato attuale: <strong>${escapeHtml(planLabel(user))}</strong></p>
              <div class="tt-profile-stacked">
                <div class="tt-profile-card" style="background:rgba(255,255,255,.03);padding:.95rem;border-radius:18px;">
                  <strong>Piano</strong>
                  <div class="tt-profile-note">${sub.adsDisabled ? 'ADV disattivata per questo account.' : 'Hai accesso al piano gratuito con ADV.'}</div>
                </div>
                <div class="tt-profile-actions">
                  <button class="tt-action-btn secondary" type="button" data-placeholder-action="premium">Passa a Premium</button>
                  <button class="tt-action-btn secondary" type="button" data-placeholder-action="manage">Gestisci abbonamento</button>
                </div>
              </div>
              <div class="tt-profile-note" style="margin-top:1rem;">Le funzioni di pagamento sono pronte come interfaccia, ma il flusso reale non è ancora collegato.</div>
            </aside>
            <section class="tt-profile-card" id="download">
              <h3>Download app</h3>
              <p class="tt-profile-meta">Installa TubeTV come app sul dispositivo per un accesso più rapido.</p>
              <div class="tt-profile-actions">
                <button class="tt-action-btn" type="button" id="tt-profile-install" data-install-button>Installa app</button>
                <button class="tt-action-btn secondary" type="button" data-placeholder-action="shortcut">Crea collegamento</button>
              </div>
              <div class="tt-profile-note">Se il browser non mostra il prompt, usa il menu del browser per aggiungere TubeTV alla schermata Home.</div>
            </section>
            <section class="tt-profile-card" id="settings">
              <h3>Impostazioni rapide</h3>
              <p class="tt-profile-meta">Queste preferenze vengono sincronizzate con il tuo profilo.</p>
              <div class="tt-profile-actions">
                <button class="tt-action-btn secondary" type="button" data-placeholder-action="notifications">Notifiche</button>
                <button class="tt-action-btn secondary" type="button" data-placeholder-action="privacy">Privacy</button>
                <button class="tt-action-btn secondary" type="button" data-placeholder-action="devices">Dispositivi</button>
              </div>
            </section>
          </div>
        </main>
      </section>
    `;

    dom.profileMessage = dom.profileRoot.querySelector('[data-profile-message]');
    dom.profileForm = dom.profileRoot.querySelector('#tt-profile-form');
    dom.profileInstallButton = dom.profileRoot.querySelector('[data-install-button]');
    dom.profileLogoutButton = dom.profileRoot.querySelector('#tt-profile-logout');

    dom.profileForm?.addEventListener('submit', submitProfile);
    dom.profileLogoutButton?.addEventListener('click', logout);
    dom.profileRoot.querySelectorAll('[data-placeholder-action]').forEach(button => {
      button.addEventListener('click', () => {
        showBanner('Questa funzione è pronta come interfaccia, ma non è ancora collegata.', 'info');
      });
    });

    updateInstallButton();
  }

  function updateInstallButton() {
    if (!dom.profileInstallButton) return;
    dom.profileInstallButton.classList.toggle('visible', !!state.installPrompt);
    dom.profileInstallButton.disabled = !state.installPrompt;
    dom.profileInstallButton.onclick = async () => {
      if (!state.installPrompt) return;
      state.installPrompt.prompt();
      await state.installPrompt.userChoice.catch(() => null);
      state.installPrompt = null;
      updateInstallButton();
    };
  }

  function ensureRemoteUi() {
    if (dom.remoteButton || /tvdevice=1/i.test(location.search)) return;
    const button = document.createElement('button');
    button.type = 'button'; button.className = 'tt-remote-fab'; button.setAttribute('aria-label', 'Telecomando TubeTV');
    button.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="7" y="2" width="10" height="20" rx="3"/><circle cx="12" cy="7" r="1.8"/><path d="M10 12h4M12 10v4M10 18h.01M14 18h.01"/></svg><i></i>';
    const modal = document.createElement('div'); modal.className = 'tt-remote-modal';
    modal.innerHTML = '<div class="tt-remote-panel" role="dialog" aria-modal="true" aria-label="Telecomando TubeTV"><div class="tt-remote-head"><div><h2>Telecomando TubeTV</h2><small style="color:rgba(255,255,255,.6)">Controlla la TV collegata</small></div><button class="tt-remote-close">Chiudi</button></div><div class="tt-remote-empty" hidden><strong>Nessuna TV collegata</strong>Apri <b>/tv</b> sul televisore e scansiona il QR con questo telefono.</div><select class="tt-remote-device" aria-label="TV collegata"></select><div class="tt-remote-shortcuts"><button class="tt-remote-key" data-command="HOME">Home</button><button class="tt-remote-key accent" data-command="TV">TV</button><button class="tt-remote-key" data-command="LIVE">Live</button><button class="tt-remote-key" data-command="TV_LITE">TV Lite</button></div><div class="tt-remote-pad"><button class="tt-remote-key" data-command="UP">↑</button><button class="tt-remote-key" data-command="LEFT">←</button><button class="tt-remote-key accent" data-command="OK">OK</button><button class="tt-remote-key" data-command="RIGHT">→</button><button class="tt-remote-key" data-command="DOWN">↓</button></div><div class="tt-remote-media"><button class="tt-remote-key" data-command="BACK">Indietro</button><button class="tt-remote-key" data-command="PLAY_PAUSE">Play / Pausa</button><button class="tt-remote-key" data-command="VOLUME_UP">Volume +</button><button class="tt-remote-key" data-command="VOLUME_DOWN">Volume −</button></div><div class="tt-remote-status"></div></div>';
    modal.querySelector('[data-command="TV_LITE"]').textContent='Ricarica TV';
    const remoteBrand=modal.querySelector('.tt-remote-head>div');remoteBrand.classList.add('tt-remote-brand');remoteBrand.querySelector('h2').innerHTML='<span class="tt-brand-tube">TUBE</span><span class="tt-brand-tv">TV</span>';
    const pairing=document.createElement('div');pairing.className='tt-remote-pair';pairing.innerHTML='<label for="tt-remote-code">Accedi sulla TV con il codice a 6 cifre</label><div class="tt-remote-pair-row"><input id="tt-remote-code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="000000"><button type="button">Accedi</button></div>';modal.querySelector('.tt-remote-panel').appendChild(pairing);
    const remoteAuth=document.createElement('div');remoteAuth.className='tt-remote-auth';remoteAuth.innerHTML='<strong>Autorizza questo telecomando</strong><small>Mostra un codice di 4 cifre sulla TV selezionata.</small><div class="tt-remote-auth-row"><input inputmode="numeric" maxlength="4" placeholder="0000" aria-label="Codice telecomando"><button type="button">Mostra codice</button></div>';
    const smartGuide=document.createElement('section');smartGuide.className='tt-smart-guide';smartGuide.hidden=true;
    modal.querySelector('.tt-remote-device').after(remoteAuth);modal.querySelector('.tt-remote-status').before(smartGuide);
    const remoteTabs=document.createElement('nav');remoteTabs.className='tt-remote-tabs';remoteTabs.innerHTML='<button class="tt-remote-tab active" data-remote-section="remote" type="button"><svg viewBox="0 0 24 24" fill="none" stroke-width="2"><rect x="7" y="2" width="10" height="20" rx="3"/><path d="M10 9h4M12 7v4"/></svg>Comandi</button>';
    const workspace=document.createElement('div');workspace.className='tt-remote-workspace';workspace.innerHTML='<section class="tt-remote-view tt-remote-command-view active" data-remote-view="remote"></section>';
    smartGuide.before(remoteTabs,workspace);const commandView=workspace.querySelector('[data-remote-view="remote"]');commandView.append(modal.querySelector('.tt-remote-shortcuts'),modal.querySelector('.tt-remote-pad'),modal.querySelector('.tt-remote-media'),modal.querySelector('.tt-remote-status'));
    document.body.append(button, modal); dom.remoteButton = button; dom.remoteModal = modal;
    button.addEventListener('click', async () => { modal.classList.add('open'); await refreshRemoteDevices(); prepareRemotePanel(); });
    modal.querySelector('.tt-remote-close').addEventListener('click', () => modal.classList.remove('open'));
    modal.addEventListener('click', event => { if (event.target === modal) modal.classList.remove('open'); });
    modal.querySelectorAll('[data-command]').forEach(key => key.addEventListener('click', () => sendRemoteCommand(key.dataset.command)));
    modal.querySelector('.tt-remote-device').addEventListener('change',()=>{state.remotePairId='';prepareRemotePanel()});
    const authInput=remoteAuth.querySelector('input'),authButton=remoteAuth.querySelector('button');authInput.addEventListener('input',e=>e.target.value=e.target.value.replace(/\D/g,'').slice(0,4));authButton.addEventListener('click',()=>authButton.dataset.verify==='1'?verifyRemoteCode():startRemotePair());
    pairing.querySelector('input').addEventListener('input',event=>{event.target.value=event.target.value.replace(/\D/g,'').slice(0,6)});pairing.querySelector('button').addEventListener('click',()=>{const code=pairing.querySelector('input').value;if(!/^\d{6}$/.test(code)){pairing.querySelector('input').focus();return}location.href=SITE_BASE+'tv-connect.html?code='+encodeURIComponent(code)});
  }

  function remoteDeviceId(){ return dom.remoteModal?.querySelector('.tt-remote-device')?.value || ''; }
  function remoteTokenKey(id){ return 'tubetv_remote_token_' + String(id || ''); }
  function remoteToken(id=remoteDeviceId()){ try{return localStorage.getItem(remoteTokenKey(id))||''}catch(e){return''} }
  function setRemoteToken(id,token){ try{if(token)localStorage.setItem(remoteTokenKey(id),token);else localStorage.removeItem(remoteTokenKey(id))}catch(e){} }
  function prepareRemotePanel(){const id=remoteDeviceId(),auth=dom.remoteModal?.querySelector('.tt-remote-auth'),tabs=dom.remoteModal?.querySelector('.tt-remote-tabs'),workspace=dom.remoteModal?.querySelector('.tt-remote-workspace');if(auth)auth.hidden=true;if(tabs)tabs.hidden=!id;if(workspace)workspace.hidden=!id;dom.remoteModal?.querySelectorAll('[data-command]').forEach(key=>key.disabled=!id);if(id)showRemoteSection('remote')}
  async function startRemotePair(){const id=remoteDeviceId(),status=dom.remoteModal?.querySelector('.tt-remote-status'),button=dom.remoteModal?.querySelector('.tt-remote-auth button');if(!id||state.remotePairBusy)return;state.remotePairBusy=true;if(status)status.textContent='Mostro il codice sulla TV…';try{const r=await fetch(SITE_BASE+'api/tv-devices.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'create_remote_pair',deviceId:id})}),d=await r.json();if(!r.ok||!d.ok)throw new Error(d.error||'PAIR_ERROR');state.remotePairId=d.pairId;button.textContent='Conferma';button.dataset.verify='1';dom.remoteModal.querySelector('.tt-remote-auth input').focus();if(status)status.textContent='Inserisci le 4 cifre mostrate sulla TV.';}catch(e){if(status)status.textContent='Impossibile mostrare il codice.'}finally{state.remotePairBusy=false}}
  async function verifyRemoteCode(){const id=remoteDeviceId(),input=dom.remoteModal?.querySelector('.tt-remote-auth input'),code=String(input?.value||'');if(!/^\d{4}$/.test(code)){input?.focus();return}const status=dom.remoteModal?.querySelector('.tt-remote-status');try{const r=await fetch(SITE_BASE+'api/tv-devices.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'verify_remote_pair',deviceId:id,pairId:state.remotePairId,code})}),d=await r.json();if(!r.ok||!d.ok)throw new Error(d.error||'CODE_ERROR');setRemoteToken(id,d.remoteToken);state.remotePairId='';input.value='';if(status)status.textContent='Telecomando collegato.';prepareRemotePanel();}catch(e){if(status)status.textContent='Codice errato o scaduto.'}}
  function renderRemoteUi() {
    ensureRemoteUi(); if (!dom.remoteButton) return;
    const visible = !!state.user && matchMedia('(max-width: 900px), (pointer: coarse)').matches;
    dom.remoteButton.classList.toggle('visible', visible);
    if (!visible) dom.remoteModal?.classList.remove('open');
    const select = dom.remoteModal?.querySelector('.tt-remote-device'); if (!select) return;
    const hasDevices=state.tvDevices.length>0,empty=dom.remoteModal.querySelector('.tt-remote-empty');select.hidden=!hasDevices;if(empty)empty.hidden=hasDevices;dom.remoteModal.querySelectorAll('[data-command]').forEach(key=>key.disabled=!hasDevices);
    const selected = select.value; select.innerHTML = state.tvDevices.map(device => `<option value="${escapeHtml(device.id)}">${escapeHtml(device.name)}${device.online ? ' · online' : ' · offline'}</option>`).join('');
    if (state.tvDevices.some(device => device.id === selected)) select.value = selected;
    if(dom.remoteModal?.classList.contains('open'))prepareRemotePanel();
  }

  async function refreshRemoteDevices() {
    if (!state.user) { state.tvDevices = []; renderRemoteUi(); return []; }
    try { const response = await fetch(SITE_BASE + 'api/tv-devices.php', { credentials:'same-origin', cache:'no-store' }); const data = await response.json(); state.tvDevices = response.ok && data.ok ? (data.devices || []) : []; }
    catch (error) { state.tvDevices = []; }
    renderRemoteUi(); return state.tvDevices;
  }

  async function sendRemoteCommand(command,payload={}) {
    const select = dom.remoteModal?.querySelector('.tt-remote-device'), status = dom.remoteModal?.querySelector('.tt-remote-status'); if (!select?.value) return;
    if (status) status.textContent = 'Invio…';
    try { const response = await fetch(SITE_BASE + 'api/tv-devices.php', { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'}, body:JSON.stringify({deviceId:select.value,command,payload}) }); const data = await response.json().catch(()=>({})); if (!response.ok || data.ok === false) throw new Error(data.error || 'Invio fallito'); if (status) { status.textContent = 'Comando inviato alla TV'; status.dataset.state='ok'; } }
    catch (error) { if (status) status.textContent = 'TV non raggiungibile'; }
  }

  async function fetchCurrentUser() {
    try {
      const data = await apiFetch('me.php', { method: 'GET', headers: {} });
      state.user = data?.user || null;
    } catch (error) {
      state.user = null;
    }
    state.loading = false;
    renderAll();
    await refreshRemoteDevices();
    window.dispatchEvent(new CustomEvent('tubetv:auth-changed', { detail: { user: state.user } }));
  }

  async function submitLogin(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const payload = Object.fromEntries(new FormData(form).entries());
    showBanner('Accesso in corso...', 'info');
    try {
      const data = await apiFetch('login.php', {
        method: 'POST',
        body: JSON.stringify(payload)
      });
      state.user = data.user || null;
      state.message = 'Accesso completato.';
      showBanner('Accesso completato.', 'success');
      closeModal();
      renderAll();
      await refreshRemoteDevices();
      window.dispatchEvent(new CustomEvent('tubetv:auth-changed', { detail: { user: state.user } }));
    } catch (error) {
      showBanner(error.message || 'Errore di accesso.', 'error');
    }
  }

  async function submitRegister(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const formData = new FormData(form);
    const payload = Object.fromEntries(formData.entries());
    payload.consent = formData.get('consent') ? '1' : '';
    showBanner('Creazione account in corso...', 'info');
    try {
      const data = await apiFetch('register.php', {
        method: 'POST',
        body: JSON.stringify(payload)
      });
      state.user = data.user || null;
      state.message = 'Account creato correttamente.';
      showBanner('Account creato correttamente.', 'success');
      closeModal();
      renderAll();
      await refreshRemoteDevices();
      window.dispatchEvent(new CustomEvent('tubetv:auth-changed', { detail: { user: state.user } }));
    } catch (error) {
      showBanner(error.message || 'Errore durante la registrazione.', 'error');
    }
  }

  async function submitProfile(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const formData = new FormData(form);
    const payload = {
      name: formData.get('name'),
      email: formData.get('email'),
      language: formData.get('language'),
      subtitlesDefault: formData.get('subtitlesDefault') === 'on',
      autoplay: formData.get('autoplay') === 'on',
      theme: formData.get('theme')
    };
    showBanner('Salvataggio profilo...', 'info');
    try {
      const data = await apiFetch('update-profile.php', {
        method: 'POST',
        body: JSON.stringify(payload)
      });
      state.user = data.user || state.user;
      showBanner('Profilo aggiornato.', 'success');
      renderAll();
    } catch (error) {
      showBanner(error.message || 'Errore durante il salvataggio.', 'error');
    }
  }

  async function logout() {
    try {
      await apiFetch('logout.php', { method: 'POST', body: '{}' });
    } catch (error) {
      // Logout should still clear local UI even if the request fails.
    }
    state.user = null;
    state.tvDevices = [];
    state.message = "Sei uscito dall'account.";
    closeMenus();
    showBanner("Sei uscito dall'account.", 'success');
    renderAll();
    renderRemoteUi();
    window.dispatchEvent(new CustomEvent('tubetv:auth-changed', { detail: { user: null } }));
    if (isProfilePage()) {
      renderProfileShell();
    }
  }

  function registerGlobalHandlers() {
    document.addEventListener('click', event => {
      const toggle = event.target.closest?.('[data-account-toggle]');
      const menu = event.target.closest?.('[data-account-menu]');
      if (!toggle && !menu) {
        closeMenus();
      }
      if (state.modalOpen && event.target === dom.modal) {
        closeModal();
      }
    });

    document.addEventListener('keydown', event => {
      if (event.key === 'Escape') {
        closeMenus();
        closeModal();
      }
    });

    window.addEventListener('beforeinstallprompt', event => {
      event.preventDefault();
      state.installPrompt = event;
      updateInstallButton();
    });
  }

  function registerServiceWorker() {
    if (!('serviceWorker' in navigator)) return;
    const swUrl = SITE_BASE + 'service-worker.js';
    try {
      navigator.serviceWorker.register(swUrl, { updateViaCache: 'none' })
        .then(registration => registration.update())
        .catch(() => null);
    } catch (error) {
      // Ignore unsupported or file:// contexts.
    }
  }

  function renderAll() {
    renderNav();
    if (isProfilePage()) {
      renderProfileShell();
    }
    if (dom.modal) {
      setModalTab(state.modalTab);
    }
  }

  function mount() {
    ensureStyles();
    ensureRemoteUi();
    buildAuthModal();
    registerGlobalHandlers();
    registerServiceWorker();
    renderAll();
    fetchCurrentUser();
    window.TubeTVAccount = {
      refresh: fetchCurrentUser,
      openModal,
      closeModal,
      logout,
      isAdFree,
      refreshRemoteDevices,
      getUser: () => state.user,
      getState: () => ({ ...state }),
      render: renderAll
    };
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mount, { once: true });
  } else {
    mount();
  }
})();
