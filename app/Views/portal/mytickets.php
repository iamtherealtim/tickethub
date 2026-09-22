<?= $this->extend('layouts/portal') ?>
<?= $this->section('content') ?>

<?php helper('i18n'); $list = $tab === 'open' ? $open : $done; ?>
<div class="max-w-[1120px] mx-auto px-5 py-8 fade-in">
  <h1 class="font-display text-[24px] font-semibold"><?= lang('Portal.mytickets.title') ?></h1>
  <p class="text-[13px] text-muted mt-1"><?= lang('Portal.mytickets.intro') ?></p>
  <div class="flex gap-1 mt-5 mb-3">
    <a href="<?= site_url('portal/tickets') ?>" class="h-8 px-3 rounded-lg text-[12.5px] font-medium inline-flex items-center <?= $tab === 'open' ? 'bg-ink text-white' : 'text-muted hover:bg-white' ?>"><?= esc(lang('Portal.mytickets.open', [count($open)])) ?></a>
    <a href="<?= site_url('portal/tickets?tab=closed') ?>" class="h-8 px-3 rounded-lg text-[12.5px] font-medium inline-flex items-center <?= $tab === 'closed' ? 'bg-ink text-white' : 'text-muted hover:bg-white' ?>"><?= esc(lang('Portal.mytickets.resolved', [count($done)])) ?></a>
  </div>
  <div class="bg-white border border-line rounded-xl overflow-hidden">
    <?php if ($list): ?>
      <?php foreach ($list as $t): ?>
        <?= view('portal/_ticket_row', ['t' => $t, 'users' => $users]) ?>
      <?php endforeach ?>
    <?php else: ?>
      <?= th_empty('inbox', lang('Portal.mytickets.emptyTitle'), lang('Portal.mytickets.emptyBody'),
          '<a href="' . site_url('portal/new') . '" class="inline-flex items-center gap-1.5 h-9 px-3.5 rounded-lg bg-brand text-white text-[13px] font-semibold">' . th_icon('plus', 'w-4 h-4') . esc(lang('Common.btn.newRequest')) . '</a>') ?>
    <?php endif ?>
  </div>
</div>
<?= $this->endSection() ?>
