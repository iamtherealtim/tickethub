<?php
$s = th_sla($t);
$waiting = $t['status'] === 'Pending';
$agent = $t['agent_id'] ? ($users[(int) $t['agent_id']] ?? null) : null;
?>
<a href="<?= site_url('portal/tickets/' . $t['code']) ?>" class="w-full flex items-center gap-3 px-4 py-3 border-b border-line last:border-0 row-hover text-left">
  <i class="led <?= TH_PRIORITY[$t['priority']]['dot'] ?>"></i>
  <span class="min-w-0 flex-1">
    <span class="block text-[13.5px] font-medium text-ink truncate"><?= esc($t['subject']) ?></span>
    <span class="block text-[11.5px] text-faint mt-0.5 font-mono"><?= esc($t['code']) ?> · opened <?= th_rel($t['created_at']) ?><?= $agent ? ' · with ' . esc(explode(' ', $agent['name'])[0]) : '' ?></span>
  </span>
  <?php if ($waiting): ?><span class="hidden sm:inline-flex items-center h-[22px] px-2 rounded-md bg-signal-50 border border-signal-100 text-signal text-[11px] font-semibold">Needs your reply</span><?php endif ?>
  <?= th_status_chip($t['status']) ?>
  <span class="text-faint"><?= th_icon('right', 'w-4 h-4') ?></span>
</a>
