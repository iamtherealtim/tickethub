<?php helper('i18n'); ?>
<!DOCTYPE html>
<html lang="<?= esc(th_locale(), 'attr') ?>" class="h-full">
<head>
<?= view('partials/head', ['title' => ($title ?? lang('Portal.home.title')) . ' · ' . lang('Nav.portal.titleSuffix')]) ?>
</head>
<body class="h-full">
<div class="min-h-full flex flex-col bg-canvas">
  <?php
    $openCount = $portalOpenCount ?? 0;
    $links = [
        ['portal', lang('Nav.portal.home'), ''],
        ['portal/catalog', lang('Nav.portal.catalog'), 'catalog'],
        ['portal/kb', lang('Nav.portal.kb'), 'kb'],
        ['portal/tickets', lang('Nav.portal.mytickets'), 'mytickets'],
    ];
    $cur = $pnav ?? '';
  ?>
  <header class="bg-white border-b border-line sticky top-0 z-30">
    <div class="max-w-[1120px] mx-auto px-5 h-16 flex items-center gap-6">
      <a href="<?= site_url('portal') ?>" class="flex items-center gap-2.5 shrink-0">
        <span class="w-7 h-7 rounded-md bg-ink grid place-items-center font-display font-bold text-[13px] text-white">TH</span>
        <span class="font-display font-semibold text-[14px] hidden sm:block"><?= lang('Common.app') ?> <span class="text-muted font-normal"><?= lang('Nav.portal.help') ?></span></span>
      </a>
      <nav class="flex items-center gap-1 flex-1 overflow-x-auto">
        <?php foreach ($links as [$path, $label, $key]): ?>
        <a href="<?= site_url($path) ?>" class="h-8 px-2.5 rounded-lg text-[13px] font-medium whitespace-nowrap transition inline-flex items-center
          <?= $cur === $key ? 'bg-canvas text-ink' : 'text-muted hover:text-ink' ?>"><?= esc($label) ?><?php if ($key === 'mytickets' && $openCount): ?><span class="ml-1 font-mono text-[11px] text-brand"><?= $openCount ?></span><?php endif ?></a>
        <?php endforeach ?>
      </nav>
      <a href="<?= site_url('portal/new') ?>" class="inline-flex items-center gap-1.5 h-9 px-3 rounded-lg bg-ink hover:bg-ink-700 text-white text-[13px] font-semibold shrink-0">
        <?= th_icon('plus', 'w-4 h-4') ?><span class="hidden sm:inline"><?= lang('Common.btn.newRequest') ?></span></a>
      <?= th_theme_toggle('hidden sm:grid') ?>
      <button data-modal="userMenu" class="shrink-0"><?= th_avatar($me, 32) ?></button>
    </div>
  </header>

  <main id="view" class="flex-1">
    <?= $this->renderSection('content') ?>
  </main>

  <footer class="border-t border-line mt-10">
    <div class="max-w-[1120px] mx-auto px-5 py-6 flex flex-wrap items-center gap-x-6 gap-y-2 text-[12.5px] text-muted">
      <span><?= lang('Nav.footer.ext') ?></span><span><?= lang('Nav.footer.hours') ?></span>
      <span><?= lang('Nav.footer.urgent') ?></span>
      <span class="ml-auto flex items-center gap-3">
        <?= th_lang_switcher() ?>
        <span class="font-mono text-[10.5px] text-faint"><?= esc(lang('Common.version', [TICKETHUB_VERSION])) ?></span>
      </span>
    </div>
  </footer>
</div>

<div id="modalRoot"></div>
<div id="toastRoot" class="fixed bottom-5 left-1/2 -translate-x-1/2 z-[90] flex flex-col items-center gap-2"></div>

<template id="tpl-userMenu">
  <div data-modal-title="<?= esc(lang('Nav.menu.account'), 'attr') ?>" data-modal-sub="<?= esc(lang('Nav.menu.signedInAsName', ['name' => $me['name']]), 'attr') ?>" data-modal-width="max-w-md">
    <div class="flex items-center gap-2.5 p-3 rounded-xl border border-line">
      <?= th_avatar($me, 36) ?>
      <div class="min-w-0">
        <div class="text-[13.5px] font-semibold text-ink truncate"><?= esc($me['name']) ?></div>
        <div class="text-[12px] text-muted truncate"><?= esc($me['email']) ?></div>
      </div>
    </div>
    <?php if (in_array($me['role'], ['Administrator', 'Supervisor', 'Agent'], true)): ?>
    <a href="<?= site_url('app/dashboard') ?>" class="mt-3 flex items-center gap-2.5 p-3 rounded-xl border border-line hover:border-[#CBD1DC] text-[13px] font-medium text-ink"><?= th_icon('grid', 'w-4 h-4') ?> <?= lang('Nav.menu.backToWorkspace') ?></a>
    <?php endif ?>
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
  </div>
</template>

<template id="tpl-changePassword">
  <form method="post" action="<?= site_url('account/password') ?>" data-modal-title="<?= esc(lang('Nav.password.change'), 'attr') ?>" data-submit="<?= esc(lang('Nav.password.change'), 'attr') ?>">
    <?= csrf_field() ?>
    <div class="grid gap-3.5">
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5"><?= lang('Nav.password.current') ?></label>
        <input name="current" type="password" required class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5"><?= lang('Nav.password.new') ?></label>
        <input name="password" type="password" required minlength="12" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5"><?= lang('Nav.password.repeat') ?></label>
        <input name="confirm" type="password" required minlength="12" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
    </div>
  </form>
</template>

<?= $this->renderSection('modals') ?>

<?php $toast = session()->getFlashdata('toast'); ?>
<script>
window.TH = {
  csrfName: '<?= csrf_token() ?>',
  csrfValue: '<?= csrf_hash() ?>',
  locale: <?= json_encode(th_locale()) ?>,
  i18n: { theme: <?= json_encode(['system' => lang('Common.theme.system'), 'light' => lang('Common.theme.light'), 'dark' => lang('Common.theme.dark'), 'toggle' => lang('Common.theme.toggle', ['{0}'])]) ?> },
  toast: <?= $toast ? json_encode($toast) : 'null' ?>
};
</script>
<script src="<?= base_url('assets/js/theme.js') ?>"></script>
<script src="<?= base_url('assets/js/app.js') ?>"></script>
</body>
</html>
