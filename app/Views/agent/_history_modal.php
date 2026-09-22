<div data-modal-title="<?= esc($u['name'], 'attr') ?>'s tickets" data-modal-sub="<?= count($list) ?> in total" data-modal-width="max-w-lg">
  <div class="-mx-2 max-h-[420px] overflow-y-auto">
    <?php foreach ($list as $t): ?>
    <a href="<?= site_url('app/tickets/' . $t['code']) ?>" class="w-full flex items-center gap-2.5 p-2.5 rounded-lg hover:bg-canvas text-left">
      <i class="led <?= TH_PRIORITY[$t['priority']]['dot'] ?>"></i>
      <span class="font-mono text-[11px] text-faint w-[68px]"><?= esc($t['code']) ?></span>
      <span class="text-[13px] truncate flex-1"><?= esc($t['subject']) ?></span>
      <?= th_status_chip($t['status']) ?>
    </a>
    <?php endforeach ?>
  </div>
</div>
