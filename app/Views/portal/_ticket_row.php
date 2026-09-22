<?php
helper('i18n');
$s = th_sla($t);
$waiting = $t['status'] === 'Pending';
$agent = $t['agent_id'] ? ($users[(int) $t['agent_id']] ?? null) : null;
$statusCls = TH_STATUS[$t['status']] ?? TH_STATUS['Closed'];
?>
<a href="<?= site_url('portal/tickets/' . $t['code']) ?>" class="w-full flex items-center gap-3 px-4 py-3 border-b border-line last:border-0 row-hover text-left">
  <i class="led <?= TH_PRIORITY[$t['priority']]['dot'] ?>" title="<?= esc(th_t('Common.priority.' . $t['priority'], $t['priority']), 'attr') ?>"></i>
  <span class="min-w-0 flex-1">
    <span class="block text-[13.5px] font-medium text-ink truncate"><?= esc($t['subject']) ?></span>
    <span class="block text-[11.5px] text-faint mt-0.5 font-mono"><?= esc($t['code']) ?> · <?= esc(lang('Portal.row.opened', [th_rel($t['created_at'])])) ?><?= $agent ? ' · ' . esc(lang('Portal.row.with', [explode(' ', $agent['name'])[0]])) : '' ?></span>
  </span>
  <?php if ($waiting): ?><span class="hidden sm:inline-flex items-center h-[22px] px-2 rounded-md bg-signal-50 border border-signal-100 text-signal text-[11px] font-semibold"><?= lang('Portal.row.needsReply') ?></span><?php endif ?>
  <span class="inline-flex items-center h-[22px] px-2 rounded-md border text-[11px] font-semibold tracking-wide <?= $statusCls ?>"><?= esc(th_t('Common.status.' . $t['status'], $t['status'])) ?></span>
  <span class="text-faint"><?= th_icon('right', 'w-4 h-4') ?></span>
</a>
