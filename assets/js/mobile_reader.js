(function(){
  const $ = (sel, root=document) => root.querySelector(sel);
  const $$ = (sel, root=document) => Array.from(root.querySelectorAll(sel));

  const app = $('.reading-app');
  if (!app) return;

  // Elements
  const contentArea = $('#content-area');
  const pages = $('#pages');
  const pagesInner = $('#pagesInner');
  const pageBody = $('#pageBody');
  const progressText = $('#progress-percent');
  const chapterTitleEl = $('.nav-bar .chapter-title');
  const statusTime = $('#current-time');
  const statusBattery = $('#battery');

  const drawer = $('#drawer');
  const settingsPanel = $('#settings-panel');
  const themePanel = $('#theme-panel');
  const progressPanel = $('#progress-panel');
  const bookmarksPanel = $('#bookmarks-panel');

  const brightnessOverlay = $('#brightness-overlay');

  // Footer buttons
  const btnTOC = $('[data-action="toc"]');
  const btnFont = $('[data-action="font"]');
  const btnTheme = $('[data-action="theme"]');
  const btnProgress = $('[data-action="progress"]');
  const btnBookmark = $('[data-action="bookmark"]');

  // Dataset
  const dataset = app.dataset || {};
  const novelId = Number(dataset.novelId || '0');
  let chapterId = Number(dataset.chapterId || '0');
  const prevUrl = dataset.prevUrl || '';
  const nextUrl = dataset.nextUrl || '';
  let currentPage = Number(dataset.initialPage || '0');
  let totalPages = 1;

  // Settings
  function loadSettings(){ try{ return JSON.parse(localStorage.getItem('mreader:settings')||'{}'); }catch(e){ return {}; } }
  function saveSettings(s){ localStorage.setItem('mreader:settings', JSON.stringify(s)); }
  const settings = Object.assign({
    theme: 'day',
    fontSize: 18,
    fontFamily: 'system',
    lineHeight: 1.65,
    paraSpace: 0.9,
    brightness: 1,
    autoTheme: true,
    keepAwake: false,
    autoScrollSpeed: 0, // 0 = off, 1..5
    timerEnd: 0,
  }, loadSettings());

  applySettings(settings);

  // Time & battery
  function updateTime(){ const now = new Date(); const h = String(now.getHours()).padStart(2,'0'); const m = String(now.getMinutes()).padStart(2,'0'); if (statusTime) statusTime.textContent = `${h}:${m}`; }
  setInterval(updateTime, 15000); updateTime();
  async function setupBattery(){
    try{
      if ('getBattery' in navigator){
        const b = await navigator.getBattery();
        const render = ()=>{ if (statusBattery) statusBattery.textContent = Math.round(b.level*100) + '%'; };
        b.addEventListener('levelchange', render); b.addEventListener('chargingchange', render); render();
      }else{ if (statusBattery) statusBattery.textContent = '78%'; }
    }catch(e){ if (statusBattery) statusBattery.textContent = '78%'; }
  }
  setupBattery();

  // Auto theme switch by time
  function applyAutoTheme(){
    if (!settings.autoTheme) return;
    const h = (new Date()).getHours();
    const night = (h >= 21 || h < 6);
    const theme = night ? 'night' : (settings.theme || 'day');
    setTheme(theme);
  }
  function setTheme(theme){ settings.theme = theme; document.body.classList.remove('theme-day','theme-night','theme-eye'); document.body.classList.add('theme-'+theme); syncThemeColorMeta(theme); saveSettings(settings); }
  function syncThemeColorMeta(theme){
    let color = '#f6f6f6'; if (theme==='night') color = '#0b0c0f'; else if (theme==='eye') color = '#efe9c8';
    let meta = document.querySelector('meta[name="theme-color"]'); if (!meta){ meta=document.createElement('meta'); meta.setAttribute('name','theme-color'); document.head.appendChild(meta); } meta.setAttribute('content', color);
  }
  applyAutoTheme();

  // Typography controls
  $$('#settings-panel .size-btn').forEach(btn=> btn.addEventListener('click', ()=>{
    const size = btn.dataset.size;
    const map = { small: 16, medium: 18, large: 20, xlarge: 22 };
    settings.fontSize = map[size] || 18; applySettings(settings); saveSettings(settings); reflow();
  }));

  function applySettings(s){
    document.documentElement.style.setProperty('--reader-font-size', (s.fontSize||18)+'px');
    document.documentElement.style.setProperty('--reader-line-height', String(s.lineHeight||1.65));
    document.documentElement.style.setProperty('--para-space', String(s.paraSpace||0.9)+'em');
    if (typeof s.brightness === 'number' && brightnessOverlay){ brightnessOverlay.style.opacity = String(1 - Math.max(0, Math.min(1, s.brightness))); }
  }

  // Panel toggles
  function closeAllPanels(){ drawer?.classList.remove('show'); settingsPanel?.classList.remove('show'); themePanel?.classList.remove('show'); progressPanel?.classList.remove('show'); bookmarksPanel?.classList.remove('show'); }
  function showControls(temp=true){ document.body.classList.add('controls-visible'); if (temp){ clearTimeout(showControls._t); showControls._t=setTimeout(()=>document.body.classList.remove('controls-visible'),2600); } }
  function toggleControls(){ if (document.body.classList.contains('controls-visible')) document.body.classList.remove('controls-visible'); else showControls(); }

  // Footer actions
  if (btnTOC) btnTOC.addEventListener('click', ()=>{ closeAllPanels(); drawer?.classList.add('show'); showControls(false); });
  if (btnFont) btnFont.addEventListener('click', ()=>{ closeAllPanels(); settingsPanel?.classList.add('show'); showControls(false); });
  if (btnTheme) btnTheme.addEventListener('click', ()=>{ closeAllPanels(); themePanel?.classList.add('show'); showControls(false); });
  if (btnProgress) btnProgress.addEventListener('click', ()=>{ closeAllPanels(); progressPanel?.classList.add('show'); showControls(false); updateStats(); });
  if (btnBookmark) btnBookmark.addEventListener('click', ()=>{ addBookmark(); loadBookmarks(); closeAllPanels(); bookmarksPanel?.classList.add('show'); showControls(false); });

  // Gesture
  let width = 0; let height = 0;
  function reflow(){
    width = contentArea.clientWidth; height = contentArea.clientHeight;
    pageBody.style.height = height + 'px';
    pageBody.style.columnWidth = width + 'px';
    pageBody.style.columnGap = '0px';
    // compute total pages by measuring scroll width of the columns container
    totalPages = Math.max(1, Math.ceil(pageBody.scrollWidth / width));
    goToPage(Math.min(currentPage, totalPages-1), false);
    updateProgressUI();
  }

  function updateProgressUI(){ if (progressText) progressText.textContent = Math.round(((currentPage+1)/totalPages)*100) + '%'; }

  function setTransform(x, withTransition=false){
    if (withTransition) pageBody.classList.add('page-turn'); else pageBody.classList.remove('page-turn');
    pageBody.style.transform = `translate3d(${x}px, 0, 0)`;
  }

  function goToPage(p, animate=true){ p = Math.max(0, Math.min(p, totalPages-1)); currentPage = p; const target = -p * width; setTransform(target, animate); updateProgressUI(); debounceSaveProgress(); updateStats(); }
  function nextPage(){ if (currentPage < totalPages-1){ goToPage(currentPage+1); } else if (nextUrl) { window.location.assign(nextUrl); } }
  function prevPage(){ if (currentPage > 0){ goToPage(currentPage-1); } else if (prevUrl) { window.location.assign(prevUrl); } }

  // Hook gesture library
  const gesture = new window.ReadingGesture(contentArea, {
    onSwipeLeft: ()=> nextPage(),
    onSwipeRight: ()=> prevPage(),
    onTapCenter: ()=> toggleControls(),
    onDrag: (dx, info)=>{
      if (!width) return;
      if (info && info.phase === 'move'){
        const base = -currentPage * width;
        const offset = dx; // follow finger
        setTransform(base + offset, false);
      } else if (info && (info.phase === 'cancel' || info.phase==='end')){
        // snap back
        const base = -currentPage * width; setTransform(base, true);
      }
    },
  });

  // Stats
  const stats = { startedAt: Date.now(), pagesTurned: 0, lastFlipAt: Date.now(), wpm: 0 };
  function updateStats(){
    const text = pageBody.innerText || pageBody.textContent || '';
    const words = Math.max(1, text.replace(/\s+/g,'').length);
    const readPages = currentPage + 1;
    const total = totalPages;
    const chapterProgress = Math.round((readPages/total)*100);
    const elapsedMin = Math.max(0.1, (Date.now() - stats.startedAt) / 60000);
    const estimatedWPM = Math.round(words / elapsedMin / (total)); // rough average per page based calc
    stats.wpm = estimatedWPM;
    const estRemainMin = Math.round((total - readPages) * (elapsedMin / readPages));
    const statText = `进度 ${chapterProgress}% · 估算速度 ${estimatedWPM}字/分 · 预计剩余 ${Math.max(0, estRemainMin)} 分钟`;
    const el = $('#stats-text'); if (el) el.textContent = statText;
  }

  // Auto scroll (auto page turn) and timer
  let autoTimer = null; let wakeLock = null; let countdownTimer = null;
  function startAutoScroll(){ if (settings.autoScrollSpeed <= 0) return; stopAutoScroll(); const interval = Math.max(1200, 6000 - settings.autoScrollSpeed * 1000); autoTimer = setInterval(()=>{ nextPage(); }, interval); requestWakeLock(); }
  function stopAutoScroll(){ if (autoTimer) clearInterval(autoTimer); autoTimer = null; releaseWakeLock(); }
  function setTimer(minutes){ settings.timerEnd = minutes>0 ? (Date.now() + minutes*60000) : 0; saveSettings(settings); if (countdownTimer) clearInterval(countdownTimer); if (settings.timerEnd){ countdownTimer = setInterval(()=>{ if (Date.now() >= settings.timerEnd){ stopAutoScroll(); settings.autoScrollSpeed = 0; saveSettings(settings); updateControlsUI(); clearInterval(countdownTimer); countdownTimer=null; } }, 1000); } }

  async function requestWakeLock(){ try{ if ('wakeLock' in navigator){ wakeLock = await navigator.wakeLock.request('screen'); } }catch(e){} }
  function releaseWakeLock(){ try{ wakeLock && wakeLock.release && wakeLock.release(); }catch(e){} finally{ wakeLock=null; } }
  document.addEventListener('visibilitychange', ()=>{ if (document.visibilityState==='visible' && (autoTimer || settings.keepAwake)) requestWakeLock(); });

  // Progress saving (reuse reading.php API)
  function debounce(fn, wait){ let t=null; return function(){ clearTimeout(t); t=setTimeout(fn, wait); } }
  const saveProgress = ()=>{ fetch(`/reading.php?action=save_progress&novel_id=${novelId}`, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ chapter_id: chapterId, page: currentPage }) }).catch(()=>{}); };
  const debounceSaveProgress = debounce(saveProgress, 400);

  // Bookmarks
  function addBookmark(){ fetch(`/reading.php?action=add_bookmark&novel_id=${novelId}`, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ chapter_id: chapterId, page: currentPage, note: '' }) }).catch(()=>{}); }
  function loadBookmarks(){ fetch(`/reading.php?action=list_bookmarks&novel_id=${novelId}`).then(r=>r.json()).then(data=>{ const list=$('#bookmarks-list'); if (!list) return; list.innerHTML=''; const items=(data&&data.bookmarks)||[]; if (!items.length){ list.innerHTML='<li class="empty">暂无书签</li>'; return; } items.sort((a,b)=> new Date(b.created_at)-new Date(a.created_at)); items.forEach(b=>{ const li=document.createElement('li'); li.className='bookmark-item'; li.innerHTML=`<div class="row"><div>第${b.chapter_id}章 第${(b.page||0)+1}页</div><div class="meta">${b.created_at?new Date(b.created_at).toLocaleString():''}</div></div><div class="ops"><button data-go>跳转</button><button data-del>删除</button></div>`; li.querySelector('[data-go]').addEventListener('click', ()=>{ window.location.assign(`/mobile_reading.php?novel_id=${novelId}&chapter_id=${b.chapter_id}&page=${b.page||0}`); }); li.querySelector('[data-del]').addEventListener('click', ()=>{ fetch(`/reading.php?action=delete_bookmark&novel_id=${novelId}`, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:`id=${encodeURIComponent(b.id)}`}).then(()=>loadBookmarks()); }); list.appendChild(li); }); }).catch(()=>{}); }

  // Populate TOC in drawer
  (function initTOC(){ const toc = $('#toc'); if (!toc) return; try{ const data = JSON.parse(app.dataset.chapters||'[]'); toc.innerHTML=''; data.forEach(c=>{ const a=document.createElement('a'); a.className='toc-item'; a.textContent=c.title; a.href=`/mobile_reading.php?novel_id=${novelId}&chapter_id=${c.id}`; if (Number(c.id)===chapterId) a.classList.add('active'); toc.appendChild(a); }); }catch(e){} })();

  // Theme switcher
  $$('#theme-panel [data-theme]').forEach(btn=> btn.addEventListener('click', ()=>{ setTheme(btn.dataset.theme); }));
  const brightnessSlider = $('#brightness-slider');
  if (brightnessSlider){ brightnessSlider.value = String(Math.round((settings.brightness ?? 1)*100)); brightnessSlider.addEventListener('input', ()=>{ settings.brightness = Math.max(0, Math.min(1, Number(brightnessSlider.value)/100)); applySettings(settings); saveSettings(settings); }); }

  // Progress panel controls
  const pageSlider = $('#page-slider'); const speedSlider = $('#speed-slider'); const timerBtns = $$('#timer-buttons button');
  if (pageSlider) pageSlider.addEventListener('input', ()=>{ goToPage(Number(pageSlider.value)); });
  if (speedSlider) speedSlider.addEventListener('input', ()=>{ settings.autoScrollSpeed = Number(speedSlider.value); saveSettings(settings); if (settings.autoScrollSpeed>0) startAutoScroll(); else stopAutoScroll(); updateControlsUI(); });
  timerBtns.forEach(btn=> btn.addEventListener('click', ()=>{ const mins = Number(btn.dataset.min||'0'); setTimer(mins); }));

  function updateControlsUI(){ if (pageSlider) { pageSlider.max=String(Math.max(0,totalPages-1)); pageSlider.value=String(currentPage); } if (speedSlider) speedSlider.value = String(settings.autoScrollSpeed||0); }

  // Safe viewport height unit
  function setVH(){ const vh = window.innerHeight * 0.01; document.documentElement.style.setProperty('--vh', `${vh}px`); }
  setVH(); window.addEventListener('resize', ()=>{ setVH(); reflow(); }, {passive:true});

  // Initial
  document.body.classList.add('theme-'+(settings.theme||'day'));
  reflow();
  goToPage(currentPage, false);
  showControls(true);

  // Accessibility: keyboard
  document.addEventListener('keydown', (e)=>{ if (e.key==='ArrowRight' || e.key==='PageDown' || e.key===' '){ e.preventDefault(); nextPage(); } if (e.key==='ArrowLeft' || e.key==='PageUp'){ e.preventDefault(); prevPage(); } if (e.key==='Escape'){ e.preventDefault(); toggleControls(); } });
})();
