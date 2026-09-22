/* TicketHub reply editor — Markdown toolbar, Write/Preview tabs, pasted
   images, and the canned-response picker. Attaches to every
   <textarea data-editor> on the page. Depends on nothing but app.js's
   window.TH (for toasts + the CSRF token). */
(function () {
  'use strict';

  const $ = (s, el) => (el || document).querySelector(s);
  const $$ = (s, el) => Array.from((el || document).querySelectorAll(s));
  const toast = (m, k) => (window.thToast ? window.thToast(m, k) : console.log(m));

  /* ---------- CSRF: rotates on every POST, so every AJAX answer refreshes the page's copies ---------- */
  function csrf() {
    const meta = $('meta[name=csrf-value]');
    return { name: ($('meta[name=csrf-name]') || {}).content || (window.TH && TH.csrfName) || 'csrf_test_name',
             value: meta ? meta.content : (window.TH ? TH.csrfValue : '') };
  }
  function refreshCsrf(res) {
    const fresh = res.headers.get('X-CSRF-TOKEN');
    if (!fresh) return;
    const name = csrf().name;
    $$('input[name="' + name + '"]').forEach((i) => { i.value = fresh; });
    const meta = $('meta[name=csrf-value]');
    if (meta) meta.content = fresh;
    if (window.TH) TH.csrfValue = fresh;
  }
  function postForm(url, fd) {
    const c = csrf();
    fd.append(c.name, c.value);
    return fetch(url, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': c.value } })
      .then((res) => { refreshCsrf(res); return res; });
  }

  /* ---------- textarea surgery ---------- */
  function replaceRange(ta, start, end, text, selStart, selEnd) {
    ta.focus();
    ta.setRangeText(text, start, end, 'preserve');
    ta.setSelectionRange(selStart, selEnd);
    ta.dispatchEvent(new Event('input', { bubbles: true }));
  }
  function wrap(ta, before, after, placeholder) {
    const s = ta.selectionStart, e = ta.selectionEnd;
    const sel = ta.value.slice(s, e) || placeholder || '';
    replaceRange(ta, s, e, before + sel + after, s + before.length, s + before.length + sel.length);
  }
  function prefixLines(ta, prefix, numbered) {
    const s = ta.selectionStart, e = ta.selectionEnd;
    const ls = ta.value.lastIndexOf('\n', s - 1) + 1;
    const leEnd = ta.value.indexOf('\n', e);
    const le = leEnd === -1 ? ta.value.length : leEnd;
    const lines = ta.value.slice(ls, le).split('\n');
    const out = lines.map((l, i) => (numbered ? (i + 1) + '. ' : prefix) + l).join('\n');
    replaceRange(ta, ls, le, out, ls, ls + out.length);
  }
  function insertAtCursor(ta, text) {
    const s = ta.selectionStart;
    replaceRange(ta, s, ta.selectionEnd, text, s + text.length, s + text.length);
  }
  function currentLine(ta) {
    const s = ta.selectionStart;
    const ls = ta.value.lastIndexOf('\n', s - 1) + 1;
    return { start: ls, text: ta.value.slice(ls, s) };
  }
  function fillVars(body, vars) {
    return body.replace(/\{\{\s*([a-z_]+)\s*\}\}/gi, (m, k) => (vars && vars[k] !== undefined ? vars[k] : m));
  }

  /* ---------- toolbar ---------- */
  const ICONS = {
    bold: '<path d="M7 4h6.5a3.5 3.5 0 0 1 0 7H7zM7 11h7.5a3.5 3.5 0 0 1 0 7H7z"/>',
    italic: '<path d="M14 4h6M4 20h6M15 4 9 20"/>',
    code: '<path d="m8 8-4 4 4 4M16 8l4 4-4 4M13 5l-2 14"/>',
    link: '<path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.5 1.5"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.5-1.5"/>',
    ul: '<path d="M9 6h11M9 12h11M9 18h11"/><circle cx="4.5" cy="6" r="1" fill="currentColor"/><circle cx="4.5" cy="12" r="1" fill="currentColor"/><circle cx="4.5" cy="18" r="1" fill="currentColor"/>',
    ol: '<path d="M10 6h10M10 12h10M10 18h10M4 5l1.5-1v5M3.8 12.5c.4-.9 2.4-1 2.4.2 0 1-2.4 2-2.4 3.3h2.6M3.8 17.5h2.2l-1.3 1.6c1.1 0 1.6.5 1.6 1.2 0 1-1.5 1.4-2.7.6"/>',
    quote: '<path d="M6 17c3 0 4-2 4-5V7H5v5h3c0 2-.5 3-2 3z"/><path d="M15 17c3 0 4-2 4-5V7h-5v5h3c0 2-.5 3-2 3z"/>',
    h2: '<path d="M4 5v14M4 12h8M12 5v14"/><path d="M16 11.5c.5-1 3-1.3 3 .5 0 1.4-3 2.6-3 4.5h3.5"/>',
    image: '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="8.5" cy="9.5" r="1.5"/><path d="m21 16-5-5-8 8"/>'
  };
  const TOOLS = {
    full: [['bold', 'Bold (Ctrl+B)'], ['italic', 'Italic (Ctrl+I)'], ['code', 'Code'], ['link', 'Link (Ctrl+K)'], ['ul', 'Bullet list'], ['ol', 'Numbered list'], ['quote', 'Quote'], ['h2', 'Heading'], ['image', 'Attach image']],
    light: [['bold', 'Bold'], ['italic', 'Italic'], ['code', 'Code'], ['link', 'Link'], ['ul', 'List'], ['image', 'Attach image']]
  };
  const btnCls = 'inline-flex items-center justify-center w-7 h-7 rounded-md text-muted hover:text-ink hover:bg-canvas transition';
  const tabCls = (on) => 'h-7 px-2.5 rounded-md text-[12px] font-medium transition ' + (on ? 'bg-ink text-white' : 'text-muted hover:text-ink hover:bg-canvas');

  function buildChrome(ta) {
    const kind = ta.dataset.toolbar === 'light' ? 'light' : 'full';
    const bar = document.createElement('div');
    bar.className = 'flex flex-wrap items-center gap-0.5 pb-2 mb-2 border-b border-line';
    bar.setAttribute('data-editor-bar', '');
    let html = '<div class="flex items-center gap-0.5 mr-1">' +
      '<button type="button" data-editor-tab="write" class="' + tabCls(true) + '">Write</button>' +
      '<button type="button" data-editor-tab="preview" class="' + tabCls(false) + '">Preview</button></div>' +
      '<span class="w-px h-5 bg-line mx-1"></span>';
    TOOLS[kind].forEach(([id, title]) => {
      if (id === 'image' && !ta.dataset.uploadUrl) return;
      html += '<button type="button" data-md="' + id + '" title="' + title + '" aria-label="' + title + '" class="' + btnCls + '">' +
        '<svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">' + ICONS[id] + '</svg></button>';
    });
    html += '<span class="ml-auto text-[11px] text-faint hidden sm:inline" data-editor-hint>Markdown · paste or drop images</span>';
    if (ta.dataset.uploadUrl) html += '<input type="file" accept="image/*" class="hidden" data-editor-file>';
    bar.innerHTML = html;
    ta.parentNode.insertBefore(bar, ta);

    const prev = document.createElement('div');
    prev.className = 'hidden text-[13.5px] leading-relaxed text-ink-500 min-h-[96px] prose-md';
    prev.setAttribute('data-editor-preview', '');
    ta.parentNode.insertBefore(prev, ta.nextSibling);
    return { bar, prev };
  }

  /* ---------- canned responses ---------- */
  function initCanned(ta, ed) {
    const picker = ed.picker;
    if (!picker || !ta.dataset.cannedUrl) return;
    const list = $('[data-canned-list]', picker);
    const search = $('[data-canned-search]', picker);
    const pop = $('[data-canned-pop]', picker);
    const toggle = $('[data-canned-toggle]', picker);
    const vars = ta.dataset.vars ? JSON.parse(ta.dataset.vars) : {};
    let items = [];
    let loaded = false;
    let active = -1;

    const load = () => fetch(ta.dataset.cannedUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then((r) => r.json()).then((j) => { items = j.responses || []; loaded = true; ed.canned = items; render(''); })
      .catch(() => toast('Could not load canned responses', 'warn'));

    const escapeHtml = (s) => s.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
    const scopeTag = { personal: 'Mine', group: 'Team', global: 'Global' };

    function render(q) {
      q = (q || '').trim().toLowerCase().replace(/^\//, '');
      const rows = items.filter((c) => !q || (c.title + ' ' + (c.shortcut || '') + ' ' + c.body).toLowerCase().includes(q));
      active = rows.length ? 0 : -1;
      if (!rows.length) {
        list.innerHTML = '<div class="px-3 py-4 text-[12.5px] text-faint text-center">' + (items.length ? 'No response matches' : 'No canned responses yet') + '</div>';
        return;
      }
      list.innerHTML = rows.map((c, i) =>
        '<div data-canned-row="' + c.id + '" class="group flex items-start gap-2 px-3 py-2 cursor-pointer ' + (i === active ? 'bg-canvas' : 'hover:bg-canvas') + '">' +
        '<div class="min-w-0 flex-1"><div class="flex items-center gap-1.5">' +
        '<span class="text-[12.5px] font-medium text-ink truncate">' + escapeHtml(c.title) + '</span>' +
        (c.shortcut ? '<code class="font-mono text-[10.5px] text-brand bg-brand-50 rounded px-1 truncate max-w-[140px]">/' + escapeHtml(c.shortcut) + '</code>' : '') +
        '<span class="text-[10px] uppercase tracking-wide text-faint ml-auto shrink-0">' + (scopeTag[c.scope] || '') + '</span></div>' +
        '<div class="text-[11.5px] text-muted truncate">' + escapeHtml(c.body.replace(/\s+/g, ' ').slice(0, 90)) + '</div></div>' +
        (c.mine ? '<button type="button" data-canned-del="' + c.id + '" title="Remove this response" class="shrink-0 w-6 h-6 grid place-items-center rounded-md text-faint opacity-0 group-hover:opacity-100 hover:text-alert hover:bg-alert-50"><svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg></button>' : '') +
        '</div>').join('');
      list._rows = rows;
    }

    function insert(c) {
      const body = fillVars(c.body, vars);
      const s = ta.selectionStart;
      const before = ta.value.slice(0, s);
      const pad = before === '' || /\n$/.test(before) ? '' : (/\n\n$/.test(before) ? '' : '\n');
      insertAtCursor(ta, pad + body);
      close();
      ed.showWrite();
    }
    function open() {
      pop.classList.remove('hidden');
      search.value = '';
      if (!loaded) load(); else render('');
      setTimeout(() => search.focus(), 20);
    }
    function close() { pop.classList.add('hidden'); }

    toggle.addEventListener('click', () => (pop.classList.contains('hidden') ? open() : close()));
    search.addEventListener('input', () => render(search.value));
    search.addEventListener('keydown', (e) => {
      const rows = list._rows || [];
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        if (!rows.length) return;
        active = (active + (e.key === 'ArrowDown' ? 1 : rows.length - 1)) % rows.length;
        $$('[data-canned-row]', list).forEach((r, i) => r.classList.toggle('bg-canvas', i === active));
      } else if (e.key === 'Enter') {
        e.preventDefault();
        if (rows[active]) insert(rows[active]);
      } else if (e.key === 'Escape') {
        close();
        ta.focus();
      }
    });
    list.addEventListener('click', (e) => {
      const del = e.target.closest('[data-canned-del]');
      if (del) {
        e.stopPropagation();
        if (!confirm('Remove this response from your list?')) return;
        const c = csrf();
        const f = document.createElement('form');
        f.method = 'post';
        f.action = ta.dataset.cannedDeleteUrl.replace('%d', del.dataset.cannedDel);
        f.innerHTML = '<input type="hidden" name="' + c.name + '" value="' + c.value + '">';
        document.body.appendChild(f);
        f.submit();
        return;
      }
      const row = e.target.closest('[data-canned-row]');
      if (row) {
        const c = items.find((x) => String(x.id) === row.dataset.cannedRow);
        if (c) insert(c);
      }
    });
    document.addEventListener('click', (e) => {
      if (!pop.classList.contains('hidden') && !picker.contains(e.target)) close();
    });

    // "/shortcut" at the start of a line, then Tab → expands in place.
    ta.addEventListener('keydown', (e) => {
      if (e.key !== 'Tab' || e.shiftKey) return;
      const line = currentLine(ta);
      const m = line.text.match(/^\/([\w-]+)$/);
      if (!m) return;
      const go = () => {
        const c = items.find((x) => x.shortcut && x.shortcut.toLowerCase() === m[1].toLowerCase());
        if (!c) { toast('No response with the shortcut /' + m[1], 'warn'); return; }
        const body = fillVars(c.body, vars);
        replaceRange(ta, line.start, ta.selectionStart, body, line.start + body.length, line.start + body.length);
      };
      e.preventDefault();
      if (loaded) go(); else load().then(go);
    });
    // Preload so shortcuts work on first Tab without a round trip.
    load();
  }

  /* ---------- main ---------- */
  function initEditor(ta) {
    if (ta._thEditor) return;
    ta._thEditor = true;
    const { bar, prev } = buildChrome(ta);
    const ed = {
      picker: ta.closest('form') ? $('[data-canned-picker]', ta.closest('form')) : null,
      showWrite() {
        prev.classList.add('hidden');
        ta.classList.remove('hidden');
        $$('[data-editor-tab]', bar).forEach((t) => { t.className = tabCls(t.dataset.editorTab === 'write'); });
        $$('[data-md]', bar).forEach((b) => b.classList.remove('opacity-40', 'pointer-events-none'));
      },
      showPreview() {
        const fd = new FormData();
        fd.append('body', ta.value);
        prev.innerHTML = '<p class="text-[12.5px] text-faint">Rendering…</p>';
        prev.classList.remove('hidden');
        ta.classList.add('hidden');
        $$('[data-editor-tab]', bar).forEach((t) => { t.className = tabCls(t.dataset.editorTab === 'preview'); });
        $$('[data-md]', bar).forEach((b) => b.classList.add('opacity-40', 'pointer-events-none'));
        postForm(ta.dataset.previewUrl, fd)
          .then((r) => { if (!r.ok) throw new Error('Preview failed (' + r.status + ')'); return r.text(); })
          .then((html) => { prev.innerHTML = html; })
          .catch((err) => { prev.innerHTML = '<p class="text-[12.5px] text-alert">' + err.message + '</p>'; });
      }
    };

    bar.addEventListener('click', (e) => {
      const tab = e.target.closest('[data-editor-tab]');
      if (tab) { tab.dataset.editorTab === 'preview' ? ed.showPreview() : ed.showWrite(); return; }
      const b = e.target.closest('[data-md]');
      if (!b) return;
      apply(b.dataset.md);
    });

    function apply(cmd) {
      switch (cmd) {
        case 'bold': wrap(ta, '**', '**', 'bold text'); break;
        case 'italic': wrap(ta, '_', '_', 'italic text'); break;
        case 'code': {
          const sel = ta.value.slice(ta.selectionStart, ta.selectionEnd);
          if (sel.includes('\n')) wrap(ta, '```\n', '\n```', ''); else wrap(ta, '`', '`', 'code');
          break;
        }
        case 'link': {
          const url = prompt('Link URL (https://…)', 'https://');
          if (!url) return;
          const sel = ta.value.slice(ta.selectionStart, ta.selectionEnd);
          wrap(ta, '[', '](' + url + ')', sel || 'link text');
          break;
        }
        case 'ul': prefixLines(ta, '- '); break;
        case 'ol': prefixLines(ta, '', true); break;
        case 'quote': prefixLines(ta, '> '); break;
        case 'h2': prefixLines(ta, '## '); break;
        case 'image': { const f = $('[data-editor-file]', bar); if (f) f.click(); break; }
      }
    }

    ta.addEventListener('keydown', (e) => {
      if (!(e.ctrlKey || e.metaKey) || e.shiftKey || e.altKey) return;
      const k = e.key.toLowerCase();
      if (k === 'b') { e.preventDefault(); apply('bold'); }
      else if (k === 'i') { e.preventDefault(); apply('italic'); }
      else if (k === 'k' && ta.selectionStart !== ta.selectionEnd) { e.preventDefault(); apply('link'); }
    });

    /* images: paste, drop, or the toolbar button */
    function upload(file) {
      if (!ta.dataset.uploadUrl) return;
      if (!/^image\//.test(file.type)) { toast('Only images can be pasted here', 'warn'); return; }
      if (file.size > 5 * 1024 * 1024) { toast('Images must be 5 MB or smaller', 'warn'); return; }
      const marker = '![Uploading ' + (file.name || 'image') + '…]()';
      insertAtCursor(ta, marker);
      const fd = new FormData();
      fd.append('image', file, file.name || 'pasted.png');
      postForm(ta.dataset.uploadUrl, fd)
        .then((r) => r.json().then((j) => ({ ok: r.ok, j })))
        .then(({ ok, j }) => {
          if (!ok || !j.url) throw new Error((j && j.error) || 'Upload failed');
          const at = ta.value.indexOf(marker);
          if (at >= 0) replaceRange(ta, at, at + marker.length, '![image](' + j.url + ')', at + 9 + j.url.length + 1, at + 9 + j.url.length + 1);
        })
        .catch((err) => {
          const at = ta.value.indexOf(marker);
          if (at >= 0) replaceRange(ta, at, at + marker.length, '', at, at);
          toast(err.message || 'Upload failed', 'bad');
        });
    }
    ta.addEventListener('paste', (e) => {
      const files = Array.from((e.clipboardData && e.clipboardData.files) || []).filter((f) => /^image\//.test(f.type));
      if (!files.length) return;
      e.preventDefault();
      files.forEach(upload);
    });
    ta.addEventListener('dragover', (e) => { if (ta.dataset.uploadUrl) { e.preventDefault(); ta.classList.add('ring-2', 'ring-brand-100'); } });
    ta.addEventListener('dragleave', () => ta.classList.remove('ring-2', 'ring-brand-100'));
    ta.addEventListener('drop', (e) => {
      ta.classList.remove('ring-2', 'ring-brand-100');
      const files = Array.from((e.dataTransfer && e.dataTransfer.files) || []);
      if (!files.length || !ta.dataset.uploadUrl) return;
      e.preventDefault();
      files.forEach(upload);
    });
    const fileInput = $('[data-editor-file]', bar);
    if (fileInput) fileInput.addEventListener('change', () => { Array.from(fileInput.files).forEach(upload); fileInput.value = ''; });

    // Submitting while on Preview would post fine, but be tidy: switch back.
    const form = ta.closest('form');
    if (form) form.addEventListener('submit', () => ed.showWrite());

    initCanned(ta, ed);
    ta._thEd = ed;
  }

  /* "Save as response": copy the reply into the modal that app.js opened. */
  document.addEventListener('click', (e) => {
    const el = e.target.closest('[data-modal="saveCanned"]');
    if (!el) return;
    const src = $('#composer');
    setTimeout(() => {
      const t = $('#modalRoot textarea[name=body]');
      if (t && src && !t.value) t.value = src.value;
    }, 30);
  });

  function boot() { $$('textarea[data-editor]').forEach(initEditor); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
  window.THEditor = { init: initEditor };
})();
