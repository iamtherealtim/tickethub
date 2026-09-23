<?= $this->extend('layouts/agent') ?>
<?= $this->section('content') ?>

<?php
  $owner     = $users[(int) $c['owner_id']] ?? null;
  $approvals = json_decode($c['approvals'] ?? '[]', true) ?: [];
  $stateCls  = TH_CHANGE_STATE[$c['state']] ?? 'bg-canvas text-muted border-line';
  $base      = site_url('app/changes/' . $c['id']);
  $canGo     = static fn (string $to): bool => in_array($to, $transitions, true);
  $stateBtn  = static function (string $to, string $label, string $cls, string $ic = '', string $confirm = '') use ($base): string {
      return '<form method="post" action="' . $base . '/state"' . ($confirm ? ' data-confirm="' . esc($confirm, 'attr') . '" data-confirm-label="' . esc($label, 'attr') . '"' : '') . '>' . csrf_field()
          . '<input type="hidden" name="to" value="' . esc($to, 'attr') . '">'
          . '<button type="submit" class="inline-flex items-center gap-1.5 h-9 px-3.5 rounded-lg text-[13px] font-semibold ' . $cls . '">' . ($ic ? th_icon($ic, 'w-3.5 h-3.5') : '') . $label . '</button></form>';
  };
?>
<div class="p-5 max-w-[1100px] mx-auto fade-in">
  <a href="<?= site_url('app/changes') ?>" class="inline-flex items-center gap-1 text-[12px] text-muted hover:text-ink mb-3"><?= th_icon('back', 'w-3.5 h-3.5') ?> Changes</a>

  <div class="bg-white border border-line rounded-xl shadow-card p-5 mb-4">
    <div class="flex flex-wrap items-center gap-2">
      <span class="font-mono text-[11.5px] text-faint"><?= esc($c['code']) ?></span>
      <span class="inline-flex items-center h-[20px] px-2 rounded-sm border text-[11px] font-semibold <?= $stateCls ?>"><?= esc($c['state']) ?></span>
      <span class="inline-flex items-center h-[20px] px-2 rounded-sm border text-[11px] font-semibold <?= TH_RISK[$c['risk']] ?? 'bg-canvas text-muted border-line' ?>"><?= esc($c['risk']) ?> risk</span>
      <span class="text-[12px] text-muted"><?= esc($c['type']) ?></span>
      <?php if ($canManage): ?>
      <span class="ml-auto flex items-center gap-2">
        <?= th_btn('Edit', 'data-modal="editChange"', 'ghost', 'edit') ?>
        <form method="post" action="<?= $base ?>/delete" data-confirm="Delete <?= esc($c['code'], 'attr') ?>? This cannot be undone." data-confirm-label="Delete" data-confirm-title="Delete this change?">
          <?= csrf_field() ?><?= th_btn('Delete', 'type="submit"', 'danger', 'trash') ?>
        </form>
      </span>
      <?php endif ?>
    </div>
    <h1 class="font-display text-[22px] font-semibold mt-2 leading-snug"><?= esc($c['title']) ?></h1>
    <div class="flex flex-wrap items-center gap-2 mt-2 text-[12.5px] text-muted">
      <?= th_avatar($owner, 22) ?><?= esc($owner['name'] ?? '—') ?>
      <span class="text-line">·</span>Window <?= th_date($c['window_at']) ?>
      <span class="text-line">·</span><?= esc($c['duration']) ?>
    </div>

    <?php if ($canManage): ?>
    <div class="flex flex-wrap gap-2 mt-4 pt-4 border-t border-line">
      <?php if ($c['state'] === 'Awaiting approval'): ?>
        <?php if ((int) $c['owner_id'] === (int) $me['id']): ?>
          <span class="inline-flex items-center text-[12.5px] text-muted">Your own change needs another supervisor's sign-off.</span>
        <?php else: ?>
        <form method="post" action="<?= $base ?>/approve"><?= csrf_field() ?>
          <button type="submit" class="inline-flex items-center gap-1.5 h-9 px-3.5 rounded-lg bg-brand hover:bg-brand-600 text-white text-[13px] font-semibold"><?= th_icon('check', 'w-3.5 h-3.5') ?>Approve</button></form>
        <form method="post" action="<?= $base ?>/reject" data-confirm="Reject <?= esc($c['code'], 'attr') ?>?" data-confirm-label="Reject" data-confirm-title="Reject this change?"><?= csrf_field() ?>
          <button type="submit" class="inline-flex items-center gap-1.5 h-9 px-3.5 rounded-lg border border-alert-100 bg-white text-alert text-[13px] font-medium hover:bg-alert-50"><?= th_icon('x', 'w-3.5 h-3.5') ?>Reject</button></form>
        <?php endif ?>
      <?php endif ?>
      <?php if ($canGo('In progress')): ?>
        <?= $stateBtn('In progress', 'Start work', 'bg-ink hover:bg-ink-700 text-white', 'play') ?>
      <?php endif ?>
      <?php if ($canGo('Completed')): ?>
        <?= $stateBtn('Completed', 'Mark completed', 'bg-brand hover:bg-brand-600 text-white', 'check') ?>
      <?php endif ?>
      <?php if ($canGo('Awaiting approval')): ?>
        <?= $stateBtn('Awaiting approval', 'Resubmit for approval', 'bg-ink hover:bg-ink-700 text-white', 'refresh') ?>
      <?php endif ?>
      <?php if ($canGo('Cancelled')): ?>
        <?= $stateBtn('Cancelled', 'Cancel change', 'border border-line bg-white text-ink-500 hover:bg-canvas font-medium', 'x', 'Cancel ' . $c['code'] . '? It will not go ahead.') ?>
      <?php endif ?>
      <?php if (! $transitions && $c['state'] !== 'Awaiting approval'): ?>
        <span class="text-[12.5px] text-muted">No further action — this change is <?= strtolower(esc($c['state'])) ?>.</span>
      <?php endif ?>
    </div>
    <?php endif ?>
  </div>

  <div class="grid lg:grid-cols-2 gap-4 mb-4">
    <?= th_card(th_card_head('Implementation plan') . '<div class="p-4 text-[13px] text-ink-500 leading-relaxed whitespace-pre-line">' . esc($c['plan'] ?: '—') . '</div>') ?>
    <?= th_card(th_card_head('Backout plan') . '<div class="p-4 text-[13px] text-ink-500 leading-relaxed whitespace-pre-line">' . esc($c['backout'] ?: '—') . '</div>') ?>
  </div>

  <div class="grid lg:grid-cols-2 gap-4 mb-4">
    <?= th_card(th_card_head('Impact') . '<div class="p-4 text-[13px] text-ink-500 leading-relaxed">' . esc($c['impact'] ?: '—') . '</div>') ?>
    <?php
      $rows = '';
      foreach ($approvals as $ap) {
          $by     = $users[(int) ($ap['by'] ?? 0)] ?? null;
          $status = (string) ($ap['status'] ?? 'Pending');
          $tone   = $status === 'Approved' ? 'text-brand' : ($status === 'Rejected' ? 'text-alert' : 'text-signal');
          $when   = ! empty($ap['decided_at']) ? th_date($ap['decided_at']) : (! empty($ap['requested_at']) ? 'requested ' . th_rel($ap['requested_at']) : '');
          $rows .= '<div class="flex items-center gap-2.5 px-4 py-2.5 border-b border-line last:border-0">'
              . th_avatar($by, 24)
              . '<span class="min-w-0 flex-1"><span class="block text-[13px] text-ink-500 truncate">' . esc($by['name'] ?? '—') . '</span>'
              . ($when ? '<span class="block font-mono text-[11px] text-faint">' . esc($when) . '</span>' : '') . '</span>'
              . '<span class="text-[12.5px] font-medium ' . $tone . '">' . esc($status) . '</span></div>';
      }
      if (! $rows) {
          $rows = '<p class="px-4 py-6 text-[13px] text-muted text-center">No approvers recorded.</p>';
      }
      echo th_card(th_card_head('Approvals · ' . count($approvals)) . '<div>' . $rows . '</div>');
    ?>
  </div>

  <?php
    $rows = '';
    foreach ($linked as $t) {
        $rows .= '<div class="flex items-center gap-3 px-4 py-2.5 border-b border-line last:border-0 row-hover">'
            . '<i class="led ' . (TH_PRIORITY[$t['priority']]['dot'] ?? 'bg-[#98A1B0]') . '"></i>'
            . '<a href="' . site_url('app/tickets/' . $t['code']) . '" class="flex items-center gap-3 min-w-0 flex-1">'
            . '<span class="font-mono text-[11px] text-faint w-[70px]">' . esc($t['code']) . '</span>'
            . '<span class="text-[13px] text-ink truncate flex-1">' . esc($t['subject']) . '</span></a>'
            . th_status_chip($t['status'])
            . '<form method="post" action="' . $base . '/unlink/' . $t['id'] . '">' . csrf_field()
            . '<button type="submit" class="w-7 h-7 grid place-items-center rounded-md text-faint hover:text-alert hover:bg-alert-50" title="Unlink">' . th_icon('x', 'w-3.5 h-3.5') . '</button></form>'
            . '</div>';
    }
    if (! $rows) {
        $rows = '<p class="px-4 py-6 text-[13px] text-muted text-center">No tickets linked yet. Link the incidents this change causes or resolves.</p>';
    }
    $linkForm = '<form method="post" action="' . $base . '/link" class="flex items-center gap-1.5">' . csrf_field()
        . '<input name="code" required placeholder="INC-2101" class="h-8 w-[120px] px-2.5 rounded-lg border border-line text-[12.5px] font-mono placeholder:text-faint focus:border-brand">'
        . th_btn('Link', 'type="submit"', 'brand', 'link') . '</form>';
    echo th_card(th_card_head('Linked tickets · ' . count($linked), $linkForm) . '<div>' . $rows . '</div>');
  ?>
</div>
<?= $this->endSection() ?>

<?= $this->section('modals') ?>
<template id="tpl-editChange">
  <form method="post" action="<?= $base ?>" data-modal-title="Edit <?= esc($c['code'], 'attr') ?>" data-modal-width="max-w-xl" data-submit="Save">
    <?= csrf_field() ?>
    <div class="grid sm:grid-cols-2 gap-3.5">
      <div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Title<span class="text-alert"> *</span></label>
        <input name="title" required value="<?= esc($c['title'], 'attr') ?>" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Risk</label>
        <select name="risk" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px]">
          <?php foreach (array_keys(TH_RISK) as $r): ?><option <?= $c['risk'] === $r ? 'selected' : '' ?>><?= $r ?></option><?php endforeach ?>
        </select></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Type</label>
        <select name="type" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px]">
          <?php foreach (['Standard', 'Normal', 'Emergency'] as $ct): ?><option <?= $c['type'] === $ct ? 'selected' : '' ?>><?= $ct ?></option><?php endforeach ?>
        </select></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Window</label>
        <input name="window_at" type="datetime-local" value="<?= date('Y-m-d\TH:i', strtotime($c['window_at'])) ?>" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] font-mono focus:border-brand"></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Duration</label>
        <input name="duration" value="<?= esc($c['duration'], 'attr') ?>" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
      <div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Impact</label>
        <input name="impact" value="<?= esc($c['impact'], 'attr') ?>" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
      <div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Implementation plan</label>
        <textarea name="plan" rows="4" class="w-full px-2.5 py-2 rounded-lg border border-line text-[13px] leading-relaxed focus:border-brand"><?= esc($c['plan']) ?></textarea></div>
      <div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Backout plan</label>
        <textarea name="backout" rows="3" class="w-full px-2.5 py-2 rounded-lg border border-line text-[13px] leading-relaxed focus:border-brand"><?= esc($c['backout']) ?></textarea></div>
    </div>
  </form>
</template>
<?= $this->endSection() ?>
