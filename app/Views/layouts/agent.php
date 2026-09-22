<?php helper('i18n'); ?>
<!DOCTYPE html>
<html lang="<?= esc(th_locale(), 'attr') ?>" class="h-full">
<head>
<?= view('partials/head', ['title' => ($title ?? lang('Common.app')) . ' · ' . lang('Nav.titleSuffix')]) ?>
</head>
<body class="h-full">
<div class="h-full flex">

  <?php
    $navItems = [
        ['dashboard', lang('Nav.agent.dashboard'), 'grid', null],
        ['tickets', lang('Nav.agent.tickets'), 'inbox', $navOpenCount ?? null],
        ['problems', lang('Nav.agent.problems'), 'warn', null],
        ['changes', lang('Nav.agent.changes'), 'branch', ($navApprovals ?? 0) ?: null],
        ['assets', lang('Nav.agent.assets'), 'server', null],
        ['catalog', lang('Nav.agent.catalog'), 'layers', null],
        ['kb', lang('Nav.agent.kb'), 'book', null],
        ['reports', lang('Nav.agent.reports'), 'chart', null],
    ];
    if (($me['role'] ?? '') === 'Administrator') {
        $navItems[] = ['admin', lang('Nav.agent.admin'), 'cog', null];
    }
    $cur = $nav ?? 'dashboard';
  ?>
  <aside id="sidebar" class="sidebar fixed lg:static z-40 inset-y-0 left-0 w-[218px] bg-ink flex flex-col shrink-0 -translate-x-full lg:translate-x-0 transition-transform">
    <div class="h-14 flex items-center gap-2.5 px-4 border-b border-white/[.07]">
      <span class="w-7 h-7 rounded-md bg-brand grid place-items-center font-display font-bold text-[13px] text-white">TH</span>
      <div class="leading-none">
        <div class="font-display font-semibold text-[13px] text-white"><?= lang('Common.app') ?></div>
        <div class="text-[10px] tracking-[.14em] text-[#6C7688] uppercase mt-[3px]"><?= lang('Common.tagline') ?></div>
      </div>
      <button data-action="closeNav" class="lg:hidden ml-auto text-[#6C7688] hover:text-white" aria-label="<?= esc(lang('Nav.closeMenu'), 'attr') ?>"><?= th_icon('x') ?></button>
    </div>
    <nav class="flex-1 overflow-y-auto p-2.5 space-y-0.5 relative">
      <?php foreach ($navItems as [$view, $label, $ic, $count]): $on = $cur === $view; ?>
      <a href="<?= site_url('app/' . $view) ?>" class="group relative w-full flex items-center gap-2.5 h-9 px-2.5 rounded-lg text-[13px] font-medium transition
        <?= $on ? 'bg-white/[.09] text-white' : 'text-[#9AA3B4] hover:text-white hover:bg-white/[.05]' ?>">
        <span class="<?= $on ? 'text-brand-100' : 'text-[#6C7688] group-hover:text-[#9AA3B4]' ?>"><?= th_icon($ic, 'w-[17px] h-[17px]') ?></span>
        <span class="flex-1 text-left"><?= esc($label) ?></span>
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
          <span class="block text-[11px] text-[#6C7688] truncate"><?= esc(th_t('Common.role.' . $me['role'], $me['role'])) ?></span>
        </span>
        <span class="text-[#6C7688]"><?= th_icon('down', 'w-3.5 h-3.5') ?></span>
      </button>
      <div class="px-1.5 pt-2 pb-0.5 font-mono text-[10px] text-[#6C7688]"><?= esc(lang('Common.version', [TICKETHUB_VERSION])) ?></div>
    </div>
  </aside>

  <div id="navScrim" class="hidden fixed inset-0 bg-ink/40 z-30 lg:hidden"></div>

  <div class="flex-1 flex flex-col min-w-0">
    <header class="h-14 bg-white border-b border-line flex items-center gap-3 px-4 shrink-0">
      <button data-action="openNav" class="lg:hidden text-muted" aria-label="<?= esc(lang('Nav.openMenu'), 'attr') ?>"><?= th_icon('grid', 'w-5 h-5') ?></button>
      <button data-modal="palette" class="group flex items-center gap-2 h-9 w-full max-w-[340px] px-3 rounded-lg border border-line bg-canvas hover:bg-white hover:border-[#CBD1DC] transition text-left">
        <span class="text-faint"><?= th_icon('search', 'w-4 h-4') ?></span>
        <span class="text-[13px] text-faint flex-1"><?= lang('Nav.searchPlaceholder') ?></span>
        <kbd class="font-mono text-[10px] text-faint border border-line bg-white rounded px-1.5 py-0.5">Ctrl K</kbd>
      </button>
      <div class="flex-1"></div>
      <?php if (! empty($overdueCount)): ?>
      <a href="<?= site_url('app/tickets?view=overdue') ?>" class="hidden md:inline-flex items-center gap-1.5 h-8 px-2.5 rounded-lg bg-alert-50 border border-alert-100 text-alert text-[12px] font-semibold">
        <?= th_icon('warn', 'w-3.5 h-3.5') ?> <?= esc(lang('Nav.pastDue', [(int) $overdueCount])) ?></a>
      <?php endif ?>
      <button data-modal="newTicket" class="inline-flex items-center gap-1.5 h-9 px-3 rounded-lg bg-brand hover:bg-brand-600 text-white text-[13px] font-semibold transition">
        <?= th_icon('plus', 'w-4 h-4') ?> <span class="hidden sm:inline"><?= lang('Common.btn.newTicket') ?></span></button>
      <?= th_theme_toggle('hidden sm:grid') ?>
      <button data-fetch-modal="<?= site_url('app/notifications') ?>" class="relative w-9 h-9 grid place-items-center rounded-lg text-muted hover:bg-canvas" aria-label="<?= esc(! empty($unreadCount) ? lang('Nav.notificationsUnread', [(int) $unreadCount]) : lang('Nav.notifications'), 'attr') ?>">
        <?= th_icon('bell', 'w-[18px] h-[18px]') ?>
        <?php if (! empty($unreadCount)): ?>
          <span class="absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] px-1 rounded-full bg-alert text-white text-[10.5px] font-bold leading-[18px] text-center font-mono"><?= (int) $unreadCount > 99 ? '99+' : (int) $unreadCount ?></span>
        <?php elseif (! empty($overdueCount) || ! empty($navApprovals)): ?><i class="absolute top-2 right-2 w-1.5 h-1.5 rounded-full bg-alert"></i><?php endif ?></button>
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
<?= $this->include('agent/_new_ticket_modal') ?>

<template id="tpl-userMenu">
  <div data-modal-title="<?= esc(lang('Nav.menu.workspace'), 'attr') ?>" data-modal-sub="<?= esc(lang('Nav.menu.signedInAs', ['name' => $me['name'], 'role' => th_t('Common.role.' . $me['role'], $me['role'])]), 'attr') ?>" data-modal-width="max-w-md">
    <div class="grid grid-cols-2 gap-2.5 mb-4">
      <a href="<?= site_url('app/dashboard') ?>" class="text-left p-3 rounded-xl border transition border-brand bg-brand-50">
        <span class="text-brand"><?= th_icon('grid', 'w-4 h-4') ?></span>
        <span class="block text-[13px] font-semibold mt-2"><?= lang('Nav.menu.agentWorkspace') ?></span>
        <span class="block text-[11.5px] text-muted mt-0.5"><?= lang('Nav.menu.agentWorkspaceSub') ?></span></a>
      <a href="<?= site_url('portal') ?>" class="text-left p-3 rounded-xl border transition border-line hover:border-[#CBD1DC]">
        <span class="text-faint"><?= th_icon('user', 'w-4 h-4') ?></span>
        <span class="block text-[13px] font-semibold mt-2"><?= lang('Nav.menu.employeePortal') ?></span>
        <span class="block text-[11.5px] text-muted mt-0.5"><?= lang('Nav.menu.employeePortalSub') ?></span></a>
    </div>
    <div class="flex items-center gap-2.5 p-3 rounded-xl border border-line">
      <?= th_avatar($me, 36) ?>
      <div class="min-w-0">
        <div class="text-[13.5px] font-semibold text-ink truncate"><?= esc($me['name']) ?></div>
        <div class="text-[12px] text-muted truncate"><?= esc($me['email']) ?></div>
      </div>
    </div>
    <div class="mt-3 grid sm:grid-cols-2 gap-2.5">
      <div class="p-3 rounded-xl border border-line">
        <div class="text-[11px] font-semibold uppercase tracking-[.09em] text-faint mb-2"><?= lang('Nav.menu.appearance') ?></div>
        <?= th_theme_picker() ?>
      </div>
      <div class="p-3 rounded-xl border border-line">
        <div class="text-[11px] font-semibold uppercase tracking-[.09em] text-faint mb-2"><?= lang('Nav.menu.language') ?></div>
        <?= th_lang_switcher() ?>
      </div>
    </div>
    <div class="grid sm:grid-cols-2 gap-2.5 mt-2.5">
      <a href="<?= site_url('account/security') ?>" class="flex items-center gap-2 p-3 rounded-xl border border-line hover:border-[#CBD1DC] text-[13px] font-medium text-ink"><?= th_icon('shield', 'w-4 h-4 text-faint') ?> <?= lang('Nav.menu.security') ?></a>
      <a href="<?= site_url('account/notifications') ?>" class="flex items-center gap-2 p-3 rounded-xl border border-line hover:border-[#CBD1DC] text-[13px] font-medium text-ink"><?= th_icon('bell', 'w-4 h-4 text-faint') ?> <?= lang('Nav.menu.notificationPrefs') ?></a>
    </div>
    <div class="flex items-center justify-between mt-4">
      <button data-modal="changePassword" class="inline-flex items-center gap-1.5 h-9 px-3.5 rounded-lg border border-line text-[13px] font-medium text-ink-500 hover:bg-canvas"><?= th_icon('lock', 'w-4 h-4') ?> <?= lang('Nav.password.change') ?></button>
      <form method="post" action="<?= site_url('logout') ?>" class="inline"><?= csrf_field() ?>
        <button type="submit" class="inline-flex items-center gap-1.5 h-9 px-3.5 rounded-lg border border-line text-[13px] font-medium text-muted hover:bg-canvas"><?= th_icon('logout', 'w-4 h-4') ?> <?= lang('Common.btn.signOut') ?></button>
      </form>
    </div>
    <div class="mt-4 text-center font-mono text-[10.5px] text-faint"><?= esc(lang('Common.version', [TICKETHUB_VERSION])) ?></div>
  </div>
</template>

<template id="tpl-changePassword">
  <form method="post" action="<?= site_url('account/password') ?>" data-modal-title="<?= esc(lang('Nav.password.change'), 'attr') ?>" data-modal-sub="<?= esc(lang('Nav.password.changeSub'), 'attr') ?>" data-submit="<?= esc(lang('Nav.password.change'), 'attr') ?>">
    <?= csrf_field() ?>
    <div class="grid gap-3.5">
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5"><?= lang('Nav.password.current') ?></label>
        <input name="current" type="password" required class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5"><?= lang('Nav.password.new') ?></label>
        <input name="password" type="password" required minlength="8" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5"><?= lang('Nav.password.repeat') ?></label>
        <input name="confirm" type="password" required minlength="8" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
    </div>
  </form>
</template>

<template id="tpl-palette">
  <div data-modal-title="<?= esc(lang('Nav.palette.title'), 'attr') ?>" data-modal-sub="<?= esc(lang('Nav.palette.sub'), 'attr') ?>" data-modal-width="max-w-xl">
    <input id="paletteInput" data-palette-url="<?= site_url('app/search') ?>" placeholder="<?= esc(lang('Nav.palette.placeholder'), 'attr') ?>" autocomplete="off" class="w-full h-10 px-3 rounded-lg border border-line text-[14px] mb-3 focus:border-brand">
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
  locale: <?= json_encode(th_locale()) ?>,
  i18n: { theme: <?= json_encode(['system' => lang('Common.theme.system'), 'light' => lang('Common.theme.light'), 'dark' => lang('Common.theme.dark'), 'toggle' => lang('Common.theme.toggle', ['{0}'])]) ?> },
  toast: <?= $toast ? json_encode($toast) : 'null' ?>
};
</script>
<script src="<?= base_url('assets/js/theme.js') ?>"></script>
<script src="<?= base_url('assets/js/app.js') ?>"></script>
</body>
</html>
