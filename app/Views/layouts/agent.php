<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
<?= view('partials/head', ['title' => ($title ?? 'TicketHub') . ' · TicketHub']) ?>
</head>
<body class="h-full">
<div class="h-full flex">

  <?php
    $navItems = [
        ['dashboard', 'Dashboard', 'grid', null],
        ['tickets', 'Tickets', 'inbox', $navOpenCount ?? null],
        ['problems', 'Problems', 'warn', null],
        ['changes', 'Changes', 'branch', ($navApprovals ?? 0) ?: null],
        ['assets', 'Assets', 'server', null],
        ['catalog', 'Service catalog', 'layers', null],
        ['kb', 'Knowledge', 'book', null],
        ['reports', 'Reports', 'chart', null],
    ];
    if (($me['role'] ?? '') === 'Administrator') {
        $navItems[] = ['admin', 'Admin', 'cog', null];
    }
    $cur = $nav ?? 'dashboard';
  ?>
  <aside id="sidebar" class="sidebar fixed lg:static z-40 inset-y-0 left-0 w-[218px] bg-ink flex flex-col shrink-0 -translate-x-full lg:translate-x-0 transition-transform">
    <div class="h-14 flex items-center gap-2.5 px-4 border-b border-white/[.07]">
      <span class="w-7 h-7 rounded-md bg-brand grid place-items-center font-display font-bold text-[13px] text-white">TH</span>
      <div class="leading-none">
        <div class="font-display font-semibold text-[13px] text-white">TicketHub</div>
        <div class="text-[10px] tracking-[.14em] text-[#6C7688] uppercase mt-[3px]">Service desk</div>
      </div>
      <button data-action="closeNav" class="lg:hidden ml-auto text-[#6C7688] hover:text-white"><?= th_icon('x') ?></button>
    </div>
    <nav class="flex-1 overflow-y-auto p-2.5 space-y-0.5 relative">
      <?php foreach ($navItems as [$view, $label, $ic, $count]): $on = $cur === $view; ?>
      <a href="<?= site_url('app/' . $view) ?>" class="group relative w-full flex items-center gap-2.5 h-9 px-2.5 rounded-lg text-[13px] font-medium transition
        <?= $on ? 'bg-white/[.09] text-white' : 'text-[#9AA3B4] hover:text-white hover:bg-white/[.05]' ?>">
        <span class="<?= $on ? 'text-brand-100' : 'text-[#6C7688] group-hover:text-[#9AA3B4]' ?>"><?= th_icon($ic, 'w-[17px] h-[17px]') ?></span>
        <span class="flex-1 text-left"><?= $label ?></span>
        <?php if ($count): ?><span class="font-mono text-[11px] <?= $on ? 'text-white' : 'text-[#6C7688]' ?>"><?= $count ?></span><?php endif ?>
        <?php if ($on): ?><i class="absolute -left-2.5 top-1/2 -translate-y-1/2 w-[3px] h-5 rounded-r bg-brand-100"></i><?php endif ?>
      </a>
      <?php endforeach ?>
    </nav>
    <div class="p-2.5 border-t border-white/[.07]">
      <button data-modal="userMenu" class="w-full flex items-center gap-2.5 p-1.5 rounded-lg hover:bg-white/[.05] text-left">
        <?= th_avatar($me, 30) ?>
        <span class="min-w-0 flex-1">
          <span class="block text-[12.5px] font-medium text-white truncate"><?= esc($me['name']) ?></span>
          <span class="block text-[11px] text-[#6C7688] truncate"><?= esc($me['role']) ?></span>
        </span>
        <span class="text-[#6C7688]"><?= th_icon('down', 'w-3.5 h-3.5') ?></span>
      </button>
    </div>
  </aside>

  <div id="navScrim" class="hidden fixed inset-0 bg-ink/40 z-30 lg:hidden"></div>

  <div class="flex-1 flex flex-col min-w-0">
    <header class="h-14 bg-white border-b border-line flex items-center gap-3 px-4 shrink-0">
      <button data-action="openNav" class="lg:hidden text-muted"><?= th_icon('grid', 'w-5 h-5') ?></button>
      <button data-modal="palette" class="group flex items-center gap-2 h-9 w-full max-w-[340px] px-3 rounded-lg border border-line bg-canvas hover:bg-white hover:border-[#CBD1DC] transition text-left">
        <span class="text-faint"><?= th_icon('search', 'w-4 h-4') ?></span>
        <span class="text-[13px] text-faint flex-1">Search tickets, people, assets…</span>
        <kbd class="font-mono text-[10px] text-faint border border-line bg-white rounded px-1.5 py-0.5">Ctrl K</kbd>
      </button>
      <div class="flex-1"></div>
      <?php if (! empty($overdueCount)): ?>
      <a href="<?= site_url('app/tickets?view=overdue') ?>" class="hidden md:inline-flex items-center gap-1.5 h-8 px-2.5 rounded-lg bg-alert-50 border border-alert-100 text-alert text-[12px] font-semibold">
        <?= th_icon('warn', 'w-3.5 h-3.5') ?> <?= $overdueCount ?> past due</a>
      <?php endif ?>
      <button data-modal="newTicket" class="inline-flex items-center gap-1.5 h-9 px-3 rounded-lg bg-brand hover:bg-brand-600 text-white text-[13px] font-semibold transition">
        <?= th_icon('plus', 'w-4 h-4') ?> <span class="hidden sm:inline">New ticket</span></button>
      <button data-fetch-modal="<?= site_url('app/notifications') ?>" class="relative w-9 h-9 grid place-items-center rounded-lg text-muted hover:bg-canvas" aria-label="Notifications">
        <?= th_icon('bell', 'w-[18px] h-[18px]') ?><?php if (! empty($overdueCount) || ! empty($navApprovals)): ?><i class="absolute top-2 right-2 w-1.5 h-1.5 rounded-full bg-alert"></i><?php endif ?></button>
      <button data-modal="userMenu" class="lg:hidden"><?= th_avatar($me, 30) ?></button>
    </header>
    <main id="view" class="flex-1 overflow-y-auto">
      <?= $this->renderSection('content') ?>
    </main>
  </div>
</div>

<div id="modalRoot"></div>
<div id="toastRoot" class="fixed bottom-5 left-1/2 -translate-x-1/2 z-[90] flex flex-col items-center gap-2"></div>

<!-- ============ shared modal templates ============ -->
<template id="tpl-newTicket">
  <form method="post" action="<?= site_url('app/tickets') ?>" enctype="multipart/form-data" data-modal-title="New ticket" data-modal-sub="Raise on behalf of someone who called or walked up" data-modal-width="max-w-2xl" data-submit="Create ticket">
    <?= csrf_field() ?>
    <div class="grid sm:grid-cols-2 gap-3.5">
      <div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Subject<span class="text-alert"> *</span></label>
        <input name="subject" required placeholder="One line the requester would recognise" autocomplete="off"
          data-kb-suggest="<?= site_url('app/kb/suggest') ?>"
          class="w-full h-9 px-2.5 rounded-lg border border-line bg-white text-[13px] placeholder:text-faint focus:border-brand">
        <div data-kb-results class="mt-2 empty:mt-0"></div></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Requester</label>
        <select name="requester_id" class="w-full h-9 px-2.5 rounded-lg border border-line bg-white text-[13px]">
          <?php foreach ($requesters as $u): ?><option value="<?= $u['id'] ?>"><?= esc($u['name']) ?> — <?= esc($u['dept']) ?></option><?php endforeach ?>
        </select></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Source</label>
        <select name="source" class="w-full h-9 px-2.5 rounded-lg border border-line bg-white text-[13px]">
          <?php foreach (TH_SOURCES as $s): ?><option><?= $s ?></option><?php endforeach ?>
        </select></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Type</label>
        <select name="type" class="w-full h-9 px-2.5 rounded-lg border border-line bg-white text-[13px]">
          <option>Incident</option><option>Service request</option>
        </select></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Category</label>
        <select name="category" class="w-full h-9 px-2.5 rounded-lg border border-line bg-white text-[13px]">
          <?php foreach (TH_CATEGORIES as $c): ?><option><?= $c ?></option><?php endforeach ?>
        </select></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Priority</label>
        <select name="priority" class="w-full h-9 px-2.5 rounded-lg border border-line bg-white text-[13px]">
          <option>Urgent</option><option>High</option><option selected>Medium</option><option>Low</option>
        </select></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Group</label>
        <select name="group_id" class="w-full h-9 px-2.5 rounded-lg border border-line bg-white text-[13px]">
          <option value="">Automatic — route by rules</option>
          <?php foreach ($groups as $g): ?><option value="<?= $g['id'] ?>"><?= esc($g['name']) ?></option><?php endforeach ?>
        </select></div>
      <div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Assign to</label>
        <select name="agent_id" class="w-full h-9 px-2.5 rounded-lg border border-line bg-white text-[13px]">
          <option value="">Leave unassigned</option>
          <?php foreach ($assignableAgents as $a): ?><option value="<?= $a['id'] ?>"><?= esc($a['name']) ?></option><?php endforeach ?>
        </select></div>
      <?php foreach (($ticketFields ?? []) as $tf): if ((int) $tf['agents'] === 1): ?>
        <?php if ($tf['type'] === 'Paragraph'): ?><div class="sm:col-span-2"><?= th_custom_field($tf) ?></div>
        <?php else: ?><?= th_custom_field($tf) ?><?php endif ?>
      <?php endif; endforeach ?>
      <div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Description<span class="text-alert"> *</span></label>
        <textarea name="body" rows="4" required placeholder="What happened, what they have already tried, and what good looks like." class="w-full px-2.5 py-2 rounded-lg border border-line text-[13px] leading-relaxed placeholder:text-faint focus:border-brand"></textarea></div>
      <div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Attachments</label>
        <input type="file" name="files[]" multiple class="w-full text-[12.5px] text-muted file:mr-3 file:h-8 file:px-3 file:rounded-lg file:border file:border-line file:bg-white file:text-[12.5px] file:font-medium file:text-ink-500 file:cursor-pointer">
        <p class="text-[11.5px] text-faint mt-1">Up to 10 MB each — images, PDF, Office, logs, zip.</p></div>
    </div>
  </form>
</template>

<template id="tpl-userMenu">
  <div data-modal-title="Workspace" data-modal-sub="Signed in as <?= esc($me['name'], 'attr') ?> · <?= esc($me['role'], 'attr') ?>" data-modal-width="max-w-md">
    <div class="grid grid-cols-2 gap-2.5 mb-4">
      <a href="<?= site_url('app/dashboard') ?>" class="text-left p-3 rounded-xl border transition border-brand bg-brand-50">
        <span class="text-brand"><?= th_icon('grid', 'w-4 h-4') ?></span>
        <span class="block text-[13px] font-semibold mt-2">Agent workspace</span>
        <span class="block text-[11.5px] text-muted mt-0.5">Queues, SLAs, admin</span></a>
      <a href="<?= site_url('portal') ?>" class="text-left p-3 rounded-xl border transition border-line hover:border-[#CBD1DC]">
        <span class="text-faint"><?= th_icon('user', 'w-4 h-4') ?></span>
        <span class="block text-[13px] font-semibold mt-2">Employee portal</span>
        <span class="block text-[11.5px] text-muted mt-0.5">Self-service view</span></a>
    </div>
    <div class="flex items-center gap-2.5 p-3 rounded-xl border border-line">
      <?= th_avatar($me, 36) ?>
      <div class="min-w-0">
        <div class="text-[13.5px] font-semibold text-ink truncate"><?= esc($me['name']) ?></div>
        <div class="text-[12px] text-muted truncate"><?= esc($me['email']) ?></div>
      </div>
    </div>
    <div class="flex items-center justify-between mt-4">
      <button data-modal="changePassword" class="inline-flex items-center gap-1.5 h-9 px-3.5 rounded-lg border border-line text-[13px] font-medium text-ink-500 hover:bg-canvas"><?= th_icon('lock', 'w-4 h-4') ?> Change password</button>
      <a href="<?= site_url('logout') ?>" class="inline-flex items-center gap-1.5 h-9 px-3.5 rounded-lg border border-line text-[13px] font-medium text-muted hover:bg-canvas"><?= th_icon('logout', 'w-4 h-4') ?> Sign out</a>
    </div>
  </div>
</template>

<template id="tpl-changePassword">
  <form method="post" action="<?= site_url('account/password') ?>" data-modal-title="Change password" data-modal-sub="Applies immediately across the workspace and portal" data-submit="Change password">
    <?= csrf_field() ?>
    <div class="grid gap-3.5">
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Current password</label>
        <input name="current" type="password" required class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">New password</label>
        <input name="password" type="password" required minlength="8" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Repeat the new one</label>
        <input name="confirm" type="password" required minlength="8" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
    </div>
  </form>
</template>

<template id="tpl-palette">
  <div data-modal-title="Jump to" data-modal-sub="Tickets, people, assets, articles and pages" data-modal-width="max-w-xl">
    <input id="paletteInput" data-palette-url="<?= site_url('app/search') ?>" placeholder="Type to search…" autocomplete="off" class="w-full h-10 px-3 rounded-lg border border-line text-[14px] mb-3 focus:border-brand">
    <div id="paletteResults" class="max-h-[340px] overflow-y-auto -mx-1"></div>
  </div>
</template>

<?= $this->renderSection('modals') ?>

<?php $toast = session()->getFlashdata('toast'); ?>
<script>
window.TH = {
  csrfName: '<?= csrf_token() ?>',
  csrfValue: '<?= csrf_hash() ?>',
  appBase: '<?= site_url('app') ?>',
  toast: <?= $toast ? json_encode($toast) : 'null' ?>
};
</script>
<script src="<?= base_url('assets/js/app.js') ?>"></script>
</body>
</html>
