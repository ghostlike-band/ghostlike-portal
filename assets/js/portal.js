/* GHOSTLIKE — Member-Portal
   Daten kommen aus der REST-API (/wp-json/ghostlike-portal/v1/), alle fünf sehen denselben Stand.
   Änderungen werden sofort angezeigt und im Hintergrund gespeichert; alle 30 s wird neu geladen. */
(function () {
  'use strict';

  const CFG = window.GLP || {};
  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => [...el.querySelectorAll(s)];
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  let S = null;            // Serverstand
  let busy = 0;            // laufende Speichervorgänge
  let focus = '', sort = 'track', calType = 'alle';
  let curId = null;        // Song im Detailfenster

  /* ================= API ================= */
  async function api(path, opts = {}) {
    busy++;
    try {
      const res = await fetch(CFG.rest + path, {
        method: opts.method || 'GET',
        credentials: 'same-origin',
        headers: { 'X-WP-Nonce': CFG.nonce, 'Content-Type': 'application/json' },
        body: opts.body ? JSON.stringify(opts.body) : undefined,
      });
      const json = await res.json().catch(() => ({}));
      if (res.status === 401 || res.status === 403) { toast('Sitzung abgelaufen – bitte neu einloggen.'); setTimeout(() => location.reload(), 1500); }
      if (!res.ok) throw new Error(json.message || 'Speichern fehlgeschlagen.');
      return json;
    } finally { busy--; }
  }

  /* ================= RECHNEN ================= */
  const TYPES = {
    done: { cycle: [0, 2, -1], label: { 0: 'offen', 2: 'fertig' } },
    rec: { cycle: [0, 1, 2, -1], label: { 0: 'offen', 1: 'aufgenommen', 2: 'editiert' } },
    post: { cycle: [0, 1, 2, -1], label: { 0: 'offen', 1: 'in Arbeit', 2: 'fertig' } },
  };
  const label = (step, v) => (v === -1 ? 'nicht nötig' : TYPES[step.t].label[v] ?? 'offen');
  const stateOf = (song, step) => { const v = song.st[step.k]; return typeof v === 'number' ? v : 0; };
  const nextOf = (step, v) => { const c = TYPES[step.t].cycle; return c[(c.indexOf(v) + 1) % c.length]; };
  const val = (v) => (v === 2 ? 1 : v === 1 ? 0.5 : 0);
  function pct(song) {
    let w = 0, got = 0;
    S.steps.forEach((s) => { const v = stateOf(song, s); if (v === -1) return; w += +s.w; got += +s.w * val(v); });
    return w ? Math.round((got / w) * 100) : 100;
  }
  const album = () => S.songs.filter((s) => s.album);
  const avg = (list) => (list.length ? Math.round(list.reduce((a, s) => a + pct(s), 0) / list.length) : 0);
  const openSteps = (list) => list.reduce((n, s) => n + S.steps.filter((st) => [0, 1].includes(stateOf(s, st))).length, 0);
  const isMine = (step, name) => (step.who || []).some((w) => w.toLowerCase() === String(name).toLowerCase());

  /* ================= HILFEN ================= */
  let initials = {};
  function buildInitials() {
    initials = {};
    const names = S.members.map((m) => m.name);
    names.forEach((n) => {
      const one = n.slice(0, 1).toUpperCase();
      const clash = names.filter((x) => x.slice(0, 1).toUpperCase() === one).length > 1;
      initials[n.toLowerCase()] = clash ? n.slice(0, 2).toUpperCase() : one;
    });
  }
  const ini = (name) => initials[String(name).toLowerCase()] || String(name).slice(0, 2).toUpperCase();
  const av = (name, cls = '') => `<span class="avatar avatar--klein ${cls}" title="${esc(name)}">${esc(ini(name))}</span>`;
  const fmt = (d, o) => new Date(d).toLocaleDateString('de-DE', o);
  const fmtTime = (d) => new Date(d).toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' });
  function ago(t) {
    const m = Math.round((Date.now() - t) / 60000);
    if (m < 1) return 'gerade eben'; if (m < 60) return `vor ${m} Min.`;
    const h = Math.round(m / 60); if (h < 24) return `vor ${h} Std.`;
    const d = Math.round(h / 24); return d === 1 ? 'gestern' : `vor ${d} Tagen`;
  }
  function toast(msg) {
    const t = $('#toast'); t.textContent = msg; t.classList.add('is-on');
    clearTimeout(toast.h); toast.h = setTimeout(() => t.classList.remove('is-on'), 2800);
  }
  const songById = (id) => S.songs.find((s) => s.id === +id);
  const stepByKey = (k) => S.steps.find((s) => s.k === k);
  function replaceSong(song) { const i = S.songs.findIndex((s) => s.id === song.id); if (i >= 0) S.songs[i] = song; else S.songs.push(song); }
  function localLog(txt) { S.log.unshift({ who: S.me.name, txt, t: Date.now() }); }

  /* ================= NAVIGATION ================= */
  function show(view) {
    $$('.side__nav button').forEach((b) => b.classList.toggle('is-active', b.dataset.view === view));
    $$('.view').forEach((v) => { v.hidden = v.dataset.view !== view; });
    try { sessionStorage.setItem('glp-view', view); } catch (e) {}
    if (history.replaceState) history.replaceState(null, '', '#' + view);
    scrollTo(0, 0);
  }
  $$('.side__nav button').forEach((b) => b.addEventListener('click', () => show(b.dataset.view)));
  document.addEventListener('click', (e) => { const g = e.target.closest('[data-goto]'); if (g) show(g.dataset.goto); });

  /* ================= ZUSAGEN ================= */
  function attOf(key) { return (S.attendance && S.attendance[key]) || {}; }
  function zusageHTML(ev) {
    const a = attOf(ev.key);
    const people = S.members.map((m) => av(m.name, a[m.id] === 'ja' ? '' : a[m.id] === 'nein' ? 'avatar--aus' : 'avatar--offen')).join('');
    if (ev.cancelled) return `<div class="zusage__leute">${people}</div><span class="meta">Fällt aus</span>`;
    const mine = a[S.me.id];
    return `<div class="zusage__leute">${people}</div>
      <button class="btn ${mine === 'ja' ? 'btn--patina' : ''}" data-att="${esc(ev.key)}" data-v="ja">Dabei</button>
      <button class="btn ${mine === 'nein' ? 'btn--hell' : ''}" data-att="${esc(ev.key)}" data-v="nein">Nicht dabei</button>`;
  }
  document.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-att]'); if (!b) return;
    const key = b.dataset.att, v = b.dataset.v;
    S.attendance[key] = S.attendance[key] || {};
    const before = S.attendance[key][S.me.id];
    const now = before === v ? '' : v;
    if (now) S.attendance[key][S.me.id] = now; else delete S.attendance[key][S.me.id];
    renderCal(); renderHome();
    try { await api('attendance', { method: 'POST', body: { key, status: now } }); }
    catch (err) { if (before) S.attendance[key][S.me.id] = before; else delete S.attendance[key][S.me.id]; renderCal(); renderHome(); toast(err.message); }
  });

  /* ================= ÜBERSICHT ================= */
  function myTodos() {
    const out = [];
    album().forEach((s) => S.steps.forEach((st) => {
      const v = stateOf(s, st);
      if ((v === 0 || v === 1) && isMine(st, S.me.name)) out.push({ s, st, v, p: pct(s) });
    }));
    return out.sort((a, b) => b.p - a.p || b.v - a.v); // fast fertige Songs zuerst – motiviert
  }

  function renderHome() {
    $('#today').textContent = fmt(Date.now(), { weekday: 'long', day: '2-digit', month: 'long', year: 'numeric' });
    $('#hello-name').textContent = S.me.name;
    const next = S.events.find((e) => !e.cancelled);
    $('#home-next').innerHTML = next ? `
      <span class="meta">${esc(typeLabel(next.type))}</span>
      <p class="display-m">${esc(fmt(next.start, { weekday: 'short', day: '2-digit', month: '2-digit' }))}</p>
      <span class="label">${esc(next.title)}</span>
      <span class="meta meta--hell">${esc(next.place || '')}${next.type !== 'probe' ? ' · ' + fmtTime(next.start) : ''}</span>
      <div class="zusage">${zusageHTML(next)}</div>
      ${addLink(next)}` : '<span class="meta">Keine Termine in Sicht.</span>';

    const a = avg(album());
    $('#home-album').textContent = a; $('#home-album-bar').style.width = a + '%';
    $('#home-album-sub').textContent = `${album().filter((s) => pct(s) === 100).length} von ${album().length} Songs fertig · ${openSteps(album())} Schritte offen`;

    const todos = myTodos();
    $('#todo-count').textContent = todos.length ? `${todos.length} offen` : '';
    $('#home-todo').innerHTML = todos.length ? todos.slice(0, 7).map((t) => `
      <li><span><span class="label">${esc(t.st.l)} · ${esc(t.s.title)}</span><br><span class="meta">${label(t.st, t.v)} · Song bei ${t.p}%</span></span>
      <button data-todo="${t.s.id}|${esc(t.st.k)}">→ ${label(t.st, nextOf(t.st, t.v))}</button></li>`).join('')
      : '<li class="leer">Nichts offen. Stark! 🤘</li>';

    $('#home-log').innerHTML = S.log.slice(0, 8).map((l) => `
      <li><span><span class="log__txt">${esc(l.txt)}</span><br><span class="meta">${esc(l.who)}</span></span><span class="meta">${ago(l.t)}</span></li>`).join('')
      || '<li class="leer">Noch nichts passiert.</li>';
  }
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-todo]'); if (!b) return;
    const [sid, k] = b.dataset.todo.split('|');
    setStep(songById(sid), stepByKey(k));
  });

  /* ================= KALENDER ================= */
  const eventIcs = (e) => S.ical + '?event=' + encodeURIComponent(e.key);
  const addLink = (e) => (e.cancelled ? '' : `<a class="link meta cal__add" href="${esc(eventIcs(e))}">＋ In meinen Kalender</a>`);
  function fillAbo() {
    const webcal = S.ical.replace(/^https?:/, 'webcal:');
    $('#ical-url').value = S.ical;
    $('#abo-apple').href = webcal;
    $('#abo-google').href = 'https://calendar.google.com/calendar/render?cid=' + encodeURIComponent(webcal);
    $('#abo-outlook').href = 'https://outlook.live.com/calendar/0/addfromweb?url=' + encodeURIComponent(S.ical) + '&name=GHOSTLIKE';
  }
  const typeLabel = (t) => ({ probe: 'Bandprobe', studio: 'Studio-Session', konzert: 'Konzert', termin: 'Termin' }[t] || 'Termin');

  function renderCal() {
    const list = S.events.filter((e) => calType === 'alle' || e.type === calType);
    $('#cal').innerHTML = list.map((e) => `
      <li class="typ-${esc(e.type)} ${e.cancelled ? 'is-aus' : ''}">
        <div class="cal__date"><span class="meta">${esc(fmt(e.start, { weekday: 'short' }))}</span><b>${esc(fmt(e.start, { day: '2-digit', month: '2-digit' }))}</b></div>
        <div class="cal__info">
          <span class="label">${esc(e.title)}</span>
          <span class="meta">${esc(typeLabel(e.type))}${e.type !== 'probe' ? ' · ' + fmtTime(e.start) : ''}${e.place ? ' · ' + esc(e.place) : ''}</span>
          ${e.who && e.who.length ? `<span class="wer">${e.who.map((w) => av(w)).join('')}</span>` : ''}
          <span class="cal__tools">${addLink(e)}${e.type === 'probe'
            ? `<button class="link meta" data-probe="${esc(e.start.slice(0, 10))}" data-off="${e.cancelled ? 0 : 1}">${e.cancelled ? '↺ Findet doch statt' : '✕ Probe fällt aus'}</button>`
            : `<button class="link meta" data-del-event="${e.id}">✕ Löschen</button>`}</span>
        </div>
        <div class="zusage">${zusageHTML(e)}</div>
      </li>`).join('') || '<li class="leer">Keine Termine.</li>';
  }
  $$('#cal-filter .chip').forEach((c) => c.addEventListener('click', () => {
    calType = c.dataset.type; $$('#cal-filter .chip').forEach((x) => x.classList.toggle('is-on', x === c)); renderCal();
  }));
  document.addEventListener('click', async (e) => {
    const p = e.target.closest('[data-probe]');
    if (p) {
      const off = p.dataset.off === '1';
      if (off && !confirm('Probe am ' + fmt(p.dataset.probe, { day: '2-digit', month: '2-digit' }) + ' für alle absagen?')) return;
      try { await api('probe-off', { method: 'POST', body: { date: p.dataset.probe, off } }); await load(); toast(off ? 'Probe abgesagt' : 'Probe findet statt'); }
      catch (err) { toast(err.message); }
      return;
    }
    const d = e.target.closest('[data-del-event]');
    if (d) {
      if (!confirm('Termin wirklich löschen?')) return;
      try { await api('events/' + d.dataset.delEvent, { method: 'DELETE' }); await load(); toast('Termin gelöscht'); }
      catch (err) { toast(err.message); }
    }
  });

  // Termin anlegen
  const evForm = $('#event-form');
  $('#event-toggle').addEventListener('click', () => {
    evForm.hidden = !evForm.hidden;
    if (!evForm.hidden) {
      $('#ev-who').innerHTML = S.members.map((m) => `<label><input type="checkbox" value="${esc(m.name)}"><span>${esc(m.name)}</span></label>`).join('');
      if (!$('#ev-date').value) $('#ev-date').value = new Date().toISOString().slice(0, 10);
      $('#ev-title').focus();
    }
  });
  $('#event-cancel').addEventListener('click', () => { evForm.hidden = true; });
  evForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const body = {
      type: $('#ev-type').value, title: $('#ev-title').value.trim(), date: $('#ev-date').value,
      time: $('#ev-time').value, place: $('#ev-place').value.trim(),
      who: $$('#ev-who input:checked').map((i) => i.value),
    };
    try {
      await api('events', { method: 'POST', body });
      evForm.reset(); evForm.hidden = true;
      await load(); toast('Termin gespeichert');
    } catch (err) { toast(err.message); }
  });

  // Kalender-Abo
  $('#ical-toggle').addEventListener('click', () => { const p = $('#ical-panel'); p.hidden = !p.hidden; fillAbo(); });
  $('#ical-copy').addEventListener('click', async () => {
    const inp = $('#ical-url');
    try { await navigator.clipboard.writeText(inp.value); toast('Link kopiert'); }
    catch (e) { inp.select(); toast('Link markiert – jetzt kopieren'); }
  });
  $('#ical-reset').addEventListener('click', async () => {
    if (!confirm('Neuen Link erzeugen? Der alte funktioniert dann nicht mehr.')) return;
    try { const r = await api('ical-reset', { method: 'POST' }); S.ical = r.ical; fillAbo(); renderCal(); renderHome(); toast('Neuer Link erzeugt – bitte im Kalender neu abonnieren'); }
    catch (err) { toast(err.message); }
  });

  /* ================= STUDIO ================= */
  function renderStudio() {
    const alb = album(), fresh = alb.filter((s) => !s.released);
    $('#kpi-album').textContent = avg(alb) + '%';
    $('#kpi-new').textContent = avg(fresh) + '%';
    $('#kpi-done').textContent = `${alb.filter((s) => pct(s) === 100).length}/${alb.length}`;
    $('#kpi-open').textContent = openSteps(alb);
    $('#studio-bar').style.width = avg(alb) + '%';

    // Fokus-Filter
    $('#member-filter').innerHTML = '<span class="meta">Fokus:</span>' +
      [{ name: '' }].concat(S.members).map((m) => `<button class="chip ${focus === m.name ? 'is-on' : ''}" data-member="${esc(m.name)}">${esc(m.name || 'Alle')}</button>`).join('');

    // Kopf
    let g1 = '<tr><th class="song-col">Song · Fortschritt</th>', g2 = '<tr><th class="song-col"></th>', last = '';
    Object.keys(S.groups).forEach((g) => {
      const n = S.steps.filter((s) => s.g === g).length;
      if (n) g1 += `<th colspan="${n}" class="grp-start">${esc(S.groups[g])}</th>`;
    });
    const ordered = Object.keys(S.groups).flatMap((g) => S.steps.filter((s) => s.g === g));
    ordered.forEach((s) => { g2 += `<th class="${s.g !== last ? 'grp-start' : ''}">${esc(s.l)}</th>`; last = s.g; });
    g1 += '</tr>'; g2 += '</tr>';

    const row = (s) => {
      const p = pct(s); let l = '';
      const cells = ordered.map((st) => {
        const v = stateOf(s, st), cls = v === -1 ? 'sna' : 's' + v;
        const mine = focus && isMine(st, focus) ? 'is-mine' : '';
        const html = `<td class="${st.g !== l ? 'grp-start' : ''}"><button class="cell ${cls} ${mine}" data-cell="${s.id}|${esc(st.k)}" title="${esc(st.l)} — ${label(st, v)}${st.who && st.who.length ? ' · ' + esc(st.who.join(' / ')) : ''}"><i></i></button></td>`;
        l = st.g; return html;
      }).join('');
      const tags = [s.released ? '<span class="tag">Released</span>' : '', s.flags.single ? '<span class="tag tag--patina">Single</span>' : '', s.flags.video ? '<span class="tag">Video</span>' : ''].join('');
      return `<tr class="${s.released ? 'is-released' : ''} ${!s.album ? 'is-pool' : ''}">
        <td class="song-col"><button class="song-btn" data-song="${s.id}">
          <span class="meta">${s.album ? String(s.nr).padStart(2, '0') : '—'}</span>
          <span class="song-title"><span class="label">${esc(s.title)}</span></span>
          <span class="pct">${p}%</span>
          <span></span><span class="bar"><i style="width:${p}%"></i></span>
          ${tags ? `<span></span><span class="tags">${tags}</span>` : ''}
        </button></td>${cells}</tr>`;
    };

    const list = [...album()];
    if (sort === 'progress') list.sort((a, b) => pct(b) - pct(a)); else list.sort((a, b) => a.nr - b.nr || a.id - b.id);
    const pool = S.songs.filter((s) => !s.album);
    const m = $('#matrix');
    m.classList.toggle('has-focus', !!focus);
    m.innerHTML = `<thead>${g1}${g2}</thead><tbody>${list.map(row).join('')}
      ${pool.length ? `<tr class="sep"><td colspan="${ordered.length + 1}"><span class="meta">Song-Pool · nicht auf dem Album</span></td></tr>${pool.map(row).join('')}` : ''}</tbody>`;

    const sessions = S.events.filter((e) => e.type === 'studio');
    $('#sessions').innerHTML = sessions.length ? sessions.map((e) => `
      <li><span class="meta">${esc(fmt(e.start, { weekday: 'short', day: '2-digit', month: '2-digit' }))}</span>
      <span><span class="label">${esc(e.title)}</span><br><span class="meta">${esc(e.place || '')} · ${fmtTime(e.start)}</span></span>
      <span class="wer">${(e.who || []).map((w) => av(w)).join('')}</span></li>`).join('')
      : '<li class="leer">Noch keine Studio-Sessions geplant. Leg im Kalender eine an.</li>';
    $('#weights').innerHTML = ordered.map((s) => `<li><span>${esc(s.l)}</span><span class="meta">×${esc(s.w)}</span></li>`).join('');
  }

  async function setStep(song, step, value) {
    if (!song || !step) return;
    const before = stateOf(song, step);
    const v = value === undefined ? nextOf(step, before) : value;
    song.st[step.k] = v;
    localLog(`${step.l} · ${song.title} → ${label(step, v)}`);
    renderAll();
    const c = $(`[data-cell="${song.id}|${step.k}"]`); if (c) c.classList.add('pop');
    try { replaceSong(await api(`songs/${song.id}/step`, { method: 'POST', body: { step: step.k, value: v } })); renderAll(); }
    catch (err) { song.st[step.k] = before; S.log.shift(); renderAll(); toast(err.message); }
  }

  $('#matrix').addEventListener('click', (e) => {
    const c = e.target.closest('[data-cell]');
    if (c) { const [sid, k] = c.dataset.cell.split('|'); setStep(songById(sid), stepByKey(k)); return; }
    const sb = e.target.closest('[data-song]'); if (sb) openDrawer(+sb.dataset.song);
  });
  $('#member-filter').addEventListener('click', (e) => { const c = e.target.closest('[data-member]'); if (!c) return; focus = c.dataset.member; renderStudio(); });
  $$('[data-sort]').forEach((c) => c.addEventListener('click', () => {
    sort = c.dataset.sort; $$('[data-sort]').forEach((x) => x.classList.toggle('is-on', x === c)); renderStudio();
  }));
  $('#add-song').addEventListener('submit', async (e) => {
    e.preventDefault();
    const inp = $('#new-song'), title = inp.value.trim();
    if (!title) { inp.focus(); return; }
    try { const s = await api('songs', { method: 'POST', body: { title } }); replaceSong(s); inp.value = ''; localLog('Neuer Song angelegt: ' + s.title); renderAll(); toast('Song angelegt'); }
    catch (err) { toast(err.message); }
  });

  /* ================= SONG-DETAIL ================= */
  const drawer = $('#drawer');
  function openDrawer(id) {
    curId = id; const s = songById(id); if (!s) return;
    drawer.hidden = false; document.body.style.overflow = 'hidden';
    fillDrawer(true);
    $('[data-close].btn', drawer).focus();
  }
  function fillDrawer(inputsToo) {
    const s = songById(curId); if (!s) { closeDrawer(); return; }
    $('#d-nr').textContent = s.album ? `Track ${String(s.nr).padStart(2, '0')}${s.released ? ' · veröffentlicht' : ''}` : 'Song-Pool';
    $('#d-title').textContent = s.title;
    const p = pct(s); $('#d-bar').style.width = p + '%'; $('#d-pct').textContent = p + '%';
    const ordered = Object.keys(S.groups).flatMap((g) => S.steps.filter((st) => st.g === g));
    let g = '';
    $('#d-steps').innerHTML = ordered.map((st) => {
      const head = st.g !== g ? `<li class="grp"><span class="meta">${esc(S.groups[st.g])}</span></li>` : ''; g = st.g;
      const v = stateOf(s, st);
      const opts = TYPES[st.t].cycle.map((o) => `<option value="${o}" ${v === o ? 'selected' : ''}>${label(st, o)}</option>`).join('');
      return `${head}<li><span class="label">${esc(st.l)}</span><span class="wer">${(st.who || []).map((w) => av(w)).join('') || '<span class="meta">wer?</span>'}</span>
        <select data-step="${esc(st.k)}" aria-label="Status ${esc(st.l)}">${opts}</select></li>`;
    }).join('');
    if (inputsToo) {
      $$('[data-meta]', drawer).forEach((i) => { i.value = s.meta[i.dataset.meta] || ''; });
      $$('[data-flag]', drawer).forEach((i) => { i.checked = !!s.flags[i.dataset.flag]; });
      $('#d-f-title').value = s.title; $('#d-f-nr').value = s.nr;
      $('#d-f-album').checked = s.album; $('#d-f-released').checked = s.released;
      $('#d-saved').textContent = '';
    }
  }
  function closeDrawer() { drawer.hidden = true; document.body.style.overflow = ''; curId = null; }
  $$('[data-close]', drawer).forEach((b) => b.addEventListener('click', closeDrawer));
  addEventListener('keydown', (e) => { if (e.key === 'Escape' && !drawer.hidden) closeDrawer(); });

  $('#d-steps').addEventListener('change', (e) => {
    const sel = e.target.closest('[data-step]'); if (!sel) return;
    setStep(songById(curId), stepByKey(sel.dataset.step), +sel.value);
  });

  // Felder speichern (Text verzögert, Haken sofort)
  let saveTimer = null;
  function queueSave(immediate) {
    clearTimeout(saveTimer);
    $('#d-saved').textContent = 'Ungespeichert …';
    saveTimer = setTimeout(saveDrawer, immediate ? 0 : 700);
  }
  async function saveDrawer() {
    const s = songById(curId); if (!s) return;
    const body = {
      title: $('#d-f-title').value.trim() || s.title,
      nr: parseInt($('#d-f-nr').value, 10) || 0,
      album: $('#d-f-album').checked,
      released: $('#d-f-released').checked,
      meta: Object.fromEntries($$('[data-meta]', drawer).map((i) => [i.dataset.meta, i.value])),
      flags: Object.fromEntries($$('[data-flag]', drawer).map((i) => [i.dataset.flag, i.checked])),
    };
    try { replaceSong(await api('songs/' + s.id, { method: 'POST', body })); $('#d-saved').textContent = 'Gespeichert ✓'; fillDrawer(false); renderStudio(); renderHome(); }
    catch (err) { $('#d-saved').textContent = err.message; }
  }
  drawer.addEventListener('input', (e) => {
    if (e.target.matches('[data-meta], #d-f-title, #d-f-nr')) queueSave(false);
  });
  drawer.addEventListener('change', (e) => {
    if (e.target.matches('[data-flag], #d-f-album, #d-f-released')) queueSave(true);
  });
  $('#d-delete').addEventListener('click', async () => {
    const s = songById(curId); if (!s) return;
    if (!confirm(`„${s.title}“ wirklich löschen? Der ganze Aufnahmestand des Songs geht verloren.`)) return;
    try { await api('songs/' + s.id, { method: 'DELETE' }); S.songs = S.songs.filter((x) => x.id !== s.id); closeDrawer(); renderAll(); toast('Song gelöscht'); }
    catch (err) { toast(err.message); }
  });

  /* ================= LADEN ================= */
  function renderAll() {
    $('#me-name').textContent = S.me.name;
    $('#me-avatar').textContent = ini(S.me.name);
    renderHome(); renderCal(); renderStudio();
    if (curId && !drawer.hidden) fillDrawer(false);
  }

  async function load() {
    const fresh = await api('state');
    S = fresh;
    if (Array.isArray(S.attendance)) S.attendance = {};
    S.songs.forEach((s) => { if (Array.isArray(s.st)) s.st = {}; if (Array.isArray(s.meta)) s.meta = {}; if (Array.isArray(s.flags)) s.flags = {}; });
    buildInitials();
    renderAll();
  }

  // Neu laden, damit Änderungen der anderen auftauchen (nicht während gespeichert oder getippt wird).
  setInterval(() => {
    if (document.hidden || busy || saveTimer && $('#d-saved').textContent.startsWith('Ungespeichert')) return;
    const a = document.activeElement;
    if (a && a.matches('input, textarea, select') && !a.closest('.zusage')) return;
    load().catch(() => {});
  }, 30000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden && !busy) load().catch(() => {}); });

  (async function start() {
    try {
      await load();
      $('#loading').hidden = true;
      let view = (location.hash || '').slice(1);
      if (!['home', 'kalender', 'studio', 'pool', 'pr'].includes(view)) { try { view = sessionStorage.getItem('glp-view') || 'home'; } catch (e) { view = 'home'; } }
      show(view);
    } catch (err) {
      $('#loading').textContent = 'Konnte Daten nicht laden: ' + err.message;
    }
  })();
})();
