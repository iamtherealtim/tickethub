<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
<?= view('partials/head', ['title' => ($title ?? 'Help') . ' · TicketHub Help']) ?>
</head>
<body class="h-full">
<div class="min-h-full flex flex-col bg-canvas">
  <?php
    $openCount = $portalOpenCount ?? 0;
    $links = [['portal', 'Home', ''], ['portal/catalog', 'Service catalog', 'catalog'], ['portal/kb', 'Knowledge', 'kb'], ['portal/tickets', 'My tickets', 'mytickets']];
    $cur = $pnav ?? '';
  ?>
  <header class="bg-white border-b border-line sticky top-0 z-30">
    <div class="max-w-[1120px] mx-auto px-5 h-16 flex items-center gap-6">
      <a href="<?= site_url('portal') ?>" class="flex items-center gap-2.5 shrink-0">
        <span class="w-7 h-7 rounded-md bg-ink grid place-items-center font-display font-bold text-[13px] text-white">TH</span>
        <span class="font-display font-semibold text-[14px] hidden sm:block">TicketHub <span class="text-muted font-normal">Help</span></span>
      </a>
      <nav class="flex items-center gap-1 flex-1 overflow-x-auto">
        <?php foreach ($links as [$path, $label, $key]): ?>
        <a href="<?= site_url($path) ?>" class="h-8 px-2.5 rounded-lg text-[13px] font-medium whitespace-nowrap transition inline-flex items-center
          <?= $cur === $key ? 'bg-canvas text-ink' : 'text-muted hover:text-ink' ?>"><?= $label ?><?php if ($key === 'mytickets' && $openCount): ?><span class="ml-1 font-mono text-[11px] text-brand"><?= $openCount ?></span><?php endif ?></a>
        <?php endforeach ?>
      </nav>
      <a href="<?= site_url('portal/new') ?>" class="inline-flex items-center gap-1.5 h-9 px-3 rounded-lg bg-ink hover:bg-ink-700 text-white text-[13px] font-semibold shrink-0">
        <?= th_icon('plus', 'w-4 h-4') ?><span class="hidden sm:inline">New request</span></a>
      <button data-modal="userMenu" class="shrink-0"><?= th_avatar($me, 32) ?></button>
    </div>
  </header>

  <main id="view" class="flex-1">
    <?= $this->renderSection('content') ?>
  </main>

  <footer class="border-t border-line mt-10">
    <div class="max-w-[1120px] mx-auto px-5 py-6 flex flex-wrap gap-x-6 gap-y-2 text-[12.5px] text-muted">
      <span>Service desk · ext. 4400</span><span>Open 08:00–18:00 ET, Mon–Fri</span>
      <span>Urgent outage outside hours? Call the on-call line.</span>
    </div>
  </footer>
</div>

<div id="modalRoot"></div>
<div id="toastRoot" class="fixed bottom-5 left-1/2 -translate-x-1/2 z-[90] flex flex-col items-center gap-2"></div>

<template id="tpl-userMenu">
  <div data-modal-title="Account" data-modal-sub="Signed in as <?= esc($me['name'], 'attr') ?>" data-modal-width="max-w-md">
    <div class="flex items-center gap-2.5 p-3 rounded-xl border border-line">
      <?= th_avatar($me, 36) ?>
      <div class="min-w-0">
        <div class="text-[13.5px] font-semibold text-ink truncate"><?= esc($me['name']) ?></div>
        <div class="text-[12px] text-muted truncate"><?= esc($me['email']) ?></div>
      </div>
    </div>
    <?php if (in_array($me['role'], ['Administrator', 'Supervisor', 'Agent'], true)): ?>
    <a href="<?= site_url('app/dashboard') ?>" class="mt-3 flex items-center gap-2.5 p-3 rounded-xl border border-line hover:border-[#CBD1DC] text-[13px] font-medium text-ink"><?= th_icon('grid', 'w-4 h-4') ?> Back to the agent workspace</a>
    <?php endif ?>
    <div class="flex items-center justify-between mt-4">
      <button data-modal="changePassword" class="inline-flex items-center gap-1.5 h-9 px-3.5 rounded-lg border border-line text-[13px] font-medium text-ink-500 hover:bg-canvas"><?= th_icon('lock', 'w-4 h-4') ?> Change password</button>
      <a href="<?= site_url('logout') ?>" class="inline-flex items-center gap-1.5 h-9 px-3.5 rounded-lg border border-line text-[13px] font-medium text-muted hover:bg-canvas"><?= th_icon('logout', 'w-4 h-4') ?> Sign out</a>
    </div>
  </div>
</template>

<template id="tpl-changePassword">
  <form method="post" action="<?= site_url('account/password') ?>" data-modal-title="Change password" data-submit="Change password">
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

<?= $this->renderSection('modals') ?>

<?php $toast = session()->getFlashdata('toast'); ?>
<script>
window.TH = {
  csrfName: '<?= csrf_token() ?>',
  csrfValue: '<?= csrf_hash() ?>',
  toast: <?= $toast ? json_encode($toast) : 'null' ?>
};
</script>
<script src="<?= base_url('assets/js/app.js') ?>"></script>
</body>
</html>
