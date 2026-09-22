<?= $this->extend('layouts/portal') ?>
<?= $this->section('content') ?>

<?php $list = $tab === 'open' ? $open : $done; ?>
<div class="max-w-[1120px] mx-auto px-5 py-8 fade-in">
  <h1 class="font-display text-[24px] font-semibold">My tickets</h1>
  <p class="text-[13px] text-muted mt-1">Everything you have raised, and where it stands.</p>
  <div class="flex gap-1 mt-5 mb-3">
    <a href="<?= site_url('portal/tickets') ?>" class="h-8 px-3 rounded-lg text-[12.5px] font-medium inline-flex items-center <?= $tab === 'open' ? 'bg-ink text-white' : 'text-muted hover:bg-white' ?>">Open (<?= count($open) ?>)</a>
    <a href="<?= site_url('portal/tickets?tab=closed') ?>" class="h-8 px-3 rounded-lg text-[12.5px] font-medium inline-flex items-center <?= $tab === 'closed' ? 'bg-ink text-white' : 'text-muted hover:bg-white' ?>">Resolved (<?= count($done) ?>)</a>
  </div>
  <div class="bg-white border border-line rounded-xl overflow-hidden">
    <?php if ($list): ?>
      <?php foreach ($list as $t): ?>
        <?= view('portal/_ticket_row', ['t' => $t, 'users' => $users]) ?>
      <?php endforeach ?>
    <?php else: ?>
      <?= th_empty('inbox', 'Nothing here yet', 'When you raise a request it will show up here with every update.',
          '<a href="' . site_url('portal/new') . '" class="inline-flex items-center gap-1.5 h-9 px-3.5 rounded-lg bg-brand text-white text-[13px] font-semibold">' . th_icon('plus', 'w-4 h-4') . 'New request</a>') ?>
    <?php endif ?>
  </div>
</div>
<?= $this->endSection() ?>
