(function(){
  const $ = (sel, root=document) => root.querySelector(sel);
  const $$ = (sel, root=document) => Array.from(root.querySelectorAll(sel));

  const appEl = $('#readerApp');
  if (!appEl) return; // safety

  const viewport = $('#readerViewport');
  const content = $('#readerContent');
  const progressRange = $('#progressRange');
  const pageIndicator = $('#pageIndicator');
  const chapterSelect = $('#chapterSelect');
  const btnSettings = $('#btnSettings');
  const btnBookmarks = $('#btnBookmarks');
  const settingsPanel = $('#settingsPanel');
  const bookmarksPanel = $('#bookmarksPanel');
  const fontSizeSelect = $('#fontSizeSelect');
  const fontFamilySelect = $('#fontFamilySelect');
  const lineHeightSelect = $('#lineHeightSelect');
  const paraSpaceSelect = $('#paraSpaceSelect');
  const widthModeSelect = $('#widthModeSelect');
  const btnAddBookmark = $('#btnAddBookmark');
  const btnCloseSettings = $('#btnCloseSettings');
  const btnCloseBookmarks = $('#btnCloseBookmarks');

  const zoneLeft = $('#zoneLeft');
  const zoneRight = $('#zoneRight');
  const zoneCenter = $('#zoneCenter');

  const brightnessRange = $('#brightnessRange');
  const dimOverlay = $('#dimOverlay');
  const toggleWakeLock = $('#toggleWakeLock');

  const mbPrev = $('#mbPrev');
  const mbNext = $('#mbNext');
  const mbSettings = $('#mbSettings');
  const mbBookmarks = $('#mbBookmarks');
  const mbFocus = $('#mbFocus');

  const pullIndicator = $('#pullIndicator');

  const isLoggedIn = (document.body.dataset.loggedIn || '') === '1';
  const novelId = Number(viewport?.dataset.novelId || '0');
  let chapterId = Number(viewport?.dataset.chapterId || '0');
  let currentPage = Number(viewport?.dataset.initialPage || '0');
  let totalPages = 1;
  let hideTimer = null;
  let lastTapTime = 0;
  let wakeLock = null;

  function setVH(){
    const vh = window.innerHeight * 0.01;
    document.documentElement.style.setProperty('--vh', `${vh}px`);
  }
  setVH();
  window.addEventListener('resize', throttle(setVH, 200), {passive:true});

  function showControls(temp=true){
    document.body.classList.add('controls-visible');
    if (hideTimer) clearTimeout(hideTimer);
    if (temp) hideTimer = setTimeout(()=>document.body.classList.remove('controls-visible'), 2600);
  }
  function hideControls(){ document.body.classList.remove('controls-visible'); }

  function toggleControls(){
    if (document.body.classList.contains('controls-visible')) hideControls(); else showControls();
  }

  function applySettings(s){
    if (s.fontSize) document.documentElement.style.setProperty('--reader-font-size', s.fontSize + 'px');
    if (s.lineHeight) document.documentElement.style.setProperty('--reader-line-height', s.lineHeight);
    if (s.paraSpace) document.documentElement.style.setProperty('--reader-para-space', s.paraSpace + 'em');
    if (s.fontFamily) {
      let ff = `system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, 'Noto Sans SC', 'PingFang SC', 'Hiragino Sans GB', 'Microsoft YaHei', sans-serif`;
      if (s.fontFamily === 'serif') ff = `'Noto Serif SC','Source Han Serif SC','Source Han Serif CN','Songti SC','SimSun',serif`;
      if (s.fontFamily === 'kaiti') ff = `'STKaiti','KaiTi','Kaiti SC','DFKai-SB',serif`;
      if (s.fontFamily === 'heiti') ff = `'Heiti SC','SimHei','PingFang SC','Microsoft YaHei','Arial',sans-serif`;
      document.documentElement.style.setProperty('--reader-font-family', ff);
    }
    if (s.theme) {
      document.body.classList.remove('theme-original','theme-day','theme-night','theme-eye','theme-zlibrary');
      document.body.classList.add('theme-' + s.theme);
      syncThemeColorMeta(s.theme);
    }
    if (s.widthMode && viewport) {
      viewport.classList.remove('width-narrow','width-standard','width-wide');
      viewport.classList.add('width-' + s.widthMode);
    }
    if (typeof s.brightness === 'number' && dimOverlay){
      dimOverlay.style.opacity = String(1 - s.brightness);
    }
    if (typeof s.focusMode === 'boolean'){
      document.body.classList.toggle('focus-mode', s.focusMode);
    }
  }

  function saveSettings(s){ localStorage.setItem('reader:settings', JSON.stringify(s)); }
  function loadSettings(){ try { return JSON.parse(localStorage.getItem('reader:settings')||'{}') } catch(e){ return {}; } }

  const defaultReaderTheme = document.body.dataset.defaultTheme || 'original';
  const settings = Object.assign({
    fontSize: 18,
    fontFamily: 'system',
    lineHeight: 1.6,
    paraSpace: 0.9,
    theme: defaultReaderTheme,
    widthMode: 'standard',
    brightness: 1,
    focusMode: false,
    keepAwake: false,
  }, loadSettings());

  applySettings(settings);
  if (fontSizeSelect) fontSizeSelect.value = String(settings.fontSize);
  if (fontFamilySelect) fontFamilySelect.value = settings.fontFamily;
  if (lineHeightSelect) lineHeightSelect.value = String(settings.lineHeight);
  if (paraSpaceSelect) paraSpaceSelect.value = String(settings.paraSpace);
  if (widthModeSelect) widthModeSelect.value = settings.widthMode;
  if (brightnessRange) brightnessRange.value = String(Math.round(settings.brightness * 100));
  if (toggleWakeLock) toggleWakeLock.checked = !!settings.keepAwake;

  // bindings
  if (fontSizeSelect) fontSizeSelect.addEventListener('change', ()=>{ settings.fontSize=Number(fontSizeSelect.value); applySettings(settings); saveSettings(settings); reflow(); });
  if (fontFamilySelect) fontFamilySelect.addEventListener('change', ()=>{ settings.fontFamily=fontFamilySelect.value; applySettings(settings); saveSettings(settings); reflow(); });
  if (lineHeightSelect) lineHeightSelect.addEventListener('change', ()=>{ settings.lineHeight=Number(lineHeightSelect.value); applySettings(settings); saveSettings(settings); reflow(); });
  if (paraSpaceSelect) paraSpaceSelect.addEventListener('change', ()=>{ settings.paraSpace=Number(paraSpaceSelect.value); applySettings(settings); saveSettings(settings); reflow(); });
  if (widthModeSelect) widthModeSelect.addEventListener('change', ()=>{ settings.widthMode=widthModeSelect.value; applySettings(settings); saveSettings(settings); reflow(); });
  $$('#settingsPanel button[data-theme]').forEach(btn=> btn.addEventListener('click', ()=>{ settings.theme = btn.dataset.theme; applySettings(settings); saveSettings(settings); }));
  if (brightnessRange) brightnessRange.addEventListener('input', ()=>{ settings.brightness = Math.max(0, Math.min(1, Number(brightnessRange.value)/100)); applySettings(settings); saveSettings(settings); });
  if (toggleWakeLock) toggleWakeLock.addEventListener('change', ()=>{ settings.keepAwake = !!toggleWakeLock.checked; saveSettings(settings); if (settings.keepAwake) requestWakeLock(); else releaseWakeLock(); });

  // Pagination & layout
  function reflow(){
    if (!viewport || !content) return;
    const width = viewport.clientWidth;
    const height = viewport.clientHeight;
    content.style.height = height + 'px';
    content.style.columnWidth = width + 'px';
    content.style.columnGap = '0px';
    totalPages = Math.max(1, Math.ceil(content.scrollWidth / width));
    if (progressRange) progressRange.max = String(totalPages - 1);
    goToPage(Math.min(currentPage, totalPages-1), false);
  }

  function goToPage(p, smooth=true){
    if (!viewport) return;
    p = Math.max(0, Math.min(p, totalPages-1));
    currentPage = p;
    viewport.scrollTo({ left: p * viewport.clientWidth, top: 0, behavior: smooth ? 'smooth' : 'auto' });
    if (progressRange) progressRange.value = String(p);
    if (pageIndicator) pageIndicator.textContent = (p+1) + '/' + totalPages;
    debounceSaveProgress();
  }

  if (progressRange) progressRange.addEventListener('input', ()=>{ goToPage(Number(progressRange.value)); });

  function nextPage(){
    if (!viewport) return;
    if (currentPage < totalPages - 1) {
      goToPage(currentPage + 1);
    } else {
      const nextUrl = viewport.dataset.nextUrl;
      if (nextUrl) window.location.assign(nextUrl);
    }
  }
  function prevPage(){
    if (!viewport) return;
    if (currentPage > 0) {
      goToPage(currentPage - 1);
    } else {
      const prevUrl = viewport.dataset.prevUrl;
      if (prevUrl) window.location.assign(prevUrl);
    }
  }

  // keyboard
  document.addEventListener('keydown', (e)=>{
    if (e.key === 'ArrowRight' || e.key === 'PageDown' || e.key === ' ') { e.preventDefault(); nextPage(); }
    if (e.key === 'ArrowLeft' || e.key === 'PageUp') { e.preventDefault(); prevPage(); }
    if (e.key === 'Escape') { e.preventDefault(); if (document.body.classList.contains('controls-visible')) { hideControls(); } else { history.length>1?history.back():window.location.assign('/'); } }
  });

  // touch gestures
  let touchX = 0, touchY = 0;
  if (viewport){
    viewport.addEventListener('touchstart', (e)=>{ if (!e.touches.length) return; touchX = e.touches[0].clientX; touchY = e.touches[0].clientY; }, {passive:true});
    viewport.addEventListener('touchend', (e)=>{
      const dx = (e.changedTouches[0]?.clientX || 0) - touchX;
      const dy = (e.changedTouches[0]?.clientY || 0) - touchY;
      const t = Date.now();
      const isDoubleTap = (t - lastTapTime) < 280; // double tap threshold
      lastTapTime = t;
      if (Math.abs(dx) > 42 && Math.abs(dx) > Math.abs(dy)) {
        if (dx < 0) nextPage(); else prevPage();
      } else {
        if (isDoubleTap) toggleControls(); else showControls();
      }
    }, {passive:true});
  }

  // tap zones (desktop + mobile)
  if (zoneLeft) zoneLeft.addEventListener('click', prevPage);
  if (zoneRight) zoneRight.addEventListener('click', nextPage);
  if (zoneCenter) zoneCenter.addEventListener('click', ()=>{ showControls(); });

  // mouse move shows controls
  document.addEventListener('mousemove', ()=> showControls());

  // settings / bookmarks panels
  if (btnSettings) btnSettings.addEventListener('click', ()=>{ settingsPanel?.classList.toggle('show'); bookmarksPanel?.classList.remove('show'); showControls(false); });
  if (btnCloseSettings) btnCloseSettings.addEventListener('click', ()=> settingsPanel?.classList.remove('show'));
  if (btnBookmarks) btnBookmarks.addEventListener('click', ()=>{ bookmarksPanel?.classList.toggle('show'); settingsPanel?.classList.remove('show'); showControls(false); loadBookmarks(); });
  if (btnCloseBookmarks) btnCloseBookmarks.addEventListener('click', ()=> bookmarksPanel?.classList.remove('show'));

  // mobile bottom bar
  if (mbPrev) mbPrev.addEventListener('click', prevPage);
  if (mbNext) mbNext.addEventListener('click', nextPage);
  if (mbSettings) mbSettings.addEventListener('click', ()=>{ settingsPanel?.classList.toggle('show'); bookmarksPanel?.classList.remove('show'); showControls(false); });
  if (mbBookmarks) mbBookmarks.addEventListener('click', ()=>{ bookmarksPanel?.classList.toggle('show'); settingsPanel?.classList.remove('show'); showControls(false); loadBookmarks(); });
  if (mbFocus) mbFocus.addEventListener('click', ()=>{ settings.focusMode = !settings.focusMode; applySettings(settings); saveSettings(settings); });

  // chapter select
  if (chapterSelect) chapterSelect.addEventListener('change', ()=>{
    const cid = chapterSelect.value;
    const url = `/reading.php?novel_id=${novelId}&chapter_id=${encodeURIComponent(cid)}`;
    window.location.assign(url);
  });

  // progress save
  let saveTimer = null;
  function debounceSaveProgress(){
    if (!isLoggedIn) { // persist local for guests regardless
      localStorage.setItem(`reader:prog:${novelId}`, JSON.stringify({chapter_id: chapterId, page: currentPage}));
      return;
    }
    if (saveTimer) clearTimeout(saveTimer);
    saveTimer = setTimeout(saveProgress, 420);
  }

  function saveProgress(){
    if (!isLoggedIn) return;
    fetch(`/reading.php?action=save_progress&novel_id=${novelId}`, {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ chapter_id: chapterId, page: currentPage })
    }).catch(()=>{});
  }

  // bookmarks
  function loadBookmarks(){
    fetch(`/reading.php?action=list_bookmarks&novel_id=${novelId}`)
      .then(r=>r.json()).then(data=>{
        const ul = $('#bookmarksList');
        if (!ul) return;
        ul.innerHTML = '';
        if (!data || !data.bookmarks || !data.bookmarks.length) {
          ul.innerHTML = '<li class="list-group-item text-muted bg-transparent">暂无书签</li>';
          return;
        }
        data.bookmarks.sort((a,b)=> new Date(b.created_at) - new Date(a.created_at));
        for (const b of data.bookmarks) {
          const li = document.createElement('li');
          li.className = 'list-group-item bg-transparent d-flex justify-content-between align-items-center';
          const txt = document.createElement('div');
          txt.innerHTML = `<div class="small">第${b.chapter_id}章 · 第${(b.page||0)+1}页</div><div class="text-white-50 small">${b.note?b.note:''} <span class="ms-2">${b.created_at?new Date(b.created_at).toLocaleString():''}</span></div>`;
          const ops = document.createElement('div');
          const go = document.createElement('button'); go.className='btn btn-sm btn-outline-light me-2'; go.textContent='跳转';
          go.addEventListener('click', ()=>{ window.location.assign(`/reading.php?novel_id=${novelId}&chapter_id=${b.chapter_id}&page=${b.page||0}`); });
          const del = document.createElement('button'); del.className='btn btn-sm btn-outline-danger'; del.textContent='删除';
          del.addEventListener('click', ()=>{ fetch(`/reading.php?action=delete_bookmark&novel_id=${novelId}`, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:`id=${encodeURIComponent(b.id)}`}).then(()=>loadBookmarks()); });
          ops.appendChild(go); ops.appendChild(del);
          li.appendChild(txt); li.appendChild(ops); ul.appendChild(li);
        }
      }).catch(()=>{});
  }

  if (btnAddBookmark) btnAddBookmark.addEventListener('click', ()=>{
    const note = '';
    fetch(`/reading.php?action=add_bookmark&novel_id=${novelId}`, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ chapter_id: chapterId, page: currentPage, note }) })
      .then(()=> loadBookmarks());
  });

  // Init
  reflow();
  if (currentPage > 0) goToPage(currentPage, false);
  window.addEventListener('resize', throttle(reflow, 200));
  window.addEventListener('beforeunload', saveProgress);

  // Auto-resume for guests
  if (!isLoggedIn) {
    try {
      const prog = JSON.parse(localStorage.getItem(`reader:prog:${novelId}`)||'null');
      if (prog && prog.chapter_id === chapterId) { currentPage = prog.page||0; goToPage(currentPage, false); }
    } catch(e){}
  }

  // Wake lock
  async function requestWakeLock(){
    try{
      if ('wakeLock' in navigator) {
        wakeLock = await navigator.wakeLock.request('screen');
        wakeLock.addEventListener('release', ()=>{ /* no-op */ });
      }
    }catch(e){ /* ignore */ }
  }
  function releaseWakeLock(){ try{ wakeLock && wakeLock.release && wakeLock.release(); }catch(e){} finally{ wakeLock=null; } }
  document.addEventListener('visibilitychange', ()=>{ if (document.visibilityState==='visible' && settings.keepAwake) requestWakeLock(); });
  if (settings.keepAwake) requestWakeLock();

  // Pull to refresh (mobile only)
  let pullStartY = 0, pulling = false;
  let pulled = 0;
  function onTouchStart(e){ if (window.scrollY<=0) { pullStartY = e.touches[0].clientY; pulling = true; pulled = 0; } }
  function onTouchMove(e){ if (!pulling) return; const dy = e.touches[0].clientY - pullStartY; if (dy>10){ e.preventDefault(); pulled = dy; pullIndicator?.classList.add('show'); } if (pullIndicator) pullIndicator.style.transform = `translate(-50%, ${Math.min(60, Math.max(20, dy*0.25))}px)`; }
  function onTouchEnd(){ if (!pulling) return; pulling=false; if (pullIndicator){ pullIndicator.classList.remove('show'); pullIndicator.style.transform=''; } if (pulled>120){ window.location.reload(); } }
  if (matchMedia('(max-width: 768px)').matches){
    window.addEventListener('touchstart', onTouchStart, {passive:true});
    window.addEventListener('touchmove', onTouchMove, {passive:false});
    window.addEventListener('touchend', onTouchEnd, {passive:true});
  }

  // Theme color sync for mobile status bar
  function syncThemeColorMeta(theme){
    let color = '#f6f6f6';
    if (theme==='night') color = '#0b0c0f'; else if (theme==='eye') color = '#efe9c8'; else color = '#f6f6f6';
    let meta = $('meta[name="theme-color"]');
    if (!meta){ meta = document.createElement('meta'); meta.setAttribute('name','theme-color'); document.head.appendChild(meta); }
    meta.setAttribute('content', color);
  }
  syncThemeColorMeta(settings.theme);

  // Utils: throttle
  function throttle(fn, wait){
    let last=0, t=null; return function(...args){ const now=Date.now(); if (now-last>=wait){ last=now; fn.apply(this,args); } else { clearTimeout(t); t=setTimeout(()=>{ last=Date.now(); fn.apply(this,args); }, wait-(now-last)); } };
  }
})();
