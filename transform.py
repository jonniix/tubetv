"""
TubeTV architecture transformation:
  index.html  → public site (fetch-based data, no admin)
  admin.html  → standalone admin console (localStorage, JSON export)
  data/       → sample JSON files
"""
import re, os, json

SRC  = r'c:\Users\jonat\Desktop\TVTUBE\index.html'
ORIG = r'c:\Users\jonat\Desktop\TVTUBE\index.html.bak'
DST_ADM = r'c:\Users\jonat\Desktop\TVTUBE\admin.html'
DATA_DIR = r'c:\Users\jonat\Desktop\TVTUBE\data'

# ── Backup original
with open(SRC, 'r', encoding='utf-8') as f:
    original = f.read()
with open(ORIG, 'w', encoding='utf-8') as f:
    f.write(original)
print('Backup written to index.html.bak')

lines = original.splitlines(keepends=True)
total = len(lines)
print(f'Total lines: {total}')

# ─────────────────────────────────────────────────────────────────────────────
# HELPER: find first line index (0-based) where text appears
# ─────────────────────────────────────────────────────────────────────────────
def find_line(text, start=0):
    for i in range(start, len(lines)):
        if text in lines[i]:
            return i
    return -1

# ─────────────────────────────────────────────────────────────────────────────
# SECTION BOUNDARIES (0-based line index)
# ─────────────────────────────────────────────────────────────────────────────
L_ADMIN_VIEW_START = find_line('═══ ADMIN VIEW ═══')       # html
L_PLAYER_MODAL     = find_line('<!-- PLAYER MODAL -->')     # html
L_SCRIPT           = find_line('<script>')                  # html/script boundary

L_DATA_STORE       = find_line('// DATA STORE')
L_NAVIGATION       = find_line('// NAVIGATION')
L_HOME_RENDER      = find_line('// HOME RENDER')
L_LIVE             = find_line('// LIVE')
L_CATALOG          = find_line('// CATALOG')
L_PLAYER_MODAL_JS  = find_line('// PLAYER MODAL')
L_ADMIN_RENDERS    = find_line('// ADMIN RENDERS')
L_SERIES_PUBLIC    = find_line('// SERIES PUBLIC VIEW')
L_INIT             = find_line('// INIT')

print(f'Admin view HTML start: {L_ADMIN_VIEW_START+1}')
print(f'Player modal HTML:     {L_PLAYER_MODAL+1}')
print(f'Script tag:            {L_SCRIPT+1}')
print(f'DATA STORE:            {L_DATA_STORE+1}')
print(f'NAVIGATION:            {L_NAVIGATION+1}')
print(f'HOME RENDER:           {L_HOME_RENDER+1}')
print(f'LIVE:                  {L_LIVE+1}')
print(f'CATALOG:               {L_CATALOG+1}')
print(f'PLAYER MODAL JS:       {L_PLAYER_MODAL_JS+1}')
print(f'ADMIN RENDERS:         {L_ADMIN_RENDERS+1}')
print(f'SERIES PUBLIC VIEW:    {L_SERIES_PUBLIC+1}')
print(f'INIT:                  {L_INIT+1}')

# ─────────────────────────────────────────────────────────────────────────────
# EXTRACT BLOCKS
# ─────────────────────────────────────────────────────────────────────────────

# CSS block (lines 1 to </style> tag, inclusive — from raw HTML)
css_start = find_line('<style>')
css_end   = find_line('</style>')
css_block = ''.join(lines[css_start+1 : css_end])  # content between <style> and </style>

# HTML nav block
nav_start = find_line('<nav>')
nav_end   = find_line('</nav>')
nav_html  = ''.join(lines[nav_start : nav_end+1])

# Public views HTML (between </nav> and admin view start)
public_views_html = ''.join(lines[nav_end+1 : L_ADMIN_VIEW_START])

# Player modal HTML
player_modal_html = ''.join(lines[L_PLAYER_MODAL : L_SCRIPT])

# Admin view HTML (raw, with outer wrapper div)
admin_view_html_raw = ''.join(lines[L_ADMIN_VIEW_START : L_PLAYER_MODAL])

# JS section blocks
js_data_store    = ''.join(lines[L_DATA_STORE    : L_NAVIGATION])
js_navigation    = ''.join(lines[L_NAVIGATION    : L_HOME_RENDER])
js_home_render   = ''.join(lines[L_HOME_RENDER   : L_LIVE])
js_live          = ''.join(lines[L_LIVE          : L_CATALOG])
js_catalog       = ''.join(lines[L_CATALOG       : L_PLAYER_MODAL_JS])
js_player_modal  = ''.join(lines[L_PLAYER_MODAL_JS : L_ADMIN_RENDERS])
js_admin_renders = ''.join(lines[L_ADMIN_RENDERS : L_SERIES_PUBLIC])
js_series_public = ''.join(lines[L_SERIES_PUBLIC : L_INIT])
js_init_orig     = ''.join(lines[L_INIT :]).rstrip()
# strip trailing </script></body></html>
js_init = re.sub(r'\s*</script>.*$', '', js_init_orig, flags=re.DOTALL)

print('\nAll sections extracted OK')

# ─────────────────────────────────────────────────────────────────────────────
# BUILD: JS DATA STORE (public version — async fetch, localStorage fallback)
# ─────────────────────────────────────────────────────────────────────────────
JS_DATA_STORE_PUBLIC = """\
// ══════════════════════════════════════════════
// DATA STORE  (fetch-based, localStorage fallback)
// ══════════════════════════════════════════════
function load(k,def){try{return JSON.parse(localStorage.getItem('tutv_'+k))||def;}catch{return def;}}
function save(k,v){localStorage.setItem('tutv_'+k,JSON.stringify(v));}

const DEFAULT_CATEGORIES=['Generale','Cucina','Musica','Sport','News','Documentario','Tecnologia','Scienza','Arte','Intrattenimento','Podcast','Viaggi','Gaming','Cinema'];

let DB={videos:[],slots:[],spots:[],channels:[],palinsesto:[],settings:{name:'TubeTV',color:'#e63946'},categories:DEFAULT_CATEGORIES,manualEvents:[],videoAirHistory:{},series:[],seriesEpisodes:{}};

async function fetchJSON(path,fallbackKey,fallback=null){
  try{
    const r=await fetch(path+'?v='+Date.now());
    if(!r.ok)throw new Error('HTTP '+r.status);
    const d=await r.json();
    localStorage.setItem('tutv_'+fallbackKey,JSON.stringify(d));
    return d;
  }catch(e){
    console.warn('[TubeTV] fetch failed:',path,e.message);
    const c=localStorage.getItem('tutv_'+fallbackKey);
    return c?JSON.parse(c):(fallback!==null?fallback:null);
  }
}

async function loadAllData(){
  const [videos,channels,slots,palinsesto,settings,series,seriesEpisodes,manualEvents]=await Promise.all([
    fetchJSON('data/videos.json','videos',[]),
    fetchJSON('data/channels.json','channels',[]),
    fetchJSON('data/slots.json','slots',[]),
    fetchJSON('data/schedule.json','palinsesto',[]),
    fetchJSON('data/config.json','settings',{name:'TubeTV',color:'#e63946'}),
    fetchJSON('data/series.json','series',[]),
    fetchJSON('data/series-episodes.json','series_episodes',{}),
    fetchJSON('data/manual-events.json','manual_events',[])
  ]);
  DB.videos        = videos        ?? [];
  DB.channels      = channels      ?? [];
  DB.slots         = slots         ?? [];
  DB.palinsesto    = palinsesto    ?? [];
  DB.settings      = settings      ?? {name:'TubeTV',color:'#e63946'};
  DB.series        = series        ?? [];
  DB.seriesEpisodes= seriesEpisodes?? {};
  DB.manualEvents  = manualEvents  ?? [];
  DB.categories    = load('categories',DEFAULT_CATEGORIES);
  DB.videoAirHistory = load('video_air_history',{});
  if(DB.settings.color) document.documentElement.style.setProperty('--accent',DB.settings.color);
  if(DB.settings.name){
    document.querySelectorAll('.site-name').forEach(el=>el.textContent=DB.settings.name);
  }
}

function startPeriodicRefresh(){
  // Re-fetch schedule every 60s
  setInterval(async()=>{
    DB.palinsesto=await fetchJSON('data/schedule.json','palinsesto',DB.palinsesto);
    DB.manualEvents=await fetchJSON('data/manual-events.json','manual_events',DB.manualEvents);
    const lv=document.getElementById('view-live');
    if(lv&&lv.classList.contains('active'))renderLive();
    renderScheduleBar('schedule-bar-home');
  },60000);
  // Re-fetch config every 5 min
  setInterval(async()=>{
    const s=await fetchJSON('data/config.json','settings',DB.settings);
    if(s){DB.settings=s;if(s.color)document.documentElement.style.setProperty('--accent',s.color);}
  },300000);
  // Re-fetch catalog every 10 min
  setInterval(async()=>{
    DB.videos       = await fetchJSON('data/videos.json','videos',DB.videos);
    DB.series       = await fetchJSON('data/series.json','series',DB.series);
    DB.seriesEpisodes=await fetchJSON('data/series-episodes.json','series_episodes',DB.seriesEpisodes);
  },600000);
}

// ── Helpers YouTube
function ytId(url){
  if(!url)return null;
  const m = url.match(/(?:youtu\\.be\\/|v=|\\/embed\\/|\\/shorts\\/)([A-Za-z0-9_-]{11})/);
  return m?m[1]:null;
}
function ytThumb(id){return `https://img.youtube.com/vi/${id}/hqdefault.jpg`;}
function ytEmbed(id,autoplay=0,mute=0,controls=1,start=0){return `https://www.youtube.com/embed/${id}?autoplay=${autoplay}&mute=${mute}&controls=${controls}&rel=0&modestbranding=1${start>0?'&start='+start:''}`;}
function ytEmbedPreview(id){return ytEmbed(id,1,1,0);}
function extractHandle(url){
  const m = url.match(/@([^/?&]+)/);
  return m?m[1]:url;
}
function now(){return new Date();}
function timeStr(d){return d.toTimeString().slice(0,5);}
function uid(){return Date.now().toString(36)+Math.random().toString(36).slice(2,6);}

// ── SVG icon constants
const IC = {
  trash: `<svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>`,
  edit:  `<svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>`,
  save:  `<svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>`,
  signal:`<svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1.42 9a16 16 0 0 1 21.16 0"/><path d="M5 12.55a11 11 0 0 1 14.08 0"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/><circle cx="12" cy="20" r="1" fill="currentColor"/></svg>`,
  plus:  `<svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>`,
  ad:    `<svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07"/></svg>`
};
"""

# ─────────────────────────────────────────────────────────────────────────────
# BUILD: NAVIGATION (public — no admin case)
# ─────────────────────────────────────────────────────────────────────────────
JS_NAVIGATION_PUBLIC = """\
// ══════════════════════════════════════════════
// NAVIGATION
// ══════════════════════════════════════════════
function switchView(v){
  document.querySelectorAll('.view').forEach(el=>el.classList.remove('active'));
  document.querySelectorAll('.nav-links button').forEach(b=>b.classList.remove('active'));
  const target=document.getElementById('view-'+v);
  if(!target)return;
  target.classList.add('active');
  const nb=document.getElementById('nav-'+v);
  if(nb)nb.classList.add('active');
  if(v==='home')renderHome();
  if(v==='live')renderLive();
  if(v==='catalog')renderCatalog();
  if(v==='series')renderSeries();
}
"""

# ─────────────────────────────────────────────────────────────────────────────
# BUILD: LIVE with offset
# ─────────────────────────────────────────────────────────────────────────────
# We need to patch the renderLive function.
# Replace the ytEmbed line that builds the live iframe src.
# Current: document.getElementById('live-iframe').src=ytEmbed(id,1,0,1);
# New: compute offset from startDateTime, use ytEmbed with start param

def patch_live_render(js_live_block):
    # Replace getCurrentPalEntry to use startDateTime when available
    old_get = """\
function getCurrentPalEntry(){
  const t=timeStr(now());
  for(let i=DB.palinsesto.length-1;i>=0;i--){
    if(DB.palinsesto[i].time<=t)return DB.palinsesto[i];
  }
  return DB.palinsesto[0]||null;
}"""
    new_get = """\
function getCurrentPalEntry(){
  // Try startDateTime-based lookup first (precise)
  const nowMs=Date.now();
  const byDT=DB.palinsesto.find(e=>{
    if(!e.startDateTime)return false;
    const start=new Date(e.startDateTime).getTime();
    const durMs=((e.durationSeconds||(e.duration*60)||1800))*1000;
    return nowMs>=start && nowMs<start+durMs;
  });
  if(byDT)return byDT;
  // Fallback: time-string comparison (HH:MM)
  const t=timeStr(now());
  for(let i=DB.palinsesto.length-1;i>=0;i--){
    if(DB.palinsesto[i].time<=t)return DB.palinsesto[i];
  }
  return DB.palinsesto[0]||null;
}
function getLiveOffset(entry){
  if(!entry||!entry.startDateTime)return 0;
  return Math.max(0,Math.floor((Date.now()-new Date(entry.startDateTime).getTime())/1000));
}"""
    # Replace the iframe src line to include offset
    old_src = "document.getElementById('live-iframe').src=ytEmbed(id,1,0,1);"
    new_src = "document.getElementById('live-iframe').src=ytEmbed(id,1,0,1,getLiveOffset(current));"

    result = js_live_block.replace(old_get, new_get)
    result = result.replace(old_src, new_src)
    return result

js_live_patched = patch_live_render(js_live)

# ─────────────────────────────────────────────────────────────────────────────
# BUILD: INIT (public version)
# ─────────────────────────────────────────────────────────────────────────────
JS_INIT_PUBLIC = """\
// ══════════════════════════════════════════════
// INIT
// ══════════════════════════════════════════════
async function init(){
  await loadAllData();
  if(DB.settings.color) document.documentElement.style.setProperty('--accent',DB.settings.color);
  populateCategorySelects();
  renderHome();
  startPeriodicRefresh();
  // Also refresh live view every 60s (timer)
  setInterval(()=>{
    const lv=document.getElementById('view-live');
    if(lv&&lv.classList.contains('active'))renderLive();
    renderScheduleBar('schedule-bar-home');
  },60000);
}

document.addEventListener('DOMContentLoaded',init);
"""

# ─────────────────────────────────────────────────────────────────────────────
# BUILD: NAV for public index (remove admin button)
# ─────────────────────────────────────────────────────────────────────────────
# Remove the admin button line from nav_html
nav_public = re.sub(r'\n\s*<button[^>]+onclick="switchView\(\'admin\'\)"[^>]*>.*?</button>', '', nav_html, flags=re.DOTALL)

# ─────────────────────────────────────────────────────────────────────────────
# BUILD: populateCategorySelects for public (only catalog + series)
# ─────────────────────────────────────────────────────────────────────────────
# Find the existing function in js_home_render or wherever it is
# Search in all blocks
all_js = js_data_store + js_navigation + js_home_render + js_live + js_catalog + js_player_modal + js_admin_renders + js_series_public + js_init_orig

pop_cat_match = re.search(r'function populateCategorySelects\(\)\{.*?\n\}', all_js, re.DOTALL)
js_pop_cat = pop_cat_match.group(0) if pop_cat_match else ''

# Check where it is in the home_render section
# The function is needed in public; it might be in js_home_render already
if 'populateCategorySelects' in js_home_render:
    print('populateCategorySelects found in HOME RENDER section')
elif 'populateCategorySelects' in js_admin_renders:
    print('populateCategorySelects found in ADMIN RENDERS section — need to include it')

# ─────────────────────────────────────────────────────────────────────────────
# ASSEMBLE: PUBLIC index.html
# ─────────────────────────────────────────────────────────────────────────────
# Build the public views HTML (remove any stray whitespace / empty lines)
public_views_clean = public_views_html

# Head section
HEAD = ''.join(lines[0 : css_start])  # doctype + html + head up to <style>
TAIL_HTML = ''.join(lines[css_end+1 : nav_start])  # </head>\n<body>\n

index_public = f"""{HEAD}<style>
{css_block}</style>
{TAIL_HTML}{nav_public}
{public_views_clean}
{player_modal_html}
<script>
{JS_DATA_STORE_PUBLIC}
{JS_NAVIGATION_PUBLIC}
{js_home_render}
{js_live_patched}
{js_catalog}
{js_player_modal}
{js_series_public}
{JS_INIT_PUBLIC}
</script>
</body>
</html>
"""

with open(SRC, 'w', encoding='utf-8') as f:
    f.write(index_public)
print(f'Written public index.html ({len(index_public.splitlines())} lines)')

# ─────────────────────────────────────────────────────────────────────────────
# BUILD: ADMIN DATA STORE (localStorage only — same as original)
# ─────────────────────────────────────────────────────────────────────────────
# We keep the original data store (with load/save/saveAll) for admin.html
# Extract the original data store section completely up to NAVIGATION
js_data_store_admin = ''.join(lines[L_DATA_STORE : L_NAVIGATION])

# ─────────────────────────────────────────────────────────────────────────────
# BUILD: ADMIN INIT
# ─────────────────────────────────────────────────────────────────────────────
JS_INIT_ADMIN = """\
// ══════════════════════════════════════════════
// ADMIN INIT
// ══════════════════════════════════════════════
function switchAdmin(p,btn){
  document.querySelectorAll('.admin-panel').forEach(el=>el.classList.remove('active'));
  document.querySelectorAll('.sidebar-btn').forEach(b=>b.classList.remove('active'));
  document.getElementById('panel-'+p).classList.add('active');
  if(btn)btn.classList.add('active');
  if(p==='dashboard')renderDashboard();
  if(p==='slots')renderSlots();
  if(p==='videos')renderVideosTable();
  if(p==='spots')renderSpotsTable();
  if(p==='channels'){renderChannelsTable();renderChannelSlotCheckboxes([]);}
  if(p==='manual'){renderManualEvents();}
  if(p==='series'){renderSeriesTable();}
  if(p==='library'){renderLibraryStats();}
  if(p==='generate'){document.getElementById('gen-date').value=new Date().toISOString().slice(0,10);}
  if(p==='publish'){renderPublishInfo();}
}

function switchTab(tid,btn){
  document.querySelectorAll('.tab-panel').forEach(el=>el.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
  document.getElementById(tid).classList.add('active');
  if(btn)btn.classList.add('active');
  renderVideosTable();
}

// ── Helper YouTube (needed in admin too)
function ytId(url){
  if(!url)return null;
  const m = url.match(/(?:youtu\\.be\\/|v=|\\/embed\\/|\\/shorts\\/)([A-Za-z0-9_-]{11})/);
  return m?m[1]:null;
}
function ytThumb(id){return `https://img.youtube.com/vi/${id}/hqdefault.jpg`;}
function ytEmbed(id,autoplay=0,mute=0,controls=1,start=0){return `https://www.youtube.com/embed/${id}?autoplay=${autoplay}&mute=${mute}&controls=${controls}&rel=0&modestbranding=1${start>0?'&start='+start:''}`;}
function ytEmbedPreview(id){return ytEmbed(id,1,1,0);}
function extractHandle(url){const m=url.match(/@([^/?&]+)/);return m?m[1]:url;}
function now(){return new Date();}
function timeStr(d){return d.toTimeString().slice(0,5);}
function uid(){return Date.now().toString(36)+Math.random().toString(36).slice(2,6);}
function esc(s){return (s||'').replace(/'/g,"&#39;").replace(/"/g,'&quot;');}

const IC = {
  trash: `<svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>`,
  edit:  `<svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>`,
  save:  `<svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>`,
  signal:`<svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1.42 9a16 16 0 0 1 21.16 0"/><path d="M5 12.55a11 11 0 0 1 14.08 0"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/><circle cx="12" cy="20" r="1" fill="currentColor"/></svg>`,
  plus:  `<svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>`,
  ad:    `<svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07"/></svg>`
};

let _editSlotId=null,_editSpotId=null,_editVideoId=null,_editChannelId=null;

// ── JSON publish/export
function downloadJSON(filename,data){
  const blob=new Blob([JSON.stringify(data,null,2)],{type:'application/json'});
  const a=document.createElement('a');
  a.href=URL.createObjectURL(blob);
  a.download=filename;
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(a.href);
}

function publishData(){
  // Strip API keys from channels before exporting
  const safeChannels=DB.channels.map(ch=>{const {apiKey,...rest}=ch;return rest;});
  const files={
    'config.json':      {name:DB.settings.name,color:DB.settings.color},
    'videos.json':      DB.videos,
    'channels.json':    safeChannels,
    'slots.json':       DB.slots,
    'schedule.json':    DB.palinsesto,
    'series.json':      DB.series,
    'series-episodes.json': DB.seriesEpisodes,
    'manual-events.json':   DB.manualEvents
  };
  let i=0;
  Object.entries(files).forEach(([fn,data])=>{
    setTimeout(()=>downloadJSON(fn,data),200*i);
    i++;
  });
  const info=document.getElementById('publish-info');
  if(info)info.style.display='block';
}

function renderPublishInfo(){
  const el=document.getElementById('publish-files-list');
  if(!el)return;
  const counts={
    'config.json':      '1 oggetto (nome + colore)',
    'videos.json':      DB.videos.length+' video',
    'channels.json':    DB.channels.length+' canali (senza chiavi API)',
    'slots.json':       DB.slots.length+' fasce orarie',
    'schedule.json':    DB.palinsesto.length+' slot nel palinsesto',
    'series.json':      DB.series.length+' serie TV',
    'series-episodes.json': Object.keys(DB.seriesEpisodes).length+' playlist episodi',
    'manual-events.json':   DB.manualEvents.length+' eventi manuali'
  };
  el.innerHTML=Object.entries(counts).map(([fn,c])=>`
    <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border);">
      <span style="font-family:monospace;font-size:.82rem;color:#a78bfa;">data/${fn}</span>
      <span style="font-size:.78rem;color:var(--muted);">${c}</span>
    </div>`).join('');
}

function init(){
  if(DB.slots.length===0){
    DB.slots=[
      {id:uid(),start:'08:00',end:'10:00',name:'Mattino',channels:[],priority:2},
      {id:uid(),start:'10:00',end:'12:00',name:'Prima pranzo',channels:[],priority:2},
      {id:uid(),start:'12:00',end:'14:00',name:'Pranzo',channels:[],priority:2},
      {id:uid(),start:'14:00',end:'16:00',name:'Pomeriggio',channels:[],priority:2},
      {id:uid(),start:'16:00',end:'18:00',name:'Pre serata',channels:[],priority:2},
      {id:uid(),start:'18:00',end:'20:00',name:'Prima serata',channels:[],priority:1},
      {id:uid(),start:'20:00',end:'22:00',name:'Seconda serata',channels:[],priority:1},
      {id:uid(),start:'22:00',end:'00:00',name:'Terza serata',channels:[],priority:2},
      {id:uid(),start:'00:00',end:'02:00',name:'Quarta serata',channels:[],priority:3},
      {id:uid(),start:'02:00',end:'04:00',name:'Notte',channels:[],priority:3},
      {id:uid(),start:'04:00',end:'06:00',name:'Mattino presto',channels:[],priority:3},
      {id:uid(),start:'06:00',end:'08:00',name:'Risveglio',channels:[],priority:2}
    ];
    saveAll();
  }
  document.getElementById('gen-date').value=new Date().toISOString().slice(0,10);
  if(DB.apiKey){document.getElementById('api-key-input').value=DB.apiKey;document.getElementById('api-key-status').style.display='inline';}
  if(DB.settings.color) document.documentElement.style.setProperty('--accent',DB.settings.color);
  const vidUrlInput=document.getElementById('vid-url');
  if(vidUrlInput) vidUrlInput.addEventListener('change',()=>onVidUrlChange(vidUrlInput.value));
  const spotUrlInput=document.getElementById('spot-url');
  if(spotUrlInput) spotUrlInput.addEventListener('change',()=>onSpotUrlChange(spotUrlInput.value));
  const chUrlInput=document.getElementById('ch-url');
  if(chUrlInput) chUrlInput.addEventListener('change',()=>onChannelUrlChange(chUrlInput.value));
  const serUrlInput=document.getElementById('ser-url');
  if(serUrlInput) serUrlInput.addEventListener('change',()=>onSeriesUrlChange(serUrlInput.value));
  const meDateInput=document.getElementById('me-date');
  if(meDateInput) meDateInput.value=new Date().toISOString().slice(0,10);
  if(DB.ytApiKey){const yks=document.getElementById('yt-api-key-input');if(yks)yks.value=DB.ytApiKey;const s=document.getElementById('yt-api-key-status');if(s)s.style.display='inline';}
  populateCategorySelects();
  updateSpotSlots();
  renderDashboard();
}
document.addEventListener('DOMContentLoaded',init);
"""

# ─────────────────────────────────────────────────────────────────────────────
# BUILD: ADMIN panel for "Pubblica" — HTML to inject into admin sidebar + panels
# ─────────────────────────────────────────────────────────────────────────────
# We add a "Pubblica" button in the sidebar and a "panel-publish" panel.
# The sidebar button needs to go before the closing </ul> or equivalent sidebar button group.

PUBLISH_SIDEBAR_BTN = """\
          <button class="sidebar-btn" onclick="switchAdmin('publish',this)"><svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg> Pubblica JSON</button>"""

PUBLISH_PANEL = """\
    <!-- ─── PUBLISH PANEL ─── -->
    <div class="admin-panel" id="panel-publish">
      <div class="panel-header"><h2>Pubblica dati sul sito</h2></div>
      <div class="form-card" style="max-width:640px;">
        <p style="font-size:.85rem;color:var(--muted);margin-bottom:1.25rem;line-height:1.6;">
          Clicca <strong>Scarica tutti i file JSON</strong> per esportare i dati.<br>
          Copia i file scaricati nella cartella <code style="background:var(--bg3);padding:1px 6px;border-radius:4px;font-size:.82rem;">data/</code> del tuo sito web.
          Le chiavi API <strong>non vengono incluse</strong> nei file esportati.
        </p>
        <div id="publish-files-list" style="margin-bottom:1.25rem;"></div>
        <div style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:center;">
          <button class="btn-add" onclick="publishData()">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
            Scarica tutti i file JSON
          </button>
        </div>
        <div id="publish-info" style="display:none;margin-top:1rem;padding:.75rem 1rem;background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.25);border-radius:8px;font-size:.82rem;color:#34d399;">
          File scaricati! Caricali nella cartella <code>data/</code> del server per aggiornare il sito pubblico.
        </div>
      </div>
    </div>"""

# ─────────────────────────────────────────────────────────────────────────────
# BUILD: ADMIN nav HTML
# ─────────────────────────────────────────────────────────────────────────────
ADMIN_NAV = """\
<nav>
  <div class="logo" onclick="location.href='index.html'" style="cursor:pointer;">TUBE<span>TV</span> <span style="font-size:.6rem;font-weight:400;background:rgba(230,57,70,.15);color:var(--accent);padding:2px 8px;border-radius:4px;letter-spacing:1px;margin-left:4px;vertical-align:middle;">ADMIN</span></div>
  <div class="nav-links">
    <a href="index.html" style="color:var(--muted);font-size:.82rem;text-decoration:none;padding:6px 12px;border:1px solid var(--border);border-radius:6px;transition:border-color .2s;" onmouseover="this.style.borderColor='var(--accent)'" onmouseout="this.style.borderColor='var(--border)'"><svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="vertical-align:middle;margin-right:4px;"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>Sito pubblico</a>
    <button class="btn-add" onclick="publishData()" style="font-size:.82rem;"><svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="vertical-align:middle;margin-right:4px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>Pubblica</button>
  </div>
</nav>"""

# ─────────────────────────────────────────────────────────────────────────────
# PATCH admin view HTML: add Pubblica button to sidebar + add publish panel
# ─────────────────────────────────────────────────────────────────────────────
# We need to:
# 1. Remove the outer <div class="view" id="view-admin"> wrapper
# 2. Add the Pubblica sidebar button
# 3. Add the publish panel

# Strip the outer view-admin wrapper
admin_inner_html = admin_view_html_raw
admin_inner_html = re.sub(r'^.*?<!-- ═══ ADMIN VIEW ═══ -->\s*<div class="view"[^>]*>\s*', '', admin_inner_html, flags=re.DOTALL)
# Remove the closing </div> (the view wrapper) — it's at the very end
admin_inner_html = re.sub(r'</div>\s*$', '', admin_inner_html.rstrip())

# Add publish sidebar button before the last </div> of sidebar
# Find the Sistema group or a known location
# The sidebar has groups: let's add after the "Sistema" group
sistema_section_end = admin_inner_html.rfind('</div>', 0, admin_inner_html.rfind('</div>'))
# More targeted: add before the </div><!-- /sidebar --> or just before a known last sidebar group closing

# Simpler: find the last sidebar-section and add our button after it
# We look for the pattern of the last sidebar group header (Sistema or Impostazioni button)
# Add publish button just before the </div><!-- /sidebar --> closing
# Let's find the sidebar div closing
sidebar_close_idx = admin_inner_html.find('</div><!-- /sidebar -->')
if sidebar_close_idx < 0:
    # Try finding the sidebar closing another way
    # Look for the admin-main div start
    admin_main_idx = admin_inner_html.find('<div class="admin-main"')
    if admin_main_idx > 0:
        # The sidebar ends just before admin-main
        sidebar_content = admin_inner_html[:admin_main_idx]
        last_div_close = sidebar_content.rfind('</div>')
        insert_pos = last_div_close
        admin_inner_html = (
            admin_inner_html[:insert_pos]
            + '\n' + PUBLISH_SIDEBAR_BTN + '\n'
            + admin_inner_html[insert_pos:]
        )
else:
    admin_inner_html = (
        admin_inner_html[:sidebar_close_idx]
        + '\n' + PUBLISH_SIDEBAR_BTN + '\n'
        + admin_inner_html[sidebar_close_idx:]
    )

# Add publish panel before the </div><!-- /admin-main --> closing
admin_main_close = admin_inner_html.rfind('</div><!-- /admin-main -->')
if admin_main_close < 0:
    # Fall back: find </div>\n  </div>\n</div> pattern at end and insert before 2nd-to-last
    # Just append before last </div>
    admin_main_close = admin_inner_html.rfind('</div>')
    admin_inner_html = (
        admin_inner_html[:admin_main_close]
        + '\n' + PUBLISH_PANEL + '\n'
        + admin_inner_html[admin_main_close:]
    )
else:
    admin_inner_html = (
        admin_inner_html[:admin_main_close]
        + '\n' + PUBLISH_PANEL + '\n'
        + admin_inner_html[admin_main_close:]
    )

# ─────────────────────────────────────────────────────────────────────────────
# ASSEMBLE: admin.html
# ─────────────────────────────────────────────────────────────────────────────
ADMIN_EXTRA_CSS = """\
/* Admin standalone */
body { background: var(--admin-bg, #0f0f17); }
"""

admin_html_content = f"""<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>TubeTV — Admin Console</title>
  <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:ital,wght@0,300;0,400;0,500;0,600;1,400&display=swap" rel="stylesheet">
  <style>
{css_block}
{ADMIN_EXTRA_CSS}
  </style>
</head>
<body>

{ADMIN_NAV}

<div class="admin-layout">
{admin_inner_html}
</div>

<!-- PLAYER MODAL (for video preview) -->
{player_modal_html}
<script>
{js_data_store_admin}
{js_admin_renders}
{JS_INIT_ADMIN}
</script>
</body>
</html>
"""

with open(DST_ADM, 'w', encoding='utf-8') as f:
    f.write(admin_html_content)
print(f'Written admin.html ({len(admin_html_content.splitlines())} lines)')

# ─────────────────────────────────────────────────────────────────────────────
# CREATE: data/ sample JSON files
# ─────────────────────────────────────────────────────────────────────────────
os.makedirs(DATA_DIR, exist_ok=True)

import datetime as dt

# Build sample schedule with startDateTime
now_dt = dt.datetime.now()
today = now_dt.strftime('%Y-%m-%d')
# Round to nearest hour
hour = now_dt.hour
tz_offset = '+02:00'  # Italian summer time

def iso_dt(hour_offset, minute=0):
    h = (hour + hour_offset) % 24
    return f"{today}T{h:02d}:{minute:02d}:00{tz_offset}"

sample_schedule = [
    {
        "id": "sched001",
        "videoId": "dQw4w9WgXcQ",
        "title": "Esempio Video Mattino",
        "channel": "Canale Demo",
        "url": "https://www.youtube.com/watch?v=dQw4w9WgXcQ",
        "thumbnail": "https://img.youtube.com/vi/dQw4w9WgXcQ/hqdefault.jpg",
        "time": f"{hour:02d}:00",
        "startDateTime": iso_dt(0, 0),
        "durationSeconds": 3600,
        "endDateTime": iso_dt(1, 0),
        "type": "content",
        "slot": "Mattino",
        "priority": 2
    },
    {
        "id": "sched002",
        "videoId": "ScMzIvxBSi4",
        "title": "Esempio Video Pranzo",
        "channel": "Canale Demo",
        "url": "https://www.youtube.com/watch?v=ScMzIvxBSi4",
        "thumbnail": "https://img.youtube.com/vi/ScMzIvxBSi4/hqdefault.jpg",
        "time": f"{(hour+1)%24:02d}:00",
        "startDateTime": iso_dt(1, 0),
        "durationSeconds": 3600,
        "endDateTime": iso_dt(2, 0),
        "type": "content",
        "slot": "Pranzo",
        "priority": 2
    }
]

sample_config = {"name": "TubeTV", "color": "#e63946"}
sample_videos = []
sample_channels = []
sample_slots = [
    {"id": "slot001", "start": "08:00", "end": "10:00", "name": "Mattino", "channels": [], "priority": 2},
    {"id": "slot002", "start": "12:00", "end": "14:00", "name": "Pranzo", "channels": [], "priority": 2},
    {"id": "slot003", "start": "18:00", "end": "20:00", "name": "Prima serata", "channels": [], "priority": 1},
    {"id": "slot004", "start": "20:00", "end": "22:00", "name": "Seconda serata", "channels": [], "priority": 1}
]
sample_series = []
sample_episodes = {}
sample_manual_events = []

json_files = {
    'config.json':              sample_config,
    'videos.json':              sample_videos,
    'channels.json':            sample_channels,
    'slots.json':               sample_slots,
    'schedule.json':            sample_schedule,
    'series.json':              sample_series,
    'series-episodes.json':     sample_episodes,
    'manual-events.json':       sample_manual_events
}

for filename, data in json_files.items():
    path = os.path.join(DATA_DIR, filename)
    with open(path, 'w', encoding='utf-8') as f:
        json.dump(data, f, ensure_ascii=False, indent=2)
    print(f'  Created {path}')

print('\nAll done!')
print(f'  index.html  — public site ({len(index_public.splitlines())} lines)')
print(f'  admin.html  — admin console ({len(admin_html_content.splitlines())} lines)')
print(f'  data/       — {len(json_files)} JSON files')
