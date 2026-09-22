/* TicketHub client runtime — modals, palette, toasts, bulk bar, composer. */
(function () {
  'use strict';

  const $ = (s, el) => (el || document).querySelector(s);
  const $$ = (s, el) => Array.from((el || document).querySelectorAll(s));
  const modalRoot = $('#modalRoot');

  /* Every background fetch must go through this. The header value has to be
     exactly XMLHttpRequest — that is what CodeIgniter's isAJAX() looks for, and
     it is how the framework knows not to record the URL as "previous". Fetch a
     fragment without it and the next redirect()->back() lands the browser on a
     bare, layout-less modal body. */
  const fetchFragment = (url) => fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });

  /* ---------- toast ---------- */
  const ICON_OK = '<svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>';
  const ICON_WARN = '<svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M10.3 3.7 1.9 18a2 2 0 0 0 1.7 3h16.8a2 2 0 0 0 1.7-3L13.7 3.7a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/></svg>';

  function toast(msg, kind) {
    kind = kind || 'ok';
    const tones = { ok: 'bg-ink text-white', warn: 'bg-signal text-white', bad: 'bg-alert text-white' };
    const el = document.createElement('div');
    el.className = 'pop-in flex items-center gap-2 h-10 px-3.5 rounded-xl shadow-pop text-[13px] font-medium ' + (tones[kind] || tones.ok);
    el.innerHTML = (kind === 'ok' ? ICON_OK : ICON_WARN) + '<span></span>';
    el.lastChild.textContent = msg;
    $('#toastRoot').appendChild(el);
    setTimeout(() => {
      el.style.opacity = '0';
      el.style.transform = 'translateY(6px)';
      setTimeout(() => el.remove(), 200);
    }, 2800);
  }
  window.thToast = toast;
  if (window.TH && TH.toast) toast(TH.toast.msg, TH.toast.kind);

  /* ---------- modal shell ---------- */
  function closeModal() {
    modalRoot.innerHTML = '';
  }

  function openModalShell(title, sub, width, bodyNode, footerHTML) {
    modalRoot.innerHTML =
      '<div class="fixed inset-0 z-[80] flex items-start justify-center p-4 sm:p-8 overflow-y-auto">' +
      '<div data-modal-backdrop class="fixed inset-0 bg-ink/40 backdrop-blur-[2px]"></div>' +
      '<div role="dialog" aria-modal="true" class="relative w-full ' + (width || 'max-w-lg') + ' bg-white rounded-2xl shadow-pop pop-in my-auto">' +
      '<div class="flex items-start gap-3 px-5 py-4 border-b border-line">' +
      '<div class="flex-1"><h2 class="font-display text-[17px] font-semibold"></h2>' +
      '<p class="text-[12.5px] text-muted mt-0.5 hidden"></p></div>' +
      '<button data-close-modal class="w-8 h-8 -mr-1 grid place-items-center rounded-lg text-faint hover:bg-canvas hover:text-ink" aria-label="Close">' +
      '<svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg></button></div>' +
      // These carry shell-specific names: a fetched fragment may itself have
      // data-modal-footer, and it sits earlier in the DOM — querying the generic
      // attribute would overwrite the fragment's own content with the footer.
      '<div class="px-5 py-4" data-shell-body></div>' +
      '<div class="hidden items-center gap-2 px-5 py-3.5 border-t border-line bg-canvas rounded-b-2xl" data-shell-footer></div>' +
      '</div></div>';

    $('h2', modalRoot).textContent = title || '';
    if (sub) {
      const p = $('p', modalRoot);
      p.textContent = sub;
      p.classList.remove('hidden');
    }
    $('[data-shell-body]', modalRoot).appendChild(bodyNode);
    const isDataEntry = bodyNode.tagName === 'FORM' || !!bodyNode.querySelector('form[data-primary]');
    const backdrop = $('[data-modal-backdrop]', modalRoot);
    if (isDataEntry) {
      // Guarded: outside click does not close it, so a stray click never costs
      // someone a half-written ticket. It nudges the dialog instead of doing
      // nothing, so the click still reads as "seen", not "broken".
      backdrop.addEventListener('click', () => {
        const dialog = $('[role="dialog"]', modalRoot);
        if (!dialog) return;
        dialog.classList.remove('shake-no');
        void dialog.offsetWidth;
        dialog.classList.add('shake-no');
      });
    } else {
      backdrop.setAttribute('data-close-modal', '');
    }
    if (footerHTML) {
      const f = $('[data-shell-footer]', modalRoot);
      f.innerHTML = footerHTML;
      f.classList.remove('hidden');
      f.classList.add('flex');
    }
    const first = $('[data-shell-body] input:not([type=hidden]), [data-shell-body] textarea, [data-shell-body] select', modalRoot);
    if (first) setTimeout(() => first.focus(), 40);
  }

  /* Open a modal from a <template id="tpl-NAME">.
     Root may be a <form>, or a <div> wrapping a primary form (form[data-primary]) plus sibling forms. */
  function openTemplateModal(name) {
    const tpl = $('#tpl-' + name);
    if (!tpl) return;
    const node = tpl.content.firstElementChild.cloneNode(true);
    const primary = node.tagName === 'FORM' ? node : $('form[data-primary]', node);
    const footer = primary
      ? '<button type="button" data-close-modal class="h-9 px-3.5 rounded-lg border border-line text-[13px] font-medium text-ink-500 hover:bg-white">Cancel</button>' +
        '<button type="button" data-submit-modal class="h-9 px-3.5 rounded-lg bg-brand hover:bg-brand-600 text-white text-[13px] font-semibold">' + (node.dataset.submit || primary.dataset.submit || 'Save') + '</button>'
      : '';
    openModalShell(node.dataset.modalTitle, node.dataset.modalSub, node.dataset.modalWidth, node, footer);
    bindKbSuggest(modalRoot);
    if (name === 'palette') initPalette();
  }

  /* Fetch server-rendered modal fragment. Fragment root carries data-modal-title etc.
     A non-2xx answer (expired session, missing record) or a network failure is
     said out loud rather than swallowed — a click that does nothing reads as a bug. */
  function openFetchModal(url) {
    fetchFragment(url)
      .then((r) => {
        if (!r.ok) {
          const why = r.status === 401 || r.status === 403 ? 'Your session may have expired — reload the page'
            : r.status === 404 ? 'That record no longer exists'
            : 'The server answered ' + r.status;
          throw new Error(why);
        }
        return r.text();
      })
      .then((html) => {
        const wrap = document.createElement('div');
        wrap.innerHTML = html.trim();
        const node = wrap.firstElementChild;
        if (!node) {
          toast('Nothing came back to show', 'warn');
          return;
        }
        const isForm = node.tagName === 'FORM';
        const footer = node.dataset.modalFooter ||
          (isForm
            ? '<button type="button" data-close-modal class="h-9 px-3.5 rounded-lg border border-line text-[13px] font-medium text-ink-500 hover:bg-white">Cancel</button>' +
              '<button type="button" data-submit-modal class="h-9 px-3.5 rounded-lg bg-brand hover:bg-brand-600 text-white text-[13px] font-semibold">' + (node.dataset.submit || 'Save') + '</button>'
            : '');
        openModalShell(node.dataset.modalTitle, node.dataset.modalSub, node.dataset.modalWidth, node, footer);
        initLiveSearch();
      })
      .catch((err) => {
        toast((err && err.message && !/fetch/i.test(err.message)) ? err.message : 'Could not load that — check your connection and try again', 'bad');
      });
  }

  /* Copy-to-clipboard for <button data-copy="text">: Share links and the like.
     Falls back to a hidden textarea + execCommand where the async API is unavailable (http, old browsers). */
  function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(text);
    }
    return new Promise((resolve, reject) => {
      const ta = document.createElement('textarea');
      ta.value = text;
      ta.setAttribute('readonly', '');
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.select();
      let ok = false;
      try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
      ta.remove();
      ok ? resolve() : reject(new Error('copy failed'));
    });
  }

  /* Live search inside a picker dialog (merge, problem linking) — re-renders
     only the result list, so search and suggestions share one server code path. */
  function initLiveSearch() {
    const input = $('[data-live-search]', modalRoot);
    const list = $('[data-live-results]', modalRoot);
    if (!input || !list) return;
    const caption = $('[data-live-caption]', modalRoot);
    let seq = 0;
    let timer = null;

    const run = () => {
      const mine = ++seq;
      const q = input.value.trim();
      if (caption) caption.textContent = q ? caption.dataset.search : caption.dataset.suggest;
      fetchFragment(input.dataset.liveSearch + (input.dataset.liveSearch.includes('?') ? '&' : '?') + 'list=1&q=' + encodeURIComponent(q))
        .then((r) => r.text())
        .then((html) => {
          if (mine !== seq) return;
          list.innerHTML = html;
        });
    };

    input.addEventListener('input', () => {
      clearTimeout(timer);
      timer = setTimeout(run, 180);
    });
    // Enter should filter, not submit a half-finished choice.
    input.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        clearTimeout(timer);
        run();
      }
    });
  }

  /* Confirmation modal for destructive forms: <form data-confirm="msg" data-confirm-label="Delete"> */
  function openConfirmModal(form) {
    const body = document.createElement('p');
    body.className = 'text-[13.5px] text-muted leading-relaxed';
    body.textContent = form.dataset.confirm;
    openModalShell(
      form.dataset.confirmTitle || 'Are you sure?',
      '',
      'max-w-md',
      body,
      '<button type="button" data-close-modal class="h-9 px-3.5 rounded-lg border border-line text-[13px] font-medium hover:bg-white">Cancel</button>' +
        '<button type="button" data-confirm-yes class="h-9 px-3.5 rounded-lg bg-alert text-white text-[13px] font-semibold hover:bg-[#A32A25]">' + (form.dataset.confirmLabel || 'Confirm') + '</button>'
    );
    $('[data-confirm-yes]', modalRoot).addEventListener('click', () => {
      form.dataset.confirmed = '1';
      closeModal();
      form.submit();
    });
  }

  /* ---------- command palette ---------- */
  function initPalette() {
    const input = $('#paletteInput', modalRoot);
    const results = $('#paletteResults', modalRoot);
    if (!input) return;
    const url = input.dataset.paletteUrl;
    let seq = 0;

    function render(items, q) {
      if (!items.length) {
        results.innerHTML = '<p class="px-3 py-6 text-center text-[13px] text-muted"></p>';
        results.firstChild.textContent = 'Nothing matches "' + q + '".';
        return;
      }
      results.innerHTML = items
        .map(
          (h) =>
            '<a href="' + h.url + '" class="w-full flex items-center gap-3 px-3 h-11 rounded-lg hover:bg-canvas text-left">' +
            '<span class="text-faint">' + (h.icon || '') + '</span>' +
            '<span class="text-[13px] text-ink truncate flex-1" data-label></span>' +
            '<span class="font-mono text-[11px] text-faint shrink-0" data-meta></span></a>'
        )
        .join('');
      $$('a', results).forEach((a, i) => {
        $('[data-label]', a).textContent = items[i].label;
        $('[data-meta]', a).textContent = items[i].meta;
      });
    }

    function search(q) {
      const mine = ++seq;
      fetchFragment(url + '?q=' + encodeURIComponent(q))
        .then((r) => r.json())
        .then((items) => {
          if (mine === seq) render(items, q);
        });
    }
    search('');
    input.addEventListener('input', () => search(input.value));
  }

  /* ---------- knowledge deflection ----------
     Suggest articles as a subject is typed, on both the portal form and the
     agent modal. Delegated + re-scanned on modal open, since the agent form
     only exists once the template is cloned. */
  function bindKbSuggest(root) {
    $$('[data-kb-suggest]', root || document).forEach((input) => {
      if (input.dataset.kbBound) return;
      input.dataset.kbBound = '1';
      const box = input.parentNode.querySelector('[data-kb-results]');
      if (!box) return;
      let seq = 0;
      let timer = null;

      const run = () => {
        const mine = ++seq;
        const q = input.value.trim();
        if (q.length < 4) {
          box.innerHTML = '';
          return;
        }
        fetchFragment(input.dataset.kbSuggest + '?q=' + encodeURIComponent(q))
          .then((r) => r.text())
          .then((html) => {
            if (mine === seq) box.innerHTML = html;
          })
          .catch(() => {});
      };

      input.addEventListener('input', () => {
        clearTimeout(timer);
        timer = setTimeout(run, 250);
      });
    });
  }
  bindKbSuggest();

  /* ---------- portal live search ---------- */
  const portalSearch = $('#portalSearch');
  if (portalSearch) {
    const box = $('#portalResults');
    let seq = 0;
    portalSearch.addEventListener('input', () => {
      const q = portalSearch.value.trim();
      if (!q) {
        box.classList.add('hidden');
        return;
      }
      const mine = ++seq;
      fetchFragment(portalSearch.dataset.searchUrl + '?q=' + encodeURIComponent(q))
        .then((r) => r.text())
        .then((html) => {
          if (mine !== seq) return;
          box.innerHTML = html;
          box.classList.remove('hidden');
        });
    });
    document.addEventListener('click', (e) => {
      if (!box.contains(e.target) && e.target !== portalSearch) box.classList.add('hidden');
    });
  }

  /* ---------- ticket list: bulk selection ---------- */
  const bulkForm = $('#bulkForm');
  if (bulkForm) {
    const bar = $('#bulkBar');
    const count = $('#bulkCount');

    function refresh() {
      const checked = $$('input[data-check]').filter((c) => c.checked);
      $$('input[name="ids[]"]', bulkForm).forEach((n) => n.remove());
      checked.forEach((c) => {
        const h = document.createElement('input');
        h.type = 'hidden';
        h.name = 'ids[]';
        h.value = c.dataset.check;
        bulkForm.appendChild(h);
      });
      if (checked.length) {
        bar.classList.remove('hidden');
        bar.classList.add('flex');
        count.textContent = checked.length + ' selected';
      } else {
        bar.classList.add('hidden');
        bar.classList.remove('flex');
      }
      $$('[data-row]').forEach((row) => {
        const c = $('input[data-check]', row);
        row.classList.toggle('bg-brand-50', c && c.checked);
      });
    }

    document.addEventListener('change', (e) => {
      if (e.target.matches('input[data-check-all]')) {
        $$('input[data-check]').forEach((c) => (c.checked = e.target.checked));
        refresh();
      } else if (e.target.matches('input[data-check]')) {
        refresh();
      } else if (e.target.matches('select[data-bulk]')) {
        if (!e.target.value) return;
        $('#bulkAction').value = e.target.dataset.bulk;
        $('#bulkValue').value = e.target.value;
        bulkForm.submit();
      }
    });

    $$('[data-bulk-submit]').forEach((btn) =>
      btn.addEventListener('click', () => {
        const ids = $$('input[name="ids[]"]', bulkForm);
        if (btn.dataset.bulkSubmit === 'merge' && ids.length < 2) {
          toast('Select at least two tickets to merge', 'warn');
          return;
        }
        if (btn.dataset.bulkSubmit === 'delete' && !window.confirm('Delete ' + ids.length + ' selected ticket(s)?')) return;
        $('#bulkAction').value = btn.dataset.bulkSubmit;
        $('#bulkValue').value = '';
        bulkForm.submit();
      })
    );
    $$('[data-clear-selection]').forEach((btn) =>
      btn.addEventListener('click', () => {
        $$('input[data-check]').forEach((c) => (c.checked = false));
        const all = $('input[data-check-all]');
        if (all) all.checked = false;
        refresh();
      })
    );
  }

  /* ---------- composer (ticket detail) ---------- */
  const composerForm = $('#composerForm');
  if (composerForm) {
    const kindInput = $('input[name=kind]', composerForm);
    const textarea = $('#composer');
    const sendBtn = $('#composerSend');
    const sendLabel = $('#composerSendLabel');

    $$('[data-composer-tab]').forEach((tab) =>
      tab.addEventListener('click', () => {
        const kind = tab.dataset.composerTab;
        kindInput.value = kind;
        $$('[data-composer-tab]').forEach((t) => {
          const on = t === tab;
          const isReply = t.dataset.composerTab === 'reply';
          // Active: filled + matching border. Inactive: ghost button.
          t.classList.toggle('bg-ink', on && isReply);
          t.classList.toggle('border-ink', on && isReply);
          t.classList.toggle('bg-signal', on && !isReply);
          t.classList.toggle('border-signal', on && !isReply);
          t.classList.toggle('text-white', on);
          t.classList.toggle('bg-white', !on);
          t.classList.toggle('text-ink-500', !on);
          t.classList.toggle('border-line', !on);
          // Hover fill only on the inactive tab, or it repaints the active tab
          // light grey and its white label disappears.
          t.classList.toggle('hover:bg-canvas', !on);
        });
        textarea.placeholder = kind === 'reply' ? textarea.dataset.phReply : textarea.dataset.phNote;
        sendLabel.textContent = kind === 'reply' ? 'Send reply' : 'Save note';
        sendBtn.classList.toggle('bg-brand', kind === 'reply');
        sendBtn.classList.toggle('hover:bg-brand-600', kind === 'reply');
        sendBtn.classList.toggle('bg-signal', kind === 'note');
        sendBtn.classList.toggle('hover:bg-[#9C6509]', kind === 'note');
      })
    );

    document.addEventListener('change', (e) => {
      if (e.target.matches('select[data-canned]')) {
        const body = e.target.selectedOptions[0] ? e.target.selectedOptions[0].dataset.body : '';
        if (body) {
          textarea.value = body;
          textarea.focus();
        }
        e.target.value = '';
      }
    });

    document.addEventListener('keydown', (e) => {
      if ((e.metaKey || e.ctrlKey) && e.key === 'Enter' && document.activeElement === textarea) {
        e.preventDefault();
        composerForm.submit();
      }
    });
  }

  /* ---------- automation rule builder (admin) ---------- */
  function autoValueControl(type, value) {
    const A = window.THAUTO || {};
    const base = 'flex-1 min-w-0 h-9 px-2 rounded-lg border border-line bg-white text-[12.5px]';
    // Values come from admin-authored names, so build DOM nodes rather than
    // concatenating HTML — a group named "<img onerror=...>" must stay inert.
    const buildSelect = (pairs) => {
      const sel = document.createElement('select');
      sel.name = 'act_value[]';
      sel.className = base;
      pairs.forEach((p) => {
        const v = Array.isArray(p) ? p[0] : p;
        const l = Array.isArray(p) ? p[1] : p;
        const o = new Option(l, v, false, String(v) === String(value));
        sel.appendChild(o);
      });
      return sel.outerHTML;
    };

    switch (type) {
      case 'set_status': return buildSelect(A.statuses || []);
      case 'set_priority': return buildSelect(A.priorities || []);
      case 'move_group': return buildSelect(A.groups || []);
      case 'assign_agent': return buildSelect(A.agents || []);
      case 'email_template': return buildSelect(A.templates || []);
      case 'escalate': return '<input type="hidden" name="act_value[]" value=""><span class="text-[12px] text-faint self-center">no value needed</span>';
      case 'add_tag': return '<input name="act_value[]" placeholder="tag-name" class="flex-1 min-w-0 h-9 px-2.5 rounded-lg border border-line text-[13px]">';
      case 'webhook': return '<input name="act_value[]" type="url" placeholder="https://hooks.example.com/..." class="flex-1 min-w-0 h-9 px-2.5 rounded-lg border border-line text-[12.5px] font-mono">';
      default:
        return '<textarea name="act_value[]" rows="3" placeholder="Message — placeholders like {{ticket.id}}, {{requester.first}}, {{ticket.url}} work here" class="flex-1 min-w-0 px-2.5 py-2 rounded-lg border border-line text-[12.5px] font-mono leading-relaxed"></textarea>';
    }
  }

  document.addEventListener('click', (e) => {
    const add = e.target.closest('[data-auto-add]');
    if (add) {
      const kind = add.dataset.autoAdd;
      const rows = add.closest('div').parentNode.querySelector('[data-auto-rows="' + kind + '"]');
      const tpl = $('#tpl-auto' + (kind === 'cond' ? 'Cond' : 'Act') + 'Row');
      if (rows && tpl) rows.appendChild(tpl.content.firstElementChild.cloneNode(true));
      return;
    }
    const rm = e.target.closest('[data-auto-remove]');
    if (rm) rm.closest('[data-auto-row]').remove();
  });

  document.addEventListener('change', (e) => {
    if (e.target.matches('select[data-act-type]')) {
      const slot = e.target.closest('[data-auto-row]').querySelector('[data-value-slot]');
      if (slot) slot.innerHTML = autoValueControl(e.target.value, '');
    }
  });

  /* ---------- ticket row action menu ---------- */
  function openRowMenu(cfg) {
    const base = TH.appBase + '/tickets/' + encodeURIComponent(cfg.code);
    const postForm = (action, label, icon, tone, confirmMsg) =>
      '<form method="post" action="' + base + '/' + action + '"' +
      (confirmMsg ? ' data-confirm="' + confirmMsg + '" data-confirm-label="Delete" data-confirm-title="Delete this ticket?"' : '') + '>' +
      '<input type="hidden" name="' + TH.csrfName + '" value="' + TH.csrfValue + '">' +
      '<button type="submit" class="w-full flex items-center gap-2.5 h-9 px-2.5 rounded-lg hover:bg-canvas text-[13px] ' + (tone || 'text-ink-500') + '">' + icon + label + '</button></form>';

    const IC = {
      ext: '<svg class="w-4 h-4 text-faint" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M21 14v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5"/></svg>',
      user: '<svg class="w-4 h-4 text-faint" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>',
      check: '<svg class="w-4 h-4 text-faint" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>',
      warn: '<svg class="w-4 h-4 text-faint" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M10.3 3.7 1.9 18a2 2 0 0 0 1.7 3h16.8a2 2 0 0 0 1.7-3L13.7 3.7a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/></svg>',
      trash: '<svg class="w-4 h-4 text-faint" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14"/></svg>'
    };

    const wrap = document.createElement('div');
    wrap.className = '-mx-2';
    wrap.innerHTML =
      '<a href="' + base + '" class="w-full flex items-center gap-2.5 h-9 px-2.5 rounded-lg hover:bg-canvas text-[13px] text-ink-500">' + IC.ext + 'Open ticket</a>' +
      postForm('claim', 'Assign to me', IC.user) +
      (cfg.open ? postForm('resolve', 'Mark resolved', IC.check) : postForm('reopen', 'Reopen', IC.check)) +
      postForm('escalate', 'Escalate', IC.warn) +
      postForm('delete', 'Delete', IC.trash, 'text-alert', 'The ticket and its conversation will be removed. Requesters are not notified.');

    openModalShell(cfg.code, cfg.subject, 'max-w-xs', wrap, '');
  }

  /* ---------- global click handling ---------- */
  document.addEventListener('click', (e) => {
    const hit = (s) => e.target.closest(s);
    let el;

    if ((el = hit('[data-close-modal]'))) {
      closeModal();
      return;
    }
    if ((el = hit('[data-submit-modal]'))) {
      const form = $('form[data-primary]', modalRoot) || $('form', modalRoot);
      if (form) form.requestSubmit ? form.requestSubmit() : form.submit();
      return;
    }
    if ((el = hit('[data-row-menu]'))) {
      openRowMenu(JSON.parse(el.dataset.rowMenu));
      return;
    }
    if ((el = hit('[data-modal]'))) {
      openTemplateModal(el.dataset.modal);
      return;
    }
    if ((el = hit('[data-fetch-modal]'))) {
      openFetchModal(el.dataset.fetchModal);
      return;
    }
    if ((el = hit('[data-copy]'))) {
      e.preventDefault();
      copyText(el.dataset.copy)
        .then(() => toast('Copied'))
        .catch(() => toast('Could not copy — ' + el.dataset.copy, 'warn'));
      return;
    }
    if ((el = hit('[data-action="openNav"]'))) {
      $('#sidebar').classList.remove('-translate-x-full');
      $('#navScrim').classList.remove('hidden');
      return;
    }
    if ((el = hit('[data-action="closeNav"]')) || (e.target.id === 'navScrim')) {
      const sb = $('#sidebar');
      if (sb) sb.classList.add('-translate-x-full');
      const scrim = $('#navScrim');
      if (scrim) scrim.classList.add('hidden');
      return;
    }
  });

  /* Confirm-guarded forms */
  document.addEventListener(
    'submit',
    (e) => {
      const form = e.target;
      if (form.dataset.confirm && !form.dataset.confirmed) {
        e.preventDefault();
        openConfirmModal(form);
      }
    },
    true
  );

  /* Auto-submit selects/checkboxes: <select data-autosubmit> submits closest form */
  document.addEventListener('change', (e) => {
    if (e.target.matches('[data-autosubmit]')) {
      const f = e.target.closest('form');
      if (f) f.submit();
    }
  });

  /* Keyboard: Ctrl/Cmd-K palette, Escape closes modal */
  document.addEventListener('keydown', (e) => {
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
      if ($('#tpl-palette')) {
        e.preventDefault();
        openTemplateModal('palette');
      }
    }
    if (e.key === 'Escape') closeModal();
  });
})();
