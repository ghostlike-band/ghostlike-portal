/* GHOSTLIKE — Member-Portal
   Daten kommen aus der REST-API (/wp-json/ghostlike-portal/v1/), alle fünf sehen denselben Stand.
   Änderungen werden sofort angezeigt und im Hintergrund gespeichert; alle 30 s wird neu geladen. */
(function () {
  'use strict';

  // Handy: kein Zoomen per Pinch (iOS ignoriert user-scalable=no). Doppeltipp-Zoom verhindert touch-action im CSS.
  ['gesturestart', 'gesturechange', 'gestureend'].forEach((ev) => document.addEventListener(ev, (e) => e.preventDefault(), { passive: false }));
  document.addEventListener('touchmove', (e) => { if (e.touches.length > 1) e.preventDefault(); }, { passive: false });

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
        keepalive: !!opts.keepalive,
      });
      const json = await res.json().catch(() => ({}));
      if (res.status === 401 || res.status === 403) { toast('Sitzung abgelaufen – bitte neu einloggen.'); setTimeout(() => location.reload(), 1500); }
      if (!res.ok) throw new Error(json.message || 'Speichern fehlgeschlagen.');
      return json;
    } finally { busy--; }
  }

  /* ================= RECHNEN ================= */
  // Werte: 0 offen · 3 in Arbeit · 1 aufgenommen (rec) bzw. in Arbeit (post) · 2 editiert/fertig · -1 nicht nötig
  const TYPES = {
    done: { cycle: [0, 3, 2, -1], label: { 0: 'offen', 3: 'in Arbeit', 2: 'fertig' }, val: { 3: 0.5, 2: 1 } },
    rec: { cycle: [0, 3, 1, 2, -1], label: { 0: 'offen', 3: 'in Arbeit', 1: 'aufgenommen', 2: 'editiert' }, val: { 3: 0.25, 1: 0.5, 2: 1 } },
    post: { cycle: [0, 1, 2, -1], label: { 0: 'offen', 1: 'in Arbeit', 2: 'fertig' }, val: { 1: 0.5, 2: 1 } },
  };
  const isWip = (st, v) => v === 3 || (st.t === 'post' && v === 1);   // „in Arbeit“ (Welle)
  const isOpen = (v) => v === 0 || v === 1 || v === 3;              // noch nicht erledigt
  const label = (step, v) => (v === -1 ? 'nicht nötig' : TYPES[step.t].label[v] ?? 'offen');
  const stateOf = (song, step) => { const v = song.st[step.k]; return typeof v === 'number' ? v : 0; };
  const nextOf = (step, v) => { const c = TYPES[step.t].cycle; return c[(c.indexOf(v) + 1) % c.length]; };
  const val = (st, v) => TYPES[st.t].val[v] || 0;
  function pct(song) {
    let w = 0, got = 0;
    S.steps.forEach((s) => { const v = stateOf(song, s); if (v === -1) return; w += +s.w; got += +s.w * val(s, v); });
    return w ? Math.round((got / w) * 100) : 100;
  }
  const album = () => S.songs.filter((s) => s.album);
  const avg = (list) => (list.length ? Math.round(list.reduce((a, s) => a + pct(s), 0) / list.length) : 0);
  const openSteps = (list) => list.reduce((n, s) => n + S.steps.filter((st) => isOpen(stateOf(s, st))).length, 0);
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
  const roleOf = (name) => { const m = S && S.members.find((x) => x.name.toLowerCase() === String(name).toLowerCase()); return m && m.role ? ' · ' + m.role : ''; };
  const av = (name, cls = '') => `<span class="avatar avatar--klein ${cls}" title="${esc(name + roleOf(name))}">${esc(ini(name))}</span>`;
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
  function normSong(s) { if (Array.isArray(s.st)) s.st = {}; if (Array.isArray(s.meta)) s.meta = {}; if (Array.isArray(s.flags)) s.flags = {}; return s; }
  function replaceSong(song) { normSong(song); applyPending(song); const i = S.songs.findIndex((s) => s.id === song.id); if (i >= 0) S.songs[i] = song; else S.songs.push(song); }
  function localLog(txt) { S.log.unshift({ who: S.me.name, txt, t: Date.now() }); }

  /* ================= NAVIGATION ================= */
  function show(view) {
    $$('.side__nav button').forEach((b) => b.classList.toggle('is-active', b.dataset.view === view));
    $$('.view').forEach((v) => { v.hidden = v.dataset.view !== view; });
    try { sessionStorage.setItem('glp-view', view); } catch (e) {}
    if (history.replaceState) history.replaceState(null, '', '#' + view);
    scrollTo(0, 0);
    if (view === 'kasse' && typeof loadCash === 'function') loadCash();
    if (view === 'profil') setTimeout(renderPush);
    if (view === 'plays') setTimeout(loadPlays);
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
      if (isOpen(v) && isMine(st, S.me.name)) out.push({ s, st, v, p: pct(s) });
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
      <span class="meta meta--hell">${esc(next.place || '')}${showTime(next) ? ' · ' + fmtTime(next.start) : ''}</span>
      <div class="zusage">${zusageHTML(next)}</div>
      ${addLink(next)}` : '<span class="meta">Keine Termine in Sicht.</span>';

    const a = avg(album());
    $('#home-album').textContent = a; $('#home-album-bar').style.width = a + '%';
    $('#home-album-sub').textContent = `${album().filter((s) => pct(s) === 100).length} von ${album().length} Songs fertig · ${openSteps(album())} Schritte offen`;

    const todos = myTodos();
    $('#todo-count').innerHTML = todos.length ? `${todos.length} offen · <button class="link meta" data-show-todos>alle ↗</button>` : '';
    $('#home-todo').innerHTML = todos.length ? todos.slice(0, 7).map((t) => `
      <li><span><span class="label">${esc(t.st.l)} · ${esc(t.s.title)}</span><br><span class="meta">${label(t.st, t.v)} · Song bei ${t.p}%</span></span>
      <button data-todo="${t.s.id}|${esc(t.st.k)}">→ ${label(t.st, nextOf(t.st, t.v))}</button></li>`).join('')
      : '<li class="leer">Nichts offen. Stark! 🤘</li>';

    $('#home-log').innerHTML = S.log.slice(0, 8).map((l) => `
      <li><span><span class="log__txt">${esc(l.txt)}</span><br><span class="meta">${esc(l.who)}</span></span><span class="meta">${ago(l.t)}</span></li>`).join('')
      || '<li class="leer">Noch nichts passiert.</li>';
  }
  document.addEventListener('click', (e) => {
    if (e.target.closest('[data-show-todos]')) { todoWho = ''; setStudioView('todos'); renderTodos(); show('studio'); return; }
    const b = e.target.closest('[data-todo]'); if (!b) return;
    const [sid, k] = b.dataset.todo.split('|');
    setStep(songById(sid), stepByKey(k));
  });

  /* ================= KALENDER ================= */
  const eventIcs = (e) => S.ical + '?event=' + encodeURIComponent(e.key);
  const addLink = (e) => (e.cancelled ? '' : `<a class="link meta cal__add" href="${esc(eventIcs(e))}" title="Einmalig eintragen. Spätere Änderungen kommen nur über das Kalender-Abo.">＋ In meinen Kalender</a>`);
  function fillAbo() {
    const webcal = S.ical.replace(/^https?:/, 'webcal:');
    $('#ical-url').value = S.ical;
    $('#abo-apple').href = webcal;
    $('#abo-google').href = 'https://calendar.google.com/calendar/render?cid=' + encodeURIComponent(webcal);
    $('#abo-outlook').href = 'https://outlook.live.com/calendar/0/addfromweb?url=' + encodeURIComponent(S.ical) + '&name=GHOSTLIKE';
  }
  const typeLabel = (t) => ({ probe: 'Bandprobe', treffen: 'Treffen / Meeting', studio: 'Studio-Session', konzert: 'Konzert', termin: 'Termin' }[t] || 'Termin');
  const showTime = (e) => !(e.type === 'probe' && e.recurring && !e.changed); // normale Probe: Uhrzeit steht schon beim Ort

  function renderCal() {
    const list = S.events.filter((e) => calType === 'alle' || e.type === calType);
    $('#cal').innerHTML = list.map((e) => `
      <li class="typ-${esc(e.type)} ${e.cancelled ? 'is-aus' : ''}">
        <div class="cal__date"><span class="meta">${esc(fmt(e.start, { weekday: 'short' }))}</span><b>${esc(fmt(e.start, { day: '2-digit', month: '2-digit' }))}</b></div>
        <div class="cal__info">
          <span class="label">${esc(e.title)}</span>
          <span class="meta">${esc(typeLabel(e.type))}${e.recurring && e.changed ? ' <span class="tag tag--leer">statt Probe</span>' : ''}${showTime(e) ? ' · ' + fmtTime(e.start) : ''}${e.place ? ' · ' + esc(e.place) : ''}</span>
          ${e.note ? `<span class="meta meta--hell cal__note">${esc(e.note)}</span>` : ''}
          ${e.who && e.who.length ? `<span class="wer">${e.who.map((w) => av(w)).join('')}</span>` : ''}
          ${planItems(e).length ? `<span class="meta meta--hell cal__plan">Geplant: ${esc(planSummary(planItems(e)))}</span>` : ''}
          <span class="cal__tools">${addLink(e)}${!e.cancelled ? `<button class="link meta" data-edit-event="${esc(e.key)}">✎ Ändern</button>` : ''}${e.recurring
            ? `<button class="link meta" data-probe="${esc(e.start.slice(0, 10))}" data-off="${e.cancelled ? 0 : 1}">${e.cancelled ? '↺ Findet doch statt' : '✕ Fällt aus'}</button>`
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
      if (off && !confirm('Termin am ' + fmt(p.dataset.probe, { day: '2-digit', month: '2-digit' }) + ' für alle absagen?')) return;
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

  // Termin anlegen oder ändern (ein Formular für beides)
  const evForm = $('#event-form');
  let editing = null; // Termin, der gerade geändert wird
  function openEventForm(ev) {
    editing = ev || null;
    evForm.reset();
    const who = new Set(ev ? ev.who || [] : []);
    $('#ev-who').innerHTML = S.members.map((m) => `<label><input type="checkbox" value="${esc(m.name)}" ${who.has(m.name) ? 'checked' : ''}><span>${esc(m.name)}</span></label>`).join('');
    $('#ev-plan').innerHTML = planPicker(new Set(ev && ev.plan ? ev.plan.map((p) => p.s + '|' + p.k) : []));
    $('#ev-type').querySelector('[value=probe]').hidden = !(ev && ev.recurring);
    const head = $('#ev-head');
    head.hidden = !ev;
    if (ev) {
      const d = new Date(ev.start);
      $('#ev-type').value = ev.type;
      $('#ev-title').value = ev.recurring && !ev.changed ? '' : ev.title;
      $('#ev-date').value = ev.start.slice(0, 10);
      $('#ev-time').value = fmtTime(ev.start);
      $('#ev-place').value = ev.recurring && !ev.changed ? (S.probe && S.probe.place) || '' : ev.place || '';
      $('#ev-note').value = ev.note || '';
      head.textContent = ev.recurring
        ? `Nur diesen Termin ändern: ${fmt(d, { weekday: 'long', day: '2-digit', month: 'long' })}. Die anderen Proben bleiben, wie sie sind.`
        : `Termin ändern: ${fmt(d, { weekday: 'long', day: '2-digit', month: 'long' })}`;
      titleAuto = false;
    } else {
      $('#ev-type').value = 'treffen';
      $('#ev-date').value = new Date().toISOString().slice(0, 10);
      titleAuto = true;
    }
    $('#ev-date').disabled = !!(ev && ev.recurring); // eine Probe bleibt an ihrem Tag
    $('#ev-title').required = !(ev && ev.recurring);
    $('#ev-reset').hidden = !(ev && ev.recurring && ev.changed);
    $('#ev-submit').textContent = ev ? 'Änderung speichern' : 'Termin speichern';
    syncPlanBox();
    evForm.hidden = false;
    evForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
    $('#ev-title').focus({ preventScroll: true });
  }
  $('#event-toggle').addEventListener('click', () => { if (!evForm.hidden && !editing) { evForm.hidden = true; return; } openEventForm(null); });
  $('#event-cancel').addEventListener('click', () => { evForm.hidden = true; editing = null; });
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-edit-event]'); if (!b) return;
    const ev = S.events.find((x) => x.key === b.dataset.editEvent);
    if (ev) { if ($('[data-view="kalender"]').hidden) show('kalender'); openEventForm(ev); }
  });
  $('#ev-reset').addEventListener('click', async () => {
    if (!editing) return;
    try { await api('probe-edit', { method: 'POST', body: { date: editing.start.slice(0, 10), reset: true } }); evForm.hidden = true; editing = null; await load(); toast('Wieder normale Probe'); }
    catch (err) { toast(err.message); }
  });
  // Studio-Session: Songs & Schritte auswählen, Titel ergibt sich daraus (solange niemand selbst tippt)
  let titleAuto = true;
  const syncPlanBox = () => { $('#ev-plan-box').hidden = $('#ev-type').value !== 'studio'; };
  $('#ev-type').addEventListener('change', syncPlanBox);
  $('#ev-title').addEventListener('input', () => { titleAuto = $('#ev-title').value.trim() === ''; });
  $('#ev-plan').addEventListener('change', () => {
    if (!titleAuto) return;
    const items = readPlan($('#ev-plan')).map((p) => ({ s: songById(p.s), st: stepByKey(p.k) })).filter((x) => x.s && x.st);
    $('#ev-title').value = planSummary(items).slice(0, 120);
  });
  evForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const body = {
      type: $('#ev-type').value, title: $('#ev-title').value.trim(), date: $('#ev-date').value,
      time: $('#ev-time').value, place: $('#ev-place').value.trim(), note: $('#ev-note').value.trim(),
      who: $$('#ev-who input:checked').map((i) => i.value),
      plan: $('#ev-type').value === 'studio' ? readPlan($('#ev-plan')) : [],
    };
    try {
      if (editing && editing.recurring) await api('probe-edit', { method: 'POST', body: { ...body, date: editing.start.slice(0, 10) } });
      else if (editing) await api(`events/${editing.id}/edit`, { method: 'POST', body });
      else await api('events', { method: 'POST', body });
      const was = editing; editing = null;
      evForm.reset(); evForm.hidden = true;
      await load(); toast(was ? 'Geändert ✓ · Abos aktualisieren sich automatisch' : 'Termin gespeichert');
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
  let showDone = false;
  try { showDone = sessionStorage.getItem('glp-done') === '1'; } catch (e) {}
  const orderedSteps = () => Object.keys(S.groups).flatMap((g) => S.steps.filter((s) => s.g === g));
  const byNr = (a, b) => a.nr - b.nr || a.id - b.id;
  const cellCls = (st, v) => (v === -1 ? 'sna' : isWip(st, v) ? 'sw' : 's' + v);
  const relItems = () => S.release || [];
  const relOf = (s) => (s.meta && s.meta.rel && !Array.isArray(s.meta.rel) ? s.meta.rel : {});
  const relCount = (s) => { const r = relOf(s); return relItems().filter((it) => !!r[it.k]).length; };
  const showRelease = (s) => s.album && (pct(s) === 100 || s.released);
  function songTags(s) {
    const r = relOf(s);
    return [s.released ? '<span class="tag">Released</span>' : '', s.flags.single ? '<span class="tag tag--patina">Single</span>' : '', s.flags.video ? '<span class="tag">Video</span>' : '',
      showRelease(s) && !s.released ? `<span class="tag tag--leer" title="Release-Checkliste">Release ${relCount(s)}/${relItems().length}</span>` : '',
      r.date ? `<span class="tag">${esc(fmt(r.date, { day: '2-digit', month: '2-digit', year: '2-digit' }))}</span>` : ''].join('');
  }
  const grip = (s) => (sort === 'track' && s.album ? `<span class="grip" data-grip="${s.id}" title="Ziehen zum Sortieren" aria-hidden="true">⋮⋮</span>` : '');

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
    const ordered = orderedSteps();
    ordered.forEach((s) => { g2 += `<th class="${s.g !== last ? 'grp-start' : ''}">${esc(s.l)}</th>`; last = s.g; });
    g1 += '</tr>'; g2 += '</tr>';

    const row = (s) => {
      const p = pct(s); let l = '';
      const cells = ordered.map((st) => {
        const v = stateOf(s, st);
        const mine = focus && isMine(st, focus) ? 'is-mine' : '';
        const html = `<td class="${st.g !== l ? 'grp-start' : ''}"><button class="cell ${cellCls(st, v)} ${mine}" data-cell="${s.id}|${esc(st.k)}" title="${esc(st.l)} — ${label(st, v)}${st.who && st.who.length ? ' · ' + esc(st.who.join(' / ')) : ''}"><i></i></button></td>`;
        l = st.g; return html;
      }).join('');
      const tags = songTags(s);
      return `<tr class="${s.released ? 'is-released' : ''} ${!s.album ? 'is-pool' : ''}" data-row="${s.id}" data-album="${s.album ? 1 : 0}">
        <td class="song-col"><div class="song-cell">${grip(s)}${playBtn(s)}<button class="song-btn" data-song="${s.id}">
          <span class="meta">${s.album ? String(s.nr).padStart(2, '0') : '—'}</span>
          <span class="song-title"><span class="label">${esc(s.title)}</span></span>
          <span class="pct">${p}%</span>
          <span></span><span class="bar"><i style="width:${p}%"></i></span>
          ${tags ? `<span></span><span class="tags">${tags}</span>` : ''}
        </button></div></td>${cells}</tr>`;
    };

    // Handy: eine Karte pro Song, Schritte nach Gruppen
    const card = (s) => {
      const p = pct(s), tags = songTags(s);
      const groups = Object.keys(S.groups).map((g) => {
        const steps = S.steps.filter((st) => st.g === g); if (!steps.length) return '';
        return `<div class="scard__grp"><span class="meta">${esc(S.groups[g])}</span><div class="scard__cells">${steps.map((st) => {
          const v = stateOf(s, st), mine = focus && isMine(st, focus) ? 'is-mine' : '';
          return `<button class="cell cell--mini ${cellCls(st, v)} ${mine}" data-cell="${s.id}|${esc(st.k)}" aria-label="${esc(st.l)}: ${label(st, v)}"><i></i><span>${esc(st.l)}</span></button>`;
        }).join('')}</div></div>`;
      }).join('');
      return `<article class="scard ${!s.album ? 'is-pool' : ''}" data-row="${s.id}" data-album="${s.album ? 1 : 0}">
        <header class="scard__head">${grip(s)}${playBtn(s)}
          <button class="scard__title" data-song="${s.id}"><span class="meta">${s.album ? String(s.nr).padStart(2, '0') : '—'}</span><span class="label">${esc(s.title)}</span></button>
          <b class="pct">${p}%</b></header>
        <span class="bar"><i style="width:${p}%"></i></span>
        ${tags ? `<span class="tags">${tags}</span>` : ''}
        <div class="scard__body">${groups}</div>
      </article>`;
    };

    const list = [...album()];
    if (sort === 'progress') list.sort((a, b) => pct(b) - pct(a)); else list.sort(byNr);
    const active = list.filter((s) => pct(s) < 100);
    const done = list.filter((s) => pct(s) === 100).sort(byNr);
    const pool = S.songs.filter((s) => !s.album);
    const cols = ordered.length + 1;
    const doneBtn = `<button class="sep__toggle" data-toggle-done aria-expanded="${showDone}"><span class="meta">${showDone ? '▾' : '▸'} Abgeschlossen · ${done.length} ${done.length === 1 ? 'Song' : 'Songs'} bei 100 %</span></button>`;
    const m = $('#matrix');
    m.classList.toggle('has-focus', !!focus);
    m.innerHTML = `<thead>${g1}${g2}</thead><tbody>${active.map(row).join('') || `<tr><td colspan="${cols}" class="leer">Alle Album-Songs sind fertig. 🤘</td></tr>`}
      ${done.length ? `<tr class="sep sep--done"><td colspan="${cols}">${doneBtn}</td></tr>${showDone ? done.map(row).join('') : ''}` : ''}
      ${pool.length ? `<tr class="sep"><td colspan="${cols}"><span class="meta">Song-Pool · nicht auf dem Album</span></td></tr>${pool.map(row).join('')}` : ''}</tbody>`;
    const c = $('#cards');
    c.classList.toggle('has-focus', !!focus);
    c.innerHTML = (active.map(card).join('') || '<p class="leer">Alle Album-Songs sind fertig. 🤘</p>')
      + (done.length ? `<div class="sep sep--done">${doneBtn}</div>` + (showDone ? done.map(card).join('') : '') : '')
      + (pool.length ? `<div class="sep"><span class="meta">Song-Pool · nicht auf dem Album</span></div>${pool.map(card).join('')}` : '');

    renderSessions();
    $('#weights').innerHTML = ordered.map((s) => `<li><span>${esc(s.l)}</span><span class="meta">×${esc(s.w)}</span></li>`).join('');
  }

  /* ================= STUDIO-SESSIONS & PLAN ================= */
  const planDone = (st, v) => v === -1 || v === 2 || (st.t === 'rec' && v === 1);
  const planItems = (e) => (e.plan || []).map((p) => ({ s: songById(p.s), st: stepByKey(p.k) })).filter((x) => x.s && x.st);
  function planSummary(items) {
    const by = {};
    items.forEach(({ s, st }) => { (by[st.l] = by[st.l] || []).push(s.title); });
    return Object.entries(by).map(([l, t]) => `${l}: ${t.join(', ')}`).join(' · ');
  }
  function planPicker(sel) {
    const html = [...album()].sort(byNr).map((s) => {
      const steps = S.steps.filter((st) => st.g !== 'post' && (isOpen(stateOf(s, st)) || sel.has(s.id + '|' + st.k)));
      if (!steps.length) return '';
      const n = steps.filter((st) => sel.has(s.id + '|' + st.k)).length;
      return `<details class="plan-song" ${n ? 'open' : ''}><summary><span class="label">${esc(s.title)}</span><span class="meta">${pct(s)}%<b class="plan-song__n">${n ? ' · ' + n + ' gewählt' : ''}</b></span></summary>
        <div class="who-pick">${steps.map((st) => `<label><input type="checkbox" value="${s.id}|${esc(st.k)}" ${sel.has(s.id + '|' + st.k) ? 'checked' : ''}><span>${esc(st.l)}</span></label>`).join('')}</div></details>`;
    }).join('');
    return html || '<p class="meta">Alles aufgenommen – nichts mehr zu planen.</p>';
  }
  const readPlan = (box) => $$('input:checked', box).map((i) => { const [sid, k] = i.value.split('|'); return { s: +sid, k }; });
  document.addEventListener('change', (e) => {
    const d = e.target.closest('.plan-song'); if (!d) return;
    const n = $$('input:checked', d).length;
    $('.plan-song__n', d).textContent = n ? ' · ' + n + ' gewählt' : '';
  });

  let planEdit = null; // Session, deren Plan gerade bearbeitet wird
  function renderSessions() {
    const sessions = S.events.filter((e) => e.type === 'studio');
    $('#sessions').innerHTML = sessions.length ? sessions.map((e) => {
      const items = planItems(e), ok = items.filter(({ s, st }) => planDone(st, stateOf(s, st))).length;
      const editing = planEdit === e.id;
      return `<li class="session">
        <span class="meta">${esc(fmt(e.start, { weekday: 'short', day: '2-digit', month: '2-digit' }))}</span>
        <span><span class="label">${esc(e.title)}</span><br><span class="meta">${esc(e.place || '')}${e.place ? ' · ' : ''}${fmtTime(e.start)}${items.length ? ` · ${ok}/${items.length} erledigt` : ''}</span></span>
        <span class="wer">${(e.who || []).map((w) => av(w)).join('')}</span>
        ${items.length && !editing ? `<div class="session__plan">${items.map(({ s, st }) => {
          const v = stateOf(s, st);
          return `<button class="plan-item ${planDone(st, v) ? 'is-ok' : ''}" data-cell="${s.id}|${esc(st.k)}" title="Klick = nächster Status (${label(st, v)})"><i class="cell-dot ${cellCls(st, v)}"></i><span class="label">${esc(st.l)}</span><span class="meta">${esc(s.title)}</span></button>`;
        }).join('')}</div>` : ''}
        ${editing ? `<div class="session__edit" data-plan-box="${e.id}">${planPicker(new Set((e.plan || []).map((p) => p.s + '|' + p.k)))}
          <div class="actions"><button class="btn btn--klein btn--patina" data-plan-save="${e.id}">Plan speichern</button><button class="btn btn--klein" data-plan-cancel>Abbrechen</button></div></div>`
          : `<button class="link meta session__edit-btn" data-plan-edit="${e.id}">${items.length ? '✎ Plan bearbeiten' : '＋ Songs & Schritte planen'}</button>`}
      </li>`;
    }).join('') : '<li class="leer">Noch keine Studio-Sessions geplant. Leg im Kalender eine an.</li>';
  }
  $('#sessions').addEventListener('click', async (e) => {
    const c = e.target.closest('[data-cell]');
    if (c) { const [sid, k] = c.dataset.cell.split('|'); setStep(songById(sid), stepByKey(k)); return; }
    const ed = e.target.closest('[data-plan-edit]');
    if (ed) { planEdit = +ed.dataset.planEdit; renderSessions(); return; }
    if (e.target.closest('[data-plan-cancel]')) { planEdit = null; renderSessions(); return; }
    const sv = e.target.closest('[data-plan-save]');
    if (sv) {
      const id = +sv.dataset.planSave, plan = readPlan($(`[data-plan-box="${id}"]`));
      try {
        const r = await api(`events/${id}/plan`, { method: 'POST', body: { plan } });
        const ev = S.events.find((x) => x.id === id); if (ev) ev.plan = r.plan;
        planEdit = null; renderSessions(); renderCal(); toast('Plan gespeichert');
      } catch (err) { toast(err.message); }
    }
  });

  // Statuswechsel: sofort anzeigen, aber erst nach einer kurzen Pause speichern.
  // So landet beim Durchklicken nur der Status im Log, bei dem man stehen bleibt.
  const STEP_DELAY = 1800;
  const pendingSteps = new Map(); // "songId|step" → { sid, step, before, v, log, timer }
  function applyPending(song) {
    pendingSteps.forEach((p) => { if (p.sid === song.id) song.st[p.step.k] = p.v; });
    return song;
  }
  function setStep(songIn, step, value) {
    const song = songIn && songById(songIn.id);
    if (!song || !step) return;
    const key = song.id + '|' + step.k;
    const cur = stateOf(song, step);
    const v = value === undefined ? nextOf(step, cur) : value;
    let p = pendingSteps.get(key);
    if (!p) { p = { sid: song.id, step, before: cur }; pendingSteps.set(key, p); busy++; }
    const wasDone = pct(song) === 100;
    p.v = v; song.st[step.k] = v;
    if (!wasDone && pct(song) === 100 && song.album) setTimeout(() => toast(`🎉 ${song.title} ist fertig – ab in „Abgeschlossen“`), 50);
    const txt = `${step.l} · ${song.title} → ${label(step, v)}`;
    if (p.log) { p.log.txt = txt; p.log.t = Date.now(); } else { p.log = { who: S.me.name, txt, t: Date.now() }; S.log.unshift(p.log); }
    renderAll();
    const c = $(`[data-cell="${key}"]`); if (c) c.classList.add('pop');
    clearTimeout(p.timer);
    p.timer = setTimeout(() => commitStep(key), STEP_DELAY);
  }
  async function commitStep(key, leaving) {
    const p = pendingSteps.get(key); if (!p) return;
    pendingSteps.delete(key); clearTimeout(p.timer);
    const dropLog = () => { const i = S.log.indexOf(p.log); if (i >= 0) S.log.splice(i, 1); };
    try {
      if (p.v === p.before) { dropLog(); if (!leaving) renderAll(); return; } // einmal rundum geklickt: nichts geändert
      const res = await api(`songs/${p.sid}/step`, { method: 'POST', body: { step: p.step.k, value: p.v }, keepalive: !!leaving });
      if (leaving) return;
      replaceSong(res); renderAll(); refreshHistory();
    } catch (err) {
      const s = songById(p.sid); if (s) s.st[p.step.k] = p.before;
      dropLog(); renderAll(); toast(err.message);
    } finally { busy--; }
  }
  // Beim Verlassen/Wegwechseln nichts verlieren
  const flushSteps = () => [...pendingSteps.keys()].forEach((k) => commitStep(k, true));
  addEventListener('pagehide', flushSteps);
  document.addEventListener('visibilitychange', () => { if (document.hidden) flushSteps(); });

  $('#studio-matrix').addEventListener('click', (e) => {
    if (e.target.closest('[data-grip]')) return;
    if (e.target.closest('[data-toggle-done]')) {
      showDone = !showDone;
      try { sessionStorage.setItem('glp-done', showDone ? '1' : '0'); } catch (err) {}
      renderStudio(); return;
    }
    const c = e.target.closest('[data-cell]');
    if (c) { const [sid, k] = c.dataset.cell.split('|'); setStep(songById(sid), stepByKey(k)); return; }
    const sb = e.target.closest('[data-song]'); if (sb) openDrawer(+sb.dataset.song);
  });
  $('#member-filter').addEventListener('click', (e) => { const c = e.target.closest('[data-member]'); if (!c) return; focus = c.dataset.member; renderStudio(); });
  $$('[data-sort]').forEach((c) => c.addEventListener('click', () => {
    sort = c.dataset.sort; $$('[data-sort]').forEach((x) => x.classList.toggle('is-on', x === c)); renderStudio();
  }));
  /* Tracklist sortieren: am ⋮⋮ ziehen (Maus und Touch, Tabelle und Karten) */
  let drag = null;
  document.addEventListener('pointerdown', (e) => {
    const g = e.target.closest('[data-grip]'); if (!g) return;
    e.preventDefault();
    const row = g.closest('[data-row]');
    drag = { row, box: row.parentElement, id: +g.dataset.grip, moved: false, pid: e.pointerId };
    row.classList.add('is-drag'); document.body.classList.add('is-sorting');
    try { g.setPointerCapture(e.pointerId); } catch (err) {}
  });
  document.addEventListener('pointermove', (e) => {
    if (!drag || e.pointerId !== drag.pid) return;
    e.preventDefault();
    if (e.clientY < 70) scrollBy({ top: -14, behavior: 'instant' }); else if (e.clientY > innerHeight - 70) scrollBy({ top: 14, behavior: 'instant' });
    const el = document.elementFromPoint(e.clientX, e.clientY);
    const over = el && el.closest('[data-row]');
    if (!over || over === drag.row || over.parentElement !== drag.box || over.dataset.album !== '1') return;
    const r = over.getBoundingClientRect();
    over[e.clientY > r.top + r.height / 2 ? 'after' : 'before'](drag.row);
    drag.moved = true;
  }, { passive: false });
  const endDrag = async () => {
    if (!drag) return;
    const d = drag; drag = null;
    d.row.classList.remove('is-drag'); document.body.classList.remove('is-sorting');
    if (!d.moved) return;
    // Neue Position aus den Nachbarn ableiten, damit ausgeblendete (abgeschlossene) Songs ihren Platz behalten
    const sib = (dir) => { let n = d.row[dir]; while (n && !(n.dataset && n.dataset.album === '1' && n.dataset.row)) n = n[dir]; return n ? +n.dataset.row : 0; };
    const next = sib('nextElementSibling'), prev = sib('previousElementSibling');
    const ids = [...album()].sort(byNr).map((s) => s.id).filter((id) => id !== d.id);
    const at = next ? ids.indexOf(next) : prev ? ids.indexOf(prev) + 1 : ids.length;
    ids.splice(at < 0 ? ids.length : at, 0, d.id);
    ids.forEach((id, i) => { const s = songById(id); if (s) s.nr = i + 1; });
    renderAll();
    try {
      const r = await api('songs/order', { method: 'POST', body: { ids } });
      S.songs = r.songs.map(normSong); renderAll(); toast('Reihenfolge gespeichert');
    } catch (err) { toast(err.message); load().catch(() => {}); }
  };
  document.addEventListener('pointerup', endDrag);
  document.addEventListener('pointercancel', endDrag);

  $('#add-song').addEventListener('submit', async (e) => {
    e.preventDefault();
    const inp = $('#new-song'), title = inp.value.trim();
    if (!title) { inp.focus(); return; }
    try { const s = await api('songs', { method: 'POST', body: { title } }); replaceSong(s); inp.value = ''; localLog('Neuer Song angelegt: ' + s.title); renderAll(); toast('Song angelegt'); }
    catch (err) { toast(err.message); }
  });

  /* ================= MEINE TO-DOS ================= */
  let studioView = 'matrix', todoWho = '', todoFilter = 'alle';
  try { studioView = sessionStorage.getItem('glp-studio') || 'matrix'; } catch (e) {}

  function setStudioView(v) {
    studioView = v;
    $$('[data-studio]').forEach((b) => { const on = b.dataset.studio === v; b.classList.toggle('is-on', on); b.setAttribute('aria-selected', on); });
    $('#studio-matrix').hidden = v !== 'matrix';
    $('#studio-todos').hidden = v !== 'todos';
    try { sessionStorage.setItem('glp-studio', v); } catch (e) {}
  }
  $$('[data-studio]').forEach((b) => b.addEventListener('click', () => setStudioView(b.dataset.studio)));

  // Welche Aktionen für einen offenen Schritt sinnvoll sind
  function todoActions(st, v) {
    if (st.t === 'done') return v === 0 ? [[3, '〰 In Arbeit'], [2, '✓ Fertig']] : [[2, '✓ Fertig']];
    if (st.t === 'rec') {
      if (v === 0) return [[3, '〰 In Arbeit'], [1, '✓ Aufgenommen']];
      if (v === 3) return [[1, '✓ Aufgenommen'], [2, '✓ Editiert']];
      return [[2, '✓ Editiert']];
    }
    return v === 0 ? [[1, '〰 In Arbeit'], [2, '✓ Fertig']] : [[2, '✓ Fertig']];
  }
  const todoState = (st, v) => {
    if (isWip(st, v)) return 'in Arbeit';
    if (v === 1) return 'aufgenommen · Edit fehlt';
    return st.t === 'rec' ? 'noch aufnehmen' : 'offen';
  };

  function renderTodos() {
    const who = todoWho || S.me.name;
    // Zähler am Reiter: meine offenen Schritte
    const mine = album().reduce((n, s) => n + S.steps.filter((st) => isMine(st, S.me.name) && isOpen(stateOf(s, st))).length, 0);
    $('#todo-n').textContent = mine ? mine : '';

    $('#todo-who').innerHTML = '<span class="meta">Für:</span>' + S.members.map((m) =>
      `<button class="chip ${m.name === who ? 'is-on' : ''}" data-todo-who="${esc(m.name)}">${m.id === S.me.id ? 'Mich' : esc(m.name)}</button>`).join('');

    const assigned = S.steps.filter((st) => isMine(st, who));
    if (!assigned.length) {
      $('#todo-sum').innerHTML = '';
      $('#todo-songs').innerHTML = `<p class="leer">Für ${esc(who)} sind noch keine Arbeitsschritte eingetragen.${S.me.admin ? ' Zuständigkeiten stellst du unter Einstellungen → GHOSTLIKE Portal ein.' : ' Zuständigkeiten stellt ein Admin ein.'}</p>`;
      return;
    }

    // Persönlicher Fortschritt (gewichtet wie im Studio)
    let w = 0, got = 0, open0 = 0, wip = 0, edit = 0;
    const songs = [];
    album().forEach((s) => {
      const items = [];
      assigned.forEach((st) => {
        const v = stateOf(s, st);
        if (v === -1) return;
        w += +st.w; got += +st.w * val(st, v);
        const isEdit = st.t === 'rec' && v === 1;
        if (v === 0) open0++;
        if (isWip(st, v)) wip++;
        if (isEdit) edit++;
        if (isOpen(v) && (todoFilter === 'alle' || (todoFilter === 'rec' && v === 0) || (todoFilter === 'wip' && isWip(st, v)) || (todoFilter === 'edit' && isEdit))) items.push({ st, v });
      });
      if (items.length) songs.push({ s, items, p: pct(s) });
    });
    songs.sort((a, b) => b.p - a.p || a.s.nr - b.s.nr); // fast fertige Songs zuerst
    const mp = w ? Math.round((got / w) * 100) : 100;

    $('#todo-sum').innerHTML = `
      <div class="todo-sum__big"><span class="meta">${who === S.me.name ? 'Dein' : esc(who) + 's'} Anteil am Album</span><b>${mp}%</b><div class="bar"><i style="width:${mp}%"></i></div></div>
      <div class="kpi"><span class="meta">Noch offen</span><b>${open0}</b></div>
      <div class="kpi"><span class="meta">In Arbeit</span><b>${wip}</b></div>
      <div class="kpi"><span class="meta">Edit fehlt</span><b>${edit}</b></div>
      <div class="kpi"><span class="meta">Songs betroffen</span><b>${songs.length}</b></div>`;

    $('#todo-songs').innerHTML = songs.length ? songs.map(({ s, items, p }) => `
      <article class="todo-song">
        <header class="todo-song__head">
          ${playBtn(s) || `<span class="meta">${s.album ? String(s.nr).padStart(2, '0') : '—'}</span>`}
          <button class="label link" data-song="${s.id}">${esc(s.title)}</button>
          <b class="pct">${p}%</b>
          <span class="bar"><i style="width:${p}%"></i></span>
        </header>
        <ul class="todo-song__list">
          ${items.map(({ st, v }) => `
            <li class="${isWip(st, v) ? 'is-wip' : v === 1 ? 'is-halb' : ''}">
              <span><span class="label">${esc(st.l)}</span><span class="meta">${todoState(st, v)}</span></span>
              <span class="todo-song__btns">
                ${todoActions(st, v).map(([nv, l], i) => `<button class="btn btn--klein ${i === 0 ? 'btn--patina' : ''}" data-todo-set="${s.id}|${esc(st.k)}|${nv}">${l}</button>`).join('')}
                <button class="link meta" data-todo-set="${s.id}|${esc(st.k)}|-1" title="Wird für diesen Song nicht gebraucht">nicht nötig</button>
              </span>
            </li>`).join('')}
        </ul>
      </article>`).join('') : `<p class="leer">${todoFilter === 'alle' ? 'Nichts offen. Stark! 🤘' : 'In dieser Auswahl ist nichts offen.'}</p>`;
  }

  $('#studio-todos').addEventListener('click', (e) => {
    const w = e.target.closest('[data-todo-who]');
    if (w) { todoWho = w.dataset.todoWho; renderTodos(); return; }
    const f = e.target.closest('[data-todo-filter]');
    if (f) { todoFilter = f.dataset.todoFilter; $$('[data-todo-filter]').forEach((x) => x.classList.toggle('is-on', x === f)); renderTodos(); return; }
    const t = e.target.closest('[data-todo-set]');
    if (t) {
      const [sid, k, v] = t.dataset.todoSet.split('|');
      setStep(songById(sid), stepByKey(k), +v);
      return;
    }
    const sb = e.target.closest('[data-song]');
    if (sb) openDrawer(+sb.dataset.song);
  });

  /* ================= AUDIO: PLAYER & VERSIONEN ================= */
  const versionsOf = (sid) => (S.audio && S.audio[sid]) || [];
  const fmtDur = (t) => (isFinite(t) ? `${Math.floor(t / 60)}:${String(Math.floor(t % 60)).padStart(2, '0')}` : '0:00');
  const fmtSize = (b) => (b > 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.round(b / 1024) + ' KB');
  const audioSrc = (id, dl) => { const u = new URL(S.audioUrl, location.href); u.searchParams.set('audio', id); if (dl) u.searchParams.set('download', '1'); return u.toString(); };
  let playing = null; // { id, song }

  function playBtn(s) {
    const v = versionsOf(s.id)[0];
    if (!v) return '';
    const on = playing && playing.id === v.id && !$('#pl-audio').paused;
    return `<button class="play ${on ? 'is-on' : ''}" data-play="${v.id}" data-song-id="${s.id}" title="v${v.v}${v.note ? ' · ' + esc(v.note) : ''} abspielen" aria-label="${esc(s.title)} v${v.v} abspielen">${on ? '❚❚' : '▶'}</button>`;
  }

  const pl = { box: $('#player'), audio: $('#pl-audio'), toggle: $('#pl-toggle'), seek: $('#pl-seek') };
  function syncPlayButtons() {
    const paused = pl.audio.paused;
    pl.toggle.textContent = paused ? '▶' : '❚❚';
    $$('[data-play]').forEach((b) => { const on = playing && +b.dataset.play === playing.id && !paused; b.classList.toggle('is-on', on); b.textContent = on ? '❚❚' : '▶'; });
  }
  function play(versionId, songId) {
    const s = songById(songId); const v = versionsOf(songId).find((x) => x.id === +versionId);
    if (!s || !v) return;
    if (playing && playing.id === v.id) { pl.audio.paused ? pl.audio.play() : pl.audio.pause(); return; }
    playing = { id: v.id, song: s.id };
    pl.audio.src = audioSrc(v.id);
    $('#pl-title').textContent = s.title;
    $('#pl-sub').textContent = `v${v.v}${v.note ? ' · ' + v.note : ''} · ${v.by}`;
    pl.box.hidden = false; document.body.classList.add('has-player');
    pl.audio.play().catch(() => toast('Abspielen nicht möglich'));
    if ('mediaSession' in navigator) {
      navigator.mediaSession.metadata = new MediaMetadata({ title: `${s.title} (v${v.v})`, artist: 'GHOSTLIKE', album: v.note || 'Studio' });
      navigator.mediaSession.setActionHandler('play', () => pl.audio.play());
      navigator.mediaSession.setActionHandler('pause', () => pl.audio.pause());
      navigator.mediaSession.setActionHandler('seekto', (d) => { pl.audio.currentTime = d.seekTime; });
    }
  }
  pl.toggle.addEventListener('click', () => { if (!playing) return; pl.audio.paused ? pl.audio.play() : pl.audio.pause(); });
  $('#pl-close').addEventListener('click', () => { pl.audio.pause(); pl.audio.removeAttribute('src'); pl.audio.load(); playing = null; pl.box.hidden = true; document.body.classList.remove('has-player'); syncPlayButtons(); });
  ['play', 'pause', 'ended'].forEach((ev) => pl.audio.addEventListener(ev, syncPlayButtons));
  pl.audio.addEventListener('timeupdate', () => {
    const d = pl.audio.duration;
    if (isFinite(d) && d > 0 && !pl.seek.matches(':active')) pl.seek.value = Math.round((pl.audio.currentTime / d) * 1000);
    $('#pl-time').textContent = `${fmtDur(pl.audio.currentTime)} / ${fmtDur(d)}`;
  });
  pl.seek.addEventListener('input', () => { const d = pl.audio.duration; if (isFinite(d)) pl.audio.currentTime = (pl.seek.value / 1000) * d; });
  pl.audio.addEventListener('error', () => { if (pl.audio.getAttribute('src')) toast('Datei konnte nicht geladen werden'); });
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-play]'); if (!b) return;
    e.preventDefault(); e.stopPropagation();
    play(+b.dataset.play, +b.dataset.songId);
  }, true);

  function renderVersions() {
    const s = songById(curId); if (!s) return;
    const list = versionsOf(s.id);
    // Hinweis, wenn die aktuelle Datei ein anderes Tempo hat als eingetragen
    const fileBpm = list[0] && list[0].bpm, songBpm = String(s.meta.bpm || '').trim();
    const hint = $('#d-bpmhint');
    hint.hidden = !(fileBpm && songBpm && parseFloat(fileBpm) !== parseFloat(songBpm.replace(',', '.')));
    if (!hint.hidden) hint.innerHTML = `<span class="meta">In v${list[0].v} steht <b>${esc(fileBpm)} BPM</b> (eingetragen: ${esc(songBpm)})</span> <button class="btn btn--klein" data-bpm-apply="${esc(fileBpm)}">Übernehmen</button>`;
    $('#d-max').textContent = `max. ${fmtSize(S.maxUpload)} pro Datei`;
    $('#d-vlist').innerHTML = list.length ? list.map((v, i) => `
      <li class="${i === 0 ? 'is-aktuell' : ''}">
        <button class="play ${playing && playing.id === v.id && !pl.audio.paused ? 'is-on' : ''}" data-play="${v.id}" data-song-id="${s.id}" aria-label="v${v.v} abspielen">${playing && playing.id === v.id && !pl.audio.paused ? '❚❚' : '▶'}</button>
        <span class="versions__info"><span class="label">v${v.v}${i === 0 ? ' <span class="tag tag--patina">aktuell</span>' : ''}${v.note ? ' · ' + esc(v.note) : ''}</span>
        <span class="meta">${esc(fmt(v.date, { day: '2-digit', month: '2-digit', year: '2-digit' }))} · ${esc(v.by)}${v.len ? ' · ' + fmtDur(v.len) : ''}${v.bpm ? ' · ' + esc(v.bpm) + ' BPM' : ''} · ${fmtSize(v.size)}</span></span>
        <span class="versions__tools"><a class="link meta" href="${esc(audioSrc(v.id, true))}" title="Herunterladen">↓</a>${v.by === S.me.name || S.me.admin ? `<button class="link meta" data-del-version="${v.id}" title="Version löschen">✕</button>` : ''}</span>
      </li>`).join('') : '<li class="leer">Noch keine Version. Lad die erste hoch.</li>';
  }

  function uploadVersion(file) {
    const s = songById(curId); if (!s || !file) return;
    if (!/\.(mp3|m4a|aac|wav)$/i.test(file.name)) { toast('Bitte eine MP3 (oder M4A/WAV) wählen'); return; }
    if (S.maxUpload && file.size > S.maxUpload) { toast(`Zu groß: max. ${fmtSize(S.maxUpload)}. Tipp: als MP3 exportieren.`); return; }
    const bar = $('#d-upbar'), fd = new FormData();
    fd.append('file', file); fd.append('note', $('#d-vnote').value.trim());
    const xhr = new XMLHttpRequest();
    xhr.open('POST', CFG.rest + `songs/${s.id}/audio`);
    xhr.setRequestHeader('X-WP-Nonce', CFG.nonce);
    busy++; bar.hidden = false; $('i', bar).style.width = '0%';
    xhr.upload.onprogress = (e) => { if (e.lengthComputable) $('i', bar).style.width = Math.round((e.loaded / e.total) * 100) + '%'; };
    xhr.onload = () => {
      busy--; bar.hidden = true;
      let json = {}; try { json = JSON.parse(xhr.responseText); } catch (err) {}
      if (xhr.status >= 200 && xhr.status < 300) {
        S.audio[s.id] = json.versions || [];
        if (json.song) { replaceSong(json.song); if (json.bpm_applied) $('#d-m-bpm').value = json.song.meta.bpm || ''; }
        $('#d-vnote').value = ''; $('#d-file').value = '';
        localLog(`${s.title} v${S.audio[s.id][0] ? S.audio[s.id][0].v : 1} hochgeladen`);
        renderAll(); renderVersions();
        toast(json.bpm_applied ? `Hochgeladen ✓ · ${json.bpm} BPM aus der Datei eingetragen` : json.bpm ? `Hochgeladen ✓ · Datei: ${json.bpm} BPM` : 'Hochgeladen ✓');
      } else toast(json.message || 'Upload fehlgeschlagen');
    };
    xhr.onerror = () => { busy--; bar.hidden = true; toast('Keine Verbindung, bitte erneut versuchen'); };
    xhr.send(fd);
  }
  $('#d-file').addEventListener('change', (e) => uploadVersion(e.target.files[0]));
  const drop = $('#d-drop');
  ['dragenter', 'dragover'].forEach((t) => drop.addEventListener(t, (e) => { e.preventDefault(); drop.classList.add('is-over'); }));
  ['dragleave', 'drop'].forEach((t) => drop.addEventListener(t, (e) => { e.preventDefault(); drop.classList.remove('is-over'); }));
  drop.addEventListener('drop', (e) => uploadVersion(e.dataTransfer.files[0]));
  $('#d-bpmhint').addEventListener('click', (e) => {
    const b = e.target.closest('[data-bpm-apply]'); if (!b) return;
    $('#d-m-bpm').value = b.dataset.bpmApply;
    queueSave(true);
    $('#d-bpmhint').hidden = true;
    toast(`BPM auf ${b.dataset.bpmApply} gesetzt`);
  });
  $('#d-vlist').addEventListener('click', async (e) => {
    const d = e.target.closest('[data-del-version]'); if (!d) return;
    if (!confirm('Diese Version endgültig löschen?')) return;
    try { const r = await api('audio/' + d.dataset.delVersion, { method: 'DELETE' }); S.audio[curId] = r.versions || []; renderAll(); renderVersions(); toast('Version gelöscht'); }
    catch (err) { toast(err.message); }
  });

  /* ================= HISTORIE ================= */
  const H = { song: '', user: '', cursor: 0, items: [] };
  const dayLabel = (t) => {
    const d = new Date(t), today = new Date(); today.setHours(0, 0, 0, 0);
    const diff = Math.round((today - new Date(d.getFullYear(), d.getMonth(), d.getDate())) / 864e5);
    return diff === 0 ? 'Heute' : diff === 1 ? 'Gestern' : fmt(t, { weekday: 'short', day: '2-digit', month: '2-digit', year: '2-digit' });
  };
  function histRows(items, withSong) {
    let day = '';
    return items.map((h) => {
      const st = stepByKey(h.step); if (!st) return '';
      const d = dayLabel(h.t), head = d !== day ? `<li class="history__day meta">${esc(d)}</li>` : ''; day = d;
      const from = h.old === null ? '' : `<i class="cell-dot ${cellCls(st, h.old)}" title="${esc(label(st, h.old))}"></i><span class="history__arrow">→</span>`;
      return `${head}<li class="history__item">
        <span class="meta history__time">${esc(fmtTime(h.t))}</span>
        ${av(h.who)}
        <span class="history__what">${withSong ? `<button class="link label" data-song="${h.song}">${esc(h.title)}</button> · ` : ''}<span class="label">${esc(st.l)}</span></span>
        <span class="history__change">${from}<i class="cell-dot ${cellCls(st, h.new)}"></i><span class="meta">${esc(label(st, h.new))}</span></span>
      </li>`;
    }).join('');
  }
  async function loadHistory(reset) {
    if (reset) { H.cursor = 0; H.items = []; }
    const q = new URLSearchParams({ limit: 60 });
    if (H.song) q.set('song', H.song);
    if (H.user) q.set('user', H.user);
    if (H.cursor) q.set('before', H.cursor);
    try {
      const r = await api('history?' + q);
      H.items = H.items.concat(r.items);
      H.cursor = r.items.length ? r.items[r.items.length - 1].id : H.cursor;
      $('#h-list').innerHTML = histRows(H.items, true) || '<li class="leer">Noch keine Änderungen in dieser Auswahl.</li>';
      $('#h-more').hidden = !r.more;
    } catch (err) { toast(err.message); }
  }
  function fillHistoryFilters() {
    const sel = $('#h-song'), cur = sel.value;
    sel.innerHTML = '<option value="">Alle Songs</option>' + [...S.songs].sort(byNr).map((s) => `<option value="${s.id}">${esc((s.album ? String(s.nr).padStart(2, '0') + ' · ' : '') + s.title)}</option>`).join('');
    sel.value = cur;
    $('#h-who').innerHTML = '<span class="meta">Wer:</span>' + [{ id: '', name: 'Alle' }].concat(S.members).map((m) => `<button type="button" class="chip ${String(H.user) === String(m.id) ? 'is-on' : ''}" data-h-user="${m.id}">${esc(m.name)}</button>`).join('');
  }
  $('#history').addEventListener('toggle', () => { if ($('#history').open) { fillHistoryFilters(); loadHistory(true); } });
  $('#h-song').addEventListener('change', (e) => { H.song = e.target.value; loadHistory(true); });
  $('#h-who').addEventListener('click', (e) => { const b = e.target.closest('[data-h-user]'); if (!b) return; H.user = b.dataset.hUser; fillHistoryFilters(); loadHistory(true); });
  $('#h-more').addEventListener('click', () => loadHistory(false));
  $('#h-list').addEventListener('click', (e) => { const b = e.target.closest('[data-song]'); if (b) openDrawer(+b.dataset.song); });
  async function loadSongHistory() {
    const s = songById(curId); if (!s || !$('#d-history').open) return;
    try { const r = await api(`history?song=${s.id}&limit=100`); $('#d-hlist').innerHTML = histRows(r.items, false) || '<li class="leer">Noch keine Änderungen.</li>'; }
    catch (err) { $('#d-hlist').innerHTML = `<li class="leer">${esc(err.message)}</li>`; }
  }
  $('#d-history').addEventListener('toggle', loadSongHistory);
  let histTimer = null;
  const refreshHistory = () => { clearTimeout(histTimer); histTimer = setTimeout(() => { if ($('#history').open) loadHistory(true); loadSongHistory(); }, 600); };

  /* ================= DATEIEN (Tabs, Noten …) ================= */
  const filesOf = (sid) => (S.files && S.files[sid]) || [];
  const inlineExt = /^(pdf|png|jpe?g|txt)$/;
  function renderFiles() {
    const s = songById(curId); if (!s) return;
    const list = filesOf(s.id);
    $('#d-flist').innerHTML = list.length ? list.map((f) => `
      <li><span class="ficon">${esc(f.ext.toUpperCase())}</span>
        <span class="versions__info"><a class="label link" href="${esc(audioSrc(f.id, !inlineExt.test(f.ext)))}" ${inlineExt.test(f.ext) ? 'target="_blank" rel="noopener"' : ''}>${esc(f.name)}</a>
        <span class="meta">${esc(fmt(f.date, { day: '2-digit', month: '2-digit', year: '2-digit' }))} · ${esc(f.by)} · ${fmtSize(f.size)}</span></span>
        <span class="versions__tools"><a class="link meta" href="${esc(audioSrc(f.id, true))}" title="Herunterladen">↓</a>${f.by === S.me.name || S.me.admin ? `<button class="link meta" data-del-file="${f.id}" title="Datei löschen">✕</button>` : ''}</span>
      </li>`).join('') : '<li class="leer">Noch keine Dateien. Tabs, Noten oder MIDI hier ablegen.</li>';
  }
  function uploadFile(file) {
    const s = songById(curId); if (!s || !file) return;
    if (!/\.(gp|gp3|gp4|gp5|gpx|mscz|midi?|pdf|txt|png|jpe?g)$/i.test(file.name)) { toast('Erlaubt: Guitar Pro, MuseScore, MIDI, PDF, TXT, Bilder'); return; }
    if (S.maxUpload && file.size > S.maxUpload) { toast(`Zu groß: max. ${fmtSize(S.maxUpload)}`); return; }
    const bar = $('#d-fupbar'), fd = new FormData(); fd.append('file', file);
    const xhr = new XMLHttpRequest();
    xhr.open('POST', CFG.rest + `songs/${s.id}/files`); xhr.setRequestHeader('X-WP-Nonce', CFG.nonce);
    busy++; bar.hidden = false; $('i', bar).style.width = '0%';
    xhr.upload.onprogress = (e) => { if (e.lengthComputable) $('i', bar).style.width = Math.round((e.loaded / e.total) * 100) + '%'; };
    xhr.onload = () => {
      busy--; bar.hidden = true; $('#d-ffile').value = '';
      let json = {}; try { json = JSON.parse(xhr.responseText); } catch (err) {}
      if (xhr.status >= 200 && xhr.status < 300) { S.files[s.id] = json.files || []; localLog(`Datei zu ${s.title}: ${file.name}`); renderFiles(); renderHome(); toast('Datei hochgeladen ✓'); }
      else toast(json.message || 'Upload fehlgeschlagen');
    };
    xhr.onerror = () => { busy--; bar.hidden = true; toast('Keine Verbindung, bitte erneut versuchen'); };
    xhr.send(fd);
  }
  $('#d-ffile').addEventListener('change', (e) => uploadFile(e.target.files[0]));
  const fdrop = $('#d-fdrop');
  ['dragenter', 'dragover'].forEach((t) => fdrop.addEventListener(t, (e) => { e.preventDefault(); fdrop.classList.add('is-over'); }));
  ['dragleave', 'drop'].forEach((t) => fdrop.addEventListener(t, (e) => { e.preventDefault(); fdrop.classList.remove('is-over'); }));
  fdrop.addEventListener('drop', (e) => uploadFile(e.dataTransfer.files[0]));
  $('#d-flist').addEventListener('click', async (e) => {
    const d = e.target.closest('[data-del-file]'); if (!d) return;
    if (!confirm('Diese Datei endgültig löschen?')) return;
    try { const r = await api('audio/' + d.dataset.delFile, { method: 'DELETE' }); S.files[curId] = r.files || []; renderFiles(); toast('Datei gelöscht'); }
    catch (err) { toast(err.message); }
  });

  /* ================= RELEASE-CHECKLISTE ================= */
  function renderRelease(inputsToo) {
    const s = songById(curId); if (!s) return;
    const box = $('#d-release'), on = showRelease(s), was = !box.hidden;
    box.hidden = !on;
    $('#d-rel-n').textContent = `${relCount(s)}/${relItems().length}`;
    if (!on || (!inputsToo && was)) return;
    const r = relOf(s);
    $('#d-rel-list').innerHTML = relItems().map((it) => it.t === 'check'
      ? `<li><label class="check"><input type="checkbox" data-rel="${esc(it.k)}" ${r[it.k] ? 'checked' : ''}><span>${esc(it.l)}</span></label></li>`
      : `<li><label class="feld"><span class="meta">${esc(it.l)}</span><input data-rel="${esc(it.k)}" type="${it.t === 'date' ? 'date' : 'text'}" value="${esc(r[it.k] || '')}" placeholder="${esc(it.ph || '')}"></label></li>`).join('');
  }

  /* ================= SONG-DETAIL ================= */
  const drawer = $('#drawer');
  function openDrawer(id) {
    curId = id; const s = songById(id); if (!s) return;
    drawer.hidden = false; document.body.style.overflow = 'hidden';
    $('#d-history').open = false; $('#d-hlist').innerHTML = '';
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
    renderVersions(); renderFiles(); renderRelease(inputsToo);
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
    if (!$('#d-release').hidden) body.meta.rel = Object.fromEntries($$('[data-rel]', drawer).map((i) => [i.dataset.rel, i.type === 'checkbox' ? i.checked : i.value]));
    try { replaceSong(await api('songs/' + s.id, { method: 'POST', body })); $('#d-saved').textContent = 'Gespeichert ✓'; fillDrawer(false); renderStudio(); renderHome(); }
    catch (err) { $('#d-saved').textContent = err.message; }
  }
  drawer.addEventListener('input', (e) => {
    if (e.target.matches('[data-meta], #d-f-title, #d-f-nr, [data-rel]:not([type=checkbox])')) queueSave(false);
  });
  drawer.addEventListener('change', (e) => {
    if (e.target.matches('[data-flag], #d-f-album, #d-f-released, [data-rel]')) queueSave(true);
  });
  $('#d-delete').addEventListener('click', async () => {
    const s = songById(curId); if (!s) return;
    if (!confirm(`„${s.title}“ wirklich löschen? Der ganze Aufnahmestand des Songs geht verloren.`)) return;
    try { await api('songs/' + s.id, { method: 'DELETE' }); S.songs = S.songs.filter((x) => x.id !== s.id); closeDrawer(); renderAll(); toast('Song gelöscht'); }
    catch (err) { toast(err.message); }
  });

  /* ================= BANDKASSE ================= */
  // Bewusst schlicht: dein Stand, deine offenen Beträge, Ausgabe oder Einnahme eintragen.
  const K = { items: [], debts: [], cash: 0, balances: {}, categories: {}, kind: 'out', editing: null, shown: [], loaded: false };
  const euro = (c) => (c / 100).toLocaleString('de-DE', { style: 'currency', currency: 'EUR' });
  const parseEuro = (s) => { const v = parseFloat(String(s).replace(/\s|€/g, '').replace(/\.(?=\d{3}(\D|$))/g, '').replace(',', '.')); return isFinite(v) ? Math.round(v * 100) : 0; };
  const who = (id) => (id ? ((S.members.find((m) => m.id === id) || {}).name || 'Ehemalig') : 'Bandkasse');
  const whoDu = (id) => (id === S.me.id ? 'Du' : who(id));
  const member = (id) => S.members.find((m) => m.id === id) || {};
  const whoAv = (id) => (id ? av(who(id)) : '<span class="avatar avatar--klein avatar--kasse" title="Bandkasse">€</span>');
  const paypalUrl = (id, cents) => { const pp = member(id).paypal; return pp ? `https://www.paypal.me/${encodeURIComponent(pp)}/${(cents / 100).toFixed(2)}EUR` : ''; };
  const short = (d) => fmt(d, { day: '2-digit', month: '2-digit' });
  const PP_LOGO = '<svg class="pp-logo" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M7.016 19.198h-4.2a.562.562 0 0 1-.555-.65L5.093.584A.692.692 0 0 1 5.776 0h7.222c3.417 0 5.904 2.488 5.846 5.5-.006.25-.027.5-.066.747A6.794 6.794 0 0 1 12.071 12H8.743a.69.69 0 0 0-.682.583l-.325 2.056-.013.083-.692 4.39-.015.087zM19.79 6.142c-.01.087-.01.175-.023.261a7.76 7.76 0 0 1-7.695 6.598H9.007l-.283 1.795-.013.083-.692 4.39-.134.843-.014.088H6.86l-.497 3.15a.562.562 0 0 0 .555.65h3.612c.34 0 .63-.249.683-.585l.952-6.031a.692.692 0 0 1 .683-.584h2.126a6.793 6.793 0 0 0 6.707-5.752c.306-1.95-.466-3.744-1.89-4.906z"/></svg>';

  async function loadCash() {
    try {
      const r = await api('cash');
      Object.assign(K, { items: r.items, debts: r.debts || [], cash: r.cash, balances: r.balances || {}, categories: r.categories, loaded: true });
      renderCash();
    } catch (err) { toast(err.message); }
  }

  const canConfirm = (b) => b.kind === 'move' && b.status === 'pending' && (b.to ? b.to === S.me.id : b.from !== S.me.id);
  const pendingFor = (from, to) => K.items.filter((b) => b.kind === 'move' && b.status === 'pending' && b.from === from && b.to === to);

  function renderCash() {
    const me = S.me.id;
    const pay = K.debts.filter((d) => d.debtor === me), get = K.debts.filter((d) => d.creditor === me);
    // Bandgeld, das jemand noch bei sich hat, ist keine persönliche Schuld – getrennt zeigen
    const held = K.debts.filter((d) => d.creditor === 0);
    const personal = pay.filter((d) => d.creditor !== 0);
    const net = get.reduce((a, d) => a + d.amount, 0) - personal.reduce((a, d) => a + d.amount, 0);
    const myHeld = held.filter((d) => d.debtor === me).reduce((a, d) => a + d.amount, 0);
    $('#k-satz').innerHTML = (!personal.length && !get.length ? 'Du bist mit allen quitt ✓'
      : net > 0 ? `Du bekommst <b class="is-plus">${euro(net)}</b>` : net < 0 ? `Du schuldest <b class="is-minus">${euro(-net)}</b>` : 'Unterm Strich ausgeglichen')
      + (myHeld ? `<span class="k-satz__sub">+ ${euro(myHeld)} Bandgeld bei dir, das in die Kasse gehört</span>` : '');
    const kasseUsed = K.items.some((b) => b.kind === 'in' || b.kind === 'fee' || (b.kind === 'out' && !b.from) || (b.kind === 'move' && (!b.from || !b.to)));
    const heldTxt = held.length ? ` · dazu noch ${held.map((d) => `${euro(d.amount)} bei ${d.debtor === me ? 'dir' : esc(who(d.debtor))}`).join(', ')}` : '';
    $('#k-kasse').innerHTML = kasseUsed ? `In der Bandkasse: <b class="${K.cash < 0 ? 'is-minus' : ''}">${euro(K.cash)}</b>${heldTxt}` : 'Gagen und Merch-Einnahmen trägst du über „+ Einnahme“ ein, dann siehst du hier den Kassenstand.';

    // Gemeldete Zahlungen, die ich bestätigen soll
    const toConfirm = K.items.filter(canConfirm);
    const conf = $('#k-confirm');
    conf.hidden = !toConfirm.length;
    conf.innerHTML = toConfirm.length ? `<span class="meta">Bitte bestätigen</span>` + toConfirm.map((b) => `
      <div class="k-confirm__row">${whoAv(b.from)}<span><b>${esc(who(b.from))}</b> hat ${b.to ? 'dir' : 'der Bandkasse'} <b>${euro(b.amount)}</b>${b.note ? ' per ' + esc(b.note) : ''} gezahlt<span class="meta meta--hell"> · ${esc(short(b.date))}</span></span>
        <span class="k-confirm__btns"><button type="button" class="btn btn--klein btn--patina" data-k-confirm="${b.id}" data-ok="1">✓ Ist angekommen</button><button type="button" class="btn btn--klein" data-k-confirm="${b.id}" data-ok="0">Nein</button></span></div>`).join('') : '';

    K.shown = [];
    $('#k-mine').innerHTML = pay.map((d) => row(d, 'pay')).concat(get.map((d) => row(d, 'get'))).join('') || '<li class="leer">Nichts offen. 🤘</li>';
    const others = K.debts.filter((d) => d.debtor !== me && d.creditor !== me);
    $('#k-others-n').textContent = others.length ? `(${others.length})` : '';
    $('#k-others').innerHTML = others.map((d) => row(d, 'other')).join('') || '<li class="leer">Sonst ist niemand etwas schuldig.</li>';

    const tipp = $('#k-tipp');
    tipp.hidden = !!S.me.paypal || !get.length;
    tipp.innerHTML = 'Tipp: Trag unter <button class="link" data-goto="profil">Mein Profil</button> deinen PayPal.me-Namen ein, dann können dir die anderen mit einem Klick senden.';

    // Bestätigte Zahlungen an mich
    const recv = K.items.filter((b) => b.kind === 'move' && b.status === 'ok' && b.to === me);
    $('#k-recv-box').hidden = !recv.length;
    $('#k-recv-n').textContent = recv.length ? `(${recv.length} · ${euro(recv.reduce((a, b) => a + b.amount, 0))})` : '';
    $('#k-recv').innerHTML = recv.map((b) => `<li class="k-v k-v--in">
        <span class="meta">${esc(short(b.date))}</span>
        <span class="k-v__main"><span class="label">von ${esc(who(b.from))}</span><span class="meta meta--hell">${b.note ? esc(b.note) + ' · ' : ''}✓ bestätigt</span></span>
        <b>+${euro(b.amount)}</b><span></span></li>`).join('');

    renderVerlauf();
  }

  // Eine Zeile pro Person: wer → wem, Betrag, ein Knopf. „Warum?“ klappt die Posten auf.
  function row(d, mode) {
    const i = K.shown.push(d) - 1;
    const pend = pendingFor(d.debtor, d.creditor), pendSum = pend.reduce((a, b) => a + b.amount, 0);
    const other = mode === 'pay' ? d.creditor : d.debtor;
    const pp = mode === 'pay' && d.creditor ? paypalUrl(d.creditor, d.amount) : '';
    let btn = '';
    if (pendSum >= d.amount) btn = `<span class="meta k-row__wait">⏳ ${mode === 'get' ? 'bitte oben bestätigen' : d.creditor ? 'wartet auf ' + esc(who(d.creditor)) : 'wird noch bestätigt'}</span>`;
    else if (mode === 'pay' && !d.creditor) btn = `<button type="button" class="btn btn--klein btn--patina" data-k-settle-i="${i}" data-method="">In die Kasse gelegt</button>`;
    else if (mode === 'pay') btn = `<button type="button" class="btn btn--klein btn--patina" data-k-open="${i}">Bezahlen</button>`;
    else if (mode === 'get') btn = `<button type="button" class="btn btn--klein" data-k-settle-i="${i}" data-method="">Erhalten</button>`;
    else btn = `<button type="button" class="link meta" data-k-settle-i="${i}" data-method="">bezahlt?</button>`;
    const payBox = mode === 'pay' && d.creditor ? `<div class="k-row__pay" hidden>
        ${pp ? `<a class="btn btn--klein btn--patina" href="${esc(pp)}" target="_blank" rel="noopener" data-k-pp>${PP_LOGO}Mit PayPal senden ↗</a>` : d.creditor ? `<span class="meta meta--hell">${esc(who(d.creditor))} hat keinen PayPal-Namen im Profil.</span>` : ''}
        <button type="button" class="btn btn--klein" data-k-settle-i="${i}" data-method="Bar">${d.creditor ? 'Bar gegeben' : 'In die Kasse gelegt'}</button>
        <p class="k-row__pp meta" hidden>Fertig bei PayPal? <button type="button" class="btn btn--klein btn--patina" data-k-settle-i="${i}" data-method="PayPal">✓ Ja, bezahlt</button></p>
      </div>` : '';
    return `<li class="k-row ${!d.creditor ? 'is-kasse' : mode === 'pay' ? 'is-minus' : mode === 'get' ? 'is-plus' : ''}">
      <div class="k-row__head">${whoAv(mode === 'other' ? d.debtor : other)}
        <span class="k-row__who">${!d.creditor ? `${esc(whoDu(d.debtor))} ${d.debtor === S.me.id ? 'hast' : 'hat'} Bandgeld → Kasse` : `${esc(whoDu(d.debtor))} → ${esc(whoDu(d.creditor))}`}</span>
        <b class="k-row__amount">${euro(d.amount)}</b>${btn}</div>
      ${payBox}
      <details class="k-row__why"><summary class="meta">Warum? ▾</summary><ul>${d.items.map((it) => `
        <li><span class="meta">${esc(short(it.date))}</span><span><span class="label">${esc(it.title)}</span><span class="meta meta--hell">${esc(it.why)}</span></span>
        <b>${it.amount < 0 ? '−' : '+'}${euro(Math.abs(it.amount))}</b></li>`).join('')}</ul></details>
    </li>`;
  }

  function renderVerlauf() {
    $('#k-list').innerHTML = K.items.map((b) => {
      const what = b.kind === 'out' ? (b.from ? `${esc(whoDu(b.from))} hat bezahlt` : 'aus der Bandkasse') + (b.split.length && b.split.length < S.members.length ? ` · geteilt mit ${b.split.map((id) => esc(whoDu(id))).join(', ')}` : '')
        : b.kind === 'in' ? (b.from ? `Geld liegt bei ${esc(whoDu(b.from))}` : 'in die Bandkasse')
        : b.kind === 'move' ? `${esc(whoDu(b.from))} → ${esc(whoDu(b.to))}${b.note ? ' · ' + esc(b.note) : ''}` : `Beitrag von ${esc(whoDu(b.from))}`;
      const st = b.status === 'pending' ? ' · ⏳ unbestätigt' : b.status === 'rejected' ? ' · nicht angekommen' : '';
      const mine = S.me.admin || b.by === S.me.id;
      return `<li class="k-v k-v--${b.kind} ${b.status !== 'ok' ? 'is-' + b.status : ''}">
        <span class="meta">${esc(short(b.date))}</span>
        <span class="k-v__main"><span class="label">${esc(b.title)}</span><span class="meta meta--hell">${what}${st}</span></span>
        <b>${b.kind === 'out' ? '−' : b.kind === 'move' ? '' : '+'}${euro(b.amount)}</b>
        <span class="k-v__tools">${b.receipt ? `<a class="link meta" href="${esc(b.receipt.url)}" target="_blank" rel="noopener">Beleg</a>` : ''}${mine ? `<button class="link meta" data-k-edit="${b.id}" title="Bearbeiten">✎</button>` : ''}</span>
      </li>`;
    }).join('') || '<li class="leer">Noch keine Einträge.</li>';
  }

  document.addEventListener('click', async (e) => {
    const op = e.target.closest('[data-k-open]');
    if (op) { const box = op.closest('.k-row').querySelector('.k-row__pay'); box.hidden = !box.hidden; return; }
    const pp = e.target.closest('[data-k-pp]');
    if (pp) { const q = pp.closest('.k-row__pay').querySelector('.k-row__pp'); setTimeout(() => { q.hidden = false; }, 400); return; } // PayPal öffnet sich, danach bestätigen
    const cf = e.target.closest('[data-k-confirm]');
    if (cf) {
      const ok = cf.dataset.ok === '1';
      if (!ok && !confirm('Zahlung als „nicht angekommen“ markieren? Der Betrag bleibt dann offen.')) return;
      try { await api(`cash/${cf.dataset.kConfirm}/confirm`, { method: 'POST', body: { ok } }); await loadCash(); toast(ok ? 'Bestätigt ✓' : 'Als nicht angekommen markiert'); }
      catch (err) { toast(err.message); }
      return;
    }
    const st = e.target.closest('[data-k-settle-i]');
    if (!st) return;
    const d = K.shown[+st.dataset.kSettleI]; if (!d) return;
    const method = st.dataset.method;
    if (!confirm(`${whoDu(d.debtor)} → ${whoDu(d.creditor)}: ${euro(d.amount)}${method ? ' (' + method + ')' : ''} als bezahlt eintragen?`)) return;
    try {
      const r = await api('cash', { method: 'POST', body: { kind: 'move', amount: (d.amount / 100).toFixed(2), date: new Date().toISOString().slice(0, 10), from: d.debtor, to: d.creditor, title: `Ausgleich ${who(d.debtor)} → ${who(d.creditor)}`, note: method } });
      await loadCash();
      toast(r.status === 'pending' ? `Eingetragen ✓ · ${d.creditor ? who(d.creditor) : 'Jemand aus der Band'} bestätigt noch` : 'Eingetragen ✓ · quitt');
    } catch (err) { toast(err.message); }
  });

  // Formular: Ausgabe (wird auf alle aufgeteilt) oder Einnahme (gehört der Bandkasse)
  // Nichts angehakt heißt: auf alle Mitglieder aufteilen
  const allIds = () => S.members.map((m) => m.id);
  const isAll = (ids) => !ids.length || (ids.length === S.members.length && allIds().every((id) => ids.includes(id)));
  function fillSplit(ids) {
    const set = new Set(isAll(ids) ? [] : ids);
    $('#k-split').innerHTML = S.members.map((m) => `<label><input type="checkbox" value="${m.id}" ${set.has(m.id) ? 'checked' : ''}><span>${esc(m.name)}</span></label>`).join('');
  }
  const pickedSplit = () => $$('#k-split input:checked').map((i) => +i.value);
  const currentSplit = () => { const p = pickedSplit(); return p.length ? p : allIds(); };
  function updateHint() {
    const a = parseEuro($('#k-amount').value) || 0, from = +$('#k-from').value, e = euro(a);
    let t = '';
    if (K.kind === 'in') t = from ? `${whoDu(from)} ${from === S.me.id ? 'hast' : 'hat'} das Geld noch und ${from === S.me.id ? 'schuldest' : 'schuldet'} es der Bandkasse, bis es drin ist.` : `${e} kommen in die Bandkasse.`;
    else {
      const split = from ? currentSplit() : [];
      $('#k-split').classList.toggle('is-off', !from);
      if (!from) t = `${e} gehen aus der Bandkasse, niemand schuldet etwas.`;
      else {
        const share = euro(Math.floor(a / split.length)), others = split.filter((id) => id !== from).map(whoDu);
        t = `${!pickedSplit().length ? `Geteilt durch alle ${split.length}` : 'Geteilt durch ' + split.length}: ${share} pro Person.` + (others.length ? ` ${others.join(', ')} ${others.length > 1 || others[0] === 'Du' ? 'schulden' : 'schuldet'} ${from === S.me.id ? 'dir' : who(from)} je ${share}.` : '');
      }
    }
    $('#k-hint').textContent = t;
  }
  function openCashForm(kind, b) {
    K.kind = kind; K.editing = b || null;
    const f = $('#k-form'); f.reset();
    const d = b || { from: kind === 'out' ? S.me.id : 0, split: S.members.map((m) => m.id), category: kind === 'in' ? 'gage' : 'sonstiges' };
    $('#k-form-titel').textContent = (b ? 'Bearbeiten: ' : '') + (kind === 'in' ? 'Einnahme (Gage, Merch …)' : 'Ausgabe');
    $('#k-from-label').textContent = kind === 'in' ? 'Wo ist das Geld jetzt?' : 'Wer hat bezahlt?';
    $('#k-title').placeholder = kind === 'in' ? 'z. B. Gage Rockfabrik' : 'z. B. Mixing Mikrofilet';
    const opt = (id, label) => `<option value="${id}" ${(d.from || 0) === id ? 'selected' : ''}>${esc(label)}</option>`;
    const people = [opt(S.me.id, 'Ich')].concat(S.members.filter((m) => m.id !== S.me.id).map((m) => opt(m.id, m.name)));
    $('#k-from').innerHTML = kind === 'in' ? opt(0, 'In der Bandkasse') + people.join('') : people.join('') + opt(0, 'Bandkasse');
    $('#k-cat').innerHTML = Object.entries(K.categories).filter(([k]) => k !== 'beitrag').map(([k, l]) => `<option value="${k}">${esc(l)}</option>`).join('');
    $('#k-cat').value = d.category || 'sonstiges';
    fillSplit(b ? d.split : []);
    $('#k-split-box').hidden = kind === 'in';
    $('#k-amount').value = d.amount ? (d.amount / 100).toFixed(2).replace('.', ',') : '';
    $('#k-date').value = d.date || new Date().toISOString().slice(0, 10);
    $('#k-title').value = d.title || '';
    $('#k-note').value = d.note || '';
    // „Mehr Optionen“ nur offen, wenn die Buchung davon Gebrauch macht
    const special = !!b && (kind === 'out' && !isAll(b.split) || !!b.note || !!b.receipt);
    $('#k-mehr').open = special;
    $('#k-delete').hidden = !b;
    $('#k-save').textContent = 'Speichern';
    updateHint();
    f.hidden = false;
    f.scrollIntoView({ behavior: 'smooth', block: 'start' });
    $('#k-amount').focus({ preventScroll: true });
  }
  $('#k-new-out').addEventListener('click', () => openCashForm('out'));
  $('#k-new-in').addEventListener('click', () => openCashForm('in'));
  $('#k-cancel').addEventListener('click', () => { $('#k-form').hidden = true; K.editing = null; });
  $('#k-form').addEventListener('input', updateHint);
  $('#k-form').addEventListener('change', updateHint);
  $('#k-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const from = +$('#k-from').value;
    const split = K.kind === 'in' || !from ? [] : currentSplit();
    const body = { kind: K.kind, amount: (parseEuro($('#k-amount').value) / 100).toFixed(2), date: $('#k-date').value, title: $('#k-title').value.trim(), category: $('#k-cat').value, from, to: 0, note: $('#k-note').value.trim(), split };
    const btn = $('#k-save'); btn.disabled = true;
    try {
      const r = K.editing ? await api('cash/' + K.editing.id, { method: 'POST', body }) : await api('cash', { method: 'POST', body });
      const file = $('#k-receipt').files[0];
      if (file) {
        const fd = new FormData(); fd.append('file', file);
        const up = await fetch(CFG.rest + 'cash/' + r.id + '/receipt', { method: 'POST', headers: { 'X-WP-Nonce': CFG.nonce }, body: fd, credentials: 'same-origin' });
        if (!up.ok) toast((await up.json().catch(() => ({}))).message || 'Beleg konnte nicht hochgeladen werden');
      }
      $('#k-form').hidden = true; K.editing = null;
      await loadCash(); toast('Eingetragen ✓');
    } catch (err) { toast(err.message); }
    btn.disabled = false;
  });
  async function deleteCash(b) {
    if (!b || !confirm(`„${b.title}“ (${euro(b.amount)}) wirklich löschen?`)) return;
    try { await api('cash/' + b.id, { method: 'DELETE' }); $('#k-form').hidden = true; K.editing = null; await loadCash(); toast('Gelöscht'); }
    catch (err) { toast(err.message); }
  }
  $('#k-delete').addEventListener('click', () => deleteCash(K.editing));
  document.addEventListener('click', (e) => {
    const ed = e.target.closest('[data-k-edit]');
    if (!ed) return;
    const b = K.items.find((x) => x.id === +ed.dataset.kEdit); if (!b) return;
    if (b.kind === 'out' || b.kind === 'in') openCashForm(b.kind, b);
    else deleteCash(b); // Ausgleiche und alte Beiträge kann man nur löschen
  });

  /* ================= PLAYS ================= */
  const P = { data: null, open: null, done: {} };
  const plNum = (n) => Number(n || 0).toLocaleString('de-DE');
  const plSum = (a) => a.reduce((x, y) => x + y, 0);
  // Balken als SVG, ohne Bibliothek
  function bars(vals, w, h, cls = '') {
    const max = Math.max(1, ...vals), bw = w / vals.length;
    return `<svg class="pl-bars ${cls}" viewBox="0 0 ${w} ${h}" preserveAspectRatio="none" aria-hidden="true">${vals.map((v, i) => v ? `<rect x="${(i * bw).toFixed(2)}" y="${(h - (v / max) * h).toFixed(2)}" width="${Math.max(bw - (bw > 3 ? 1 : 0), 0.6).toFixed(2)}" height="${((v / max) * h).toFixed(2)}"/>` : '').join('')}</svg>`;
  }
  const plDay = (iso) => fmt(iso, { day: '2-digit', month: '2-digit' });

  async function loadPlays() {
    try { P.data = await api('plays'); renderPlays(); } catch (err) { toast(err.message); }
  }
  function renderPlays() {
    const d = P.data; if (!d) return;
    $('#pl-bookmarklet').href = bookmarklet();
    if (!d.tracks.length) {
      $('#pl-top').innerHTML = '<p class="k-satz">Noch keine Zahlen</p><p class="meta">Einmal den Sync-Button einrichten (unten), dann erscheinen hier alle Songs.</p>';
      $('#pl-chart').innerHTML = ''; $('#pl-list').innerHTML = ''; $('#pl-help').open = true; return;
    }
    const all = d.tracks.reduce((acc, t) => acc.map((v, i) => v + t.daily[i]), new Array(365).fill(0));
    const total = plSum(d.tracks.map((t) => t.total)), last7 = plSum(all.slice(-7)), prev7 = plSum(all.slice(-14, -7)), last30 = plSum(all.slice(-30));
    const trend = (a, b) => (b ? Math.round(((a - b) / b) * 100) : 0);
    const tr = trend(last7, prev7);
    $('#pl-top').innerHTML = `<p class="k-satz">${plNum(total)} <span class="pl-unit">Plays</span></p>
      <div class="pl-kpis"><span><b>${plNum(all[364])}</b><span class="meta">am ${esc(plDay(d.latest))}</span></span>
        <span><b>${plNum(last7)}</b><span class="meta">7 Tage ${prev7 ? `<i class="${tr >= 0 ? 'is-up' : 'is-down'}">${tr >= 0 ? '▲' : '▼'} ${Math.abs(tr)} %</i>` : ''}</span></span>
        <span><b>${plNum(last30)}</b><span class="meta">30 Tage</span></span></div>
      <p class="meta meta--hell">Spotify + Apple Music · Stand ${esc(plDay(d.latest))} · zuletzt geholt ${esc(d.synced)}${d.syncedBy ? ' von ' + esc(d.syncedBy) : ''}</p>`;
    $('#pl-chart').innerHTML = `<div class="pl-chart__head meta"><span>Plays pro Tag · 90 Tage</span><span>max ${plNum(Math.max(...all.slice(-90)))}</span></div>${bars(all.slice(-90), 900, 120)}<div class="pl-chart__axis meta"><span>${esc(plDay(plAdd(d.start, 275)))}</span><span>${esc(plDay(d.latest))}</span></div>`;
    $('#pl-list').innerHTML = d.tracks.map((t) => {
      const l7 = plSum(t.daily.slice(-7)), p7 = plSum(t.daily.slice(-14, -7)), x = trend(l7, p7), open = P.open === t.isrc;
      return `<li class="pl-row ${open ? 'is-open' : ''}">
        <button type="button" class="pl-row__head" data-pl="${esc(t.isrc)}">
          <span class="pl-row__title label">${esc(t.title)}</span>
          <span class="pl-row__spark">${bars(t.daily.slice(-30), 120, 28, 'pl-bars--spark')}</span>
          <span class="pl-row__week meta">+${plNum(l7)}${p7 || l7 ? ` <i class="${x >= 0 ? 'is-up' : 'is-down'}">${x >= 0 ? '▲' : '▼'}</i>` : ''}</span>
          <b class="pl-row__total">${plNum(t.total)}</b>
        </button>
        ${open ? `<div class="pl-row__detail">
          <div class="pl-chart__head meta"><span>Spotify pro Tag · 365 Tage</span><span>max ${plNum(Math.max(...t.daily))}</span></div>
          ${bars(t.daily, 900, 90)}
          <div class="pl-split meta"><span>Spotify <b>${plNum(t.spotify)}</b></span><span>Apple Music <b>${plNum(t.apple)}</b></span><span>30 Tage <b>${plNum(plSum(t.daily.slice(-30)))}</b></span><span>Bester Tag <b>${plNum(Math.max(...t.daily))}</b></span></div>
        </div>` : ''}
      </li>`;
    }).join('');
  }
  const plAdd = (iso, n) => { const x = new Date(iso + 'T12:00:00'); x.setDate(x.getDate() + n); return x.toISOString().slice(0, 10); };
  $('#pl-list').addEventListener('click', (e) => { const b = e.target.closest('[data-pl]'); if (!b) return; P.open = P.open === b.dataset.pl ? null : b.dataset.pl; renderPlays(); });
  $('#pl-help-open').addEventListener('click', () => { const h = $('#pl-help'); h.open = true; h.scrollIntoView({ behavior: 'smooth', block: 'start' }); });

  // Sync: Das Lesezeichen auf distrokid.com öffnet das Portal (?plays_sync=1) und schickt die Zahlen per postMessage
  if (new URLSearchParams(location.search).has('plays_sync')) {
    const b = $('#pl-banner'); b.hidden = false; b.textContent = 'Warte auf die Zahlen von DistroKid …';
  }
  window.addEventListener('message', async (e) => {
    if (e.origin !== 'https://distrokid.com' || !e.data || e.data.type !== 'gl-plays' || P.done[e.data.id]) return;
    P.done[e.data.id] = true;
    const b = $('#pl-banner'); b.hidden = false; b.textContent = 'Übernehme Zahlen …';
    let msg;
    try {
      const r = await api('plays/import', { method: 'POST', body: { tracks: e.data.tracks } });
      msg = `✓ ${r.tracks} Songs aktualisiert. Du kannst den DistroKid-Tab schließen.`;
      show('plays'); await loadPlays();
    } catch (err) { msg = 'Fehler: ' + err.message; }
    b.textContent = msg;
    try { e.source.postMessage({ type: 'gl-plays-ok', id: e.data.id, msg }, e.origin); } catch (err) {}
    setTimeout(() => { b.hidden = true; }, 8000);
  });

  function bookmarklet() {
    const portal = location.origin + CFG.scope + '?plays_sync=1#plays';
    const code = `(async()=>{const O=${JSON.stringify(location.origin)},P=${JSON.stringify(portal)};
if(!/(^|\\.)distrokid\\.com$/.test(location.hostname)){alert('Bitte zuerst distrokid.com öffnen und einloggen, dann nochmal klicken.');return;}
const w=window.open(P,'glsync');
const note=t=>{let b=document.getElementById('glsync');if(!b){b=document.createElement('div');b.id='glsync';b.style.cssText='position:fixed;z-index:2147483647;top:16px;right:16px;max-width:340px;background:#0A0B0A;color:#ECEEE8;border-left:4px solid #7C9A85;padding:14px 18px;font:14px/1.4 Arial,sans-serif;box-shadow:0 8px 30px rgba(0,0,0,.4)';document.body.appendChild(b);}b.textContent=t;};
try{note('GHOSTLIKE Sync: lese Tracks …');
const doc=new DOMParser().parseFromString(await(await fetch('/stats/')).text(),'text/html');
const seen={},tracks=[];
for(const o of doc.querySelectorAll('select.trendingFilterTracks option')){const t=o.textContent.replace(/\\s+/g,' ').trim();if(!/^Ghostlike - /i.test(t)||!o.value||!o.id||seen[o.value])continue;seen[o.value]=1;tracks.push({isrc:o.value,id:o.id,title:t.replace(/^Ghostlike - /i,'').replace(/ - Single$/i,'')});}
if(!tracks.length)throw new Error('Keine Ghostlike-Tracks gefunden.');
const arr=(h,re)=>{const m=h.match(re);return m?JSON.parse(m[1]):[];};
for(const [i,t] of tracks.entries()){note('GHOSTLIKE Sync: '+(i+1)+'/'+tracks.length+' · '+t.title);
const u='/stats/?type=track&id='+t.id+'&upc=undefined&view='+t.isrc+'&data=';
const s=await(await fetch(u+'streams')).text();const k=s.indexOf('makeChart("trend60day"');
t.spotify={};(k<0?[]:arr(s.slice(k),/"dataProvider":\\s*(\\[[^\\]]*\\])/)).forEach(p=>{t.spotify[p.category]=p['column-1'];});
const a=await(await fetch(u+'applemusic')).text();
t.apple={};arr(a,/"dataProvider":\\s*(\\[\\s*\\{"field"[^\\]]*\\])/).forEach(p=>{const [m,y]=p.field.split('-');t.apple['20'+y+'-'+m]=p.value;});
delete t.id;}
note('GHOSTLIKE Sync: sende ans Portal …');
const id=Date.now();let ok=false;
window.addEventListener('message',e=>{if(e.origin===O&&e.data&&e.data.type==='gl-plays-ok'&&e.data.id===id){ok=true;note(e.data.msg);}});
for(let i=0;i<60&&!ok;i++){if(w&&!w.closed)w.postMessage({type:'gl-plays',id,tracks},O);await new Promise(r=>setTimeout(r,1000));}
if(!ok)note('Das Portal hat nicht geantwortet. Bitte im Portal eingeloggt sein und nochmal klicken.');
}catch(e){note('GHOSTLIKE Sync – Fehler: '+e.message);}})();`;
    return 'javascript:' + encodeURIComponent(code.replace(/\n/g, ''));
  }

  /* ================= PROFIL ================= */
  function renderProfile(force) {
    const me = S.me;
    const set = (id, v) => { const el = $(id); if (el && (force || document.activeElement !== el)) el.value = v || ''; };
    if (force || !$('#pf-about').dataset.filled) {
      set('#pf-first', me.first); set('#pf-last', me.last); set('#pf-role', me.role); set('#pf-paypal', me.paypal); set('#pf-mail', me.email);
      $('#pf-about').dataset.filled = '1';
    }
    $('#pf-login').value = me.login;
    $('#pf-n-versions').checked = me.notify !== false;
    $('#pf-band').innerHTML = S.members.map((m) => `<li class="${m.id === me.id ? 'is-me' : ''}">${av(m.name)}<span><span class="label">${esc(m.name)}</span><span class="meta">${esc(m.role || '–')}</span></span></li>`).join('');
    $('#pf-meta').textContent = `Benutzername: ${me.login} · ${me.admin ? 'Admin' : 'Bandmitglied'}`;
  }
  async function submit(form, fn) {
    const btn = $('button[type=submit]', form);
    btn.disabled = true;
    try { await fn(); } catch (err) { toast(err.message); }
    btn.disabled = false;
  }
  $('#pf-about').addEventListener('submit', (e) => { e.preventDefault(); submit(e.target, async () => {
    S.me = await api('profile', { method: 'POST', body: { first: $('#pf-first').value.trim(), last: $('#pf-last').value.trim(), role: $('#pf-role').value.trim(), paypal: $('#pf-paypal').value.trim() } });
    await load(); renderProfile(true); toast('Gespeichert');
  }); });
  $('#pf-n-versions').addEventListener('change', async (e) => {
    try { S.me = await api('profile/notify', { method: 'POST', body: { versions: e.target.checked } }); toast(e.target.checked ? 'Du bekommst Mails zu neuen Versionen' : 'Keine Mails mehr zu neuen Versionen'); }
    catch (err) { e.target.checked = !e.target.checked; toast(err.message); }
  });
  $('#pf-notify').addEventListener('submit', (e) => e.preventDefault());

  /* ---------- Push-Benachrichtigungen ---------- */
  const PUSH_OK = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
  const IS_IOS = /iP(hone|ad|od)/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  const STANDALONE = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
  const swReady = PUSH_OK && CFG.sw ? navigator.serviceWorker.register(CFG.sw, { scope: CFG.scope }).catch(() => null) : Promise.resolve(null);
  const keyBytes = (b64) => { const s = atob((b64 + '='.repeat((4 - (b64.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/')); return Uint8Array.from(s, (c) => c.charCodeAt(0)); };
  async function currentSub() { const reg = await swReady; return reg && reg.pushManager ? reg.pushManager.getSubscription() : null; }
  async function renderPush() {
    const st = $('#pf-push-state'), on = $('#pf-push-on'), off = $('#pf-push-off'), test = $('#pf-push-test'), types = $('#pf-push-types');
    if (!PUSH_OK) {
      st.innerHTML = IS_IOS && !STANDALONE ? 'Auf dem iPhone geht Push nur in der App: Portal in Safari über <b>Teilen → Zum Home-Bildschirm</b> hinzufügen und von dort öffnen, dann hier aktivieren.' : 'Dieser Browser unterstützt keine Push-Benachrichtigungen.';
      [on, off, test].forEach((b) => { b.hidden = true; }); types.innerHTML = ''; return;
    }
    let p; try { p = await api('push'); } catch (err) { st.textContent = err.message; return; }
    const sub = await currentSub();
    const denied = Notification.permission === 'denied';
    st.innerHTML = denied ? 'Benachrichtigungen sind für das Portal blockiert. Erlaube sie in den Geräte-Einstellungen (iPhone: Einstellungen → Mitteilungen → GL Portal).'
      : sub ? `✓ Auf diesem Gerät aktiv${p.devices > 1 ? ` · insgesamt ${p.devices} Geräte` : ''}.` : `Auf diesem Gerät aus${p.devices ? ` · aktiv auf ${p.devices} anderen Gerät${p.devices > 1 ? 'en' : ''}` : ''}.`;
    on.hidden = !!sub || denied; off.hidden = !sub; test.hidden = !sub;
    types.innerHTML = Object.entries(p.types).map(([k, l]) => `<label class="check"><input type="checkbox" data-push-type="${k}" ${p.prefs[k] ? 'checked' : ''}><span>${esc(l)}</span></label>`).join('');
  }
  $('#pf-push-on').addEventListener('click', async (e) => {
    const btn = e.target; btn.disabled = true;
    try {
      const perm = await Notification.requestPermission();
      if (perm !== 'granted') throw new Error('Ohne Erlaubnis keine Push-Nachrichten.');
      const reg = await swReady; if (!reg) throw new Error('Service Worker konnte nicht starten.');
      const { key } = await api('push');
      const sub = (await reg.pushManager.getSubscription()) || await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(key) });
      await api('push/subscribe', { method: 'POST', body: { subscription: sub.toJSON(), device: IS_IOS ? 'iPhone/iPad' : navigator.userAgentData?.platform || navigator.platform || '' } });
      toast('Push aktiviert ✓');
    } catch (err) { toast(err.message); }
    btn.disabled = false; renderPush();
  });
  $('#pf-push-off').addEventListener('click', async () => {
    try { const sub = await currentSub(); if (sub) { await api('push/unsubscribe', { method: 'POST', body: { endpoint: sub.endpoint } }); await sub.unsubscribe(); } toast('Push auf diesem Gerät aus'); }
    catch (err) { toast(err.message); }
    renderPush();
  });
  $('#pf-push-test').addEventListener('click', async (e) => {
    e.target.disabled = true;
    try { await api('push/test', { method: 'POST' }); toast('Test gesendet – kommt gleich an'); } catch (err) { toast(err.message); }
    e.target.disabled = false;
  });
  $('#pf-push-types').addEventListener('change', async (e) => {
    const k = e.target.dataset.pushType; if (!k) return;
    try { await api('push/prefs', { method: 'POST', body: { [k]: e.target.checked } }); toast('Gespeichert'); } catch (err) { e.target.checked = !e.target.checked; toast(err.message); }
  });
  $('#pf-email').addEventListener('submit', (e) => { e.preventDefault(); submit(e.target, async () => {
    S.me = await api('profile/email', { method: 'POST', body: { email: $('#pf-mail').value.trim(), password: $('#pf-mail-pass').value } });
    $('#pf-mail-pass').value = ''; renderProfile(true); toast('E-Mail geändert');
  }); });
  $('#pf-pass').addEventListener('submit', (e) => { e.preventDefault(); submit(e.target, async () => {
    if ($('#pf-new').value !== $('#pf-new2').value) throw new Error('Die neuen Passwörter stimmen nicht überein.');
    await api('profile/password', { method: 'POST', body: { current: $('#pf-cur').value, new: $('#pf-new').value } });
    toast('Passwort geändert – die Seite lädt neu');
    setTimeout(() => location.reload(), 1200); // neues Login-Cookie → frische Sitzung
  }); });

  /* ================= LADEN ================= */
  function renderAll() {
    $('#me-name').textContent = S.me.name;
    $('#me-avatar').textContent = ini(S.me.name);
    renderHome(); renderCal(); renderStudio(); renderTodos(); renderProfile(false);
    if (curId && !drawer.hidden) fillDrawer(false);
    syncPlayButtons();
  }

  async function load() {
    const fresh = await api('state');
    S = fresh;
    if (Array.isArray(S.attendance)) S.attendance = {};
    if (!S.audio || Array.isArray(S.audio)) S.audio = {};
    if (!S.files || Array.isArray(S.files)) S.files = {};
    S.songs = S.songs.map(normSong);
    buildInitials();
    renderAll();
  }

  // Neu laden, damit Änderungen der anderen auftauchen (nicht während gespeichert oder getippt wird).
  setInterval(() => {
    if (document.hidden || busy || drag || saveTimer && $('#d-saved').textContent.startsWith('Ungespeichert')) return;
    const a = document.activeElement;
    if (a && a.matches('input, textarea, select') && !a.closest('.zusage')) return;
    load().catch(() => {});
  }, 30000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden && !busy) load().catch(() => {}); });

  (async function start() {
    try {
      await load();
      $('#loading').hidden = true;
      setStudioView(studioView);
      let view = (location.hash || '').slice(1);
      if (!['home', 'kalender', 'studio', 'kasse', 'plays', 'profil'].includes(view)) { try { view = sessionStorage.getItem('glp-view') || 'home'; } catch (e) { view = 'home'; } }
      show(view);
      // Link aus der Mail: /portal/?song=7#studio → Song direkt öffnen
      const sid = +new URLSearchParams(location.search).get('song');
      if (sid && songById(sid)) {
        setStudioView('matrix'); show('studio'); openDrawer(sid);
        if (history.replaceState) history.replaceState(null, '', location.pathname + '#studio');
      }
    } catch (err) {
      $('#loading').textContent = 'Konnte Daten nicht laden: ' + err.message;
    }
  })();
})();
