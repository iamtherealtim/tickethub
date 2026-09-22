<?= $this->extend('layouts/agent') ?>
<?= $this->section('content') ?>

<?php
  $owner     = $users[(int) $c['owner_id']] ?? null;
  $approvals = json_decode($c['approvals'] ?? '[]', true) ?: [];
  $stateCls  = TH_CHANGE_STATE[$c['state']] ?? 'bg-canvas text-muted border-line';
  $base      = site_url('app/changes/' . $c['id']);
?>
<div class="p-5 max-w-[1100px] mx-auto fade-in">
  <a href="<?= site_url('app/changes') ?>" class="inline-flex items-center gap-1 text-[12px] text-muted hover:text-ink mb-3"><?= th_icon('back', 'w-3.5 h-3.5') ?> Changes</a>

  <div class="bg-white border border-line rounded-xl shadow-card p-5 mb-4">
    <div class="flex flex-wrap items-center gap-2">
      <span class="font-mono text-[11.5px] text-faint"><?= esc($c['code']) ?></span>
      <span class="inline-flex items-center h-[20px] px-2 rounded border text-[11px] font-semibold <?= $stateCls ?>"><?= esc($c['state']) ?></span>
      <span class="inline-flex items-center h-[20px] px-2 rounded border text-[11px] font-semibold <?= TH_RISK[$c['risk']] ?? 'bg-canvas text-muted border-line' ?>"><?= esc($c['risk']) ?> risk</span>
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
        <form method="post" action="<?= $base ?>/approve"><?= csrf_field() ?>
          <button type="submit" class="inline-flex items-center gap-1.5 h-9 px-3.5 rounded-lg bg-brand hover:bg-brand-600 text-white text-[13px] font-semibold"><?= th_icon('check', 'w-3.5 h-3.5') ?>Approve</button></form>
        <form method="post" action="<?= $base ?>/reject" data-confirm="Reject <?= esc($c['code'], 'attr') ?>?" data-confirm-label="Reject" data-confirm-title="Reject this change?"><?= csrf_field() ?>
          <button type="submit" class="inline-flex items-center gap-1.5 h-9 px-3.5 rounded-lg border border-alert-100 bg-white text-alert text-[13px] font-medium hover:bg-alert-50"><?= th_icon('x', 'w-3.5 h-3.5') ?>Reject</button></form>
      <?php elseif ($c['state'] === 'Scheduled'): ?>
        <form method="post" action="<?= $base ?>/state"><?= csrf_field() ?>
          <input type="hidden" name="to" value="In progress">
          <button type="submit" class="inline-flex items-center gap-1.5 h-9 px-3.5 rounded-lg bg-ink hover:bg-ink-700 text-white text-[13px] font-semibold"><?= th_icon('play', 'w-3.5 h-3.5') ?>Start work</button></form>
      <?php elseif ($c['state'] === 'In progress'): ?>
        <form method="post" action="<?= $base ?>/state"><?= csrf_field() ?>
          <input type="hidden" name="to" value="Completed">
          <button type="submit" class="inline-flex items-center gap-1.5 h-9 px-3.5 rounded-lg bg-brand hover:bg-brand-600 text-white text-[13px] font-semibold"><?= th_icon('check', 'w-3.5 h-3.5') ?>Mark completed</button></form>
      <?php else: ?>
        <span class="text-[12.5px] text-muted">No further action — this change is <?= strtolower(esc($c['state'])) ?>.</span>
      <?php endif ?>
    </div>
    <?php endif ?>
  </div>

  <div class="grid lg:grid-cols-2 gap-4 mb-4">
    <?= th_card(th_card_head('Implementation plan') . '<div class="p-4 text-[13px] text-ink-500 leading-relaxed whitespace-pre-line">' . esc($c['plan'] ?: '—') . '</div>') ?>
    <?= th_card(th_card_head('Backout plan') . '<div class="p-4 text-[13px] text-ink-500 leading-relaxed whitespace-pre-line">' . esc($c['backout'] ?: '—') . '</div>') ?>
  </div>

  <div class="grid lg:grid-cols-2 gap-4">
    <?= th_card(th_card_head('Impact') . '<div class="p-4 text-[13px] text-ink-500 leading-relaxed">' . esc($c['impact'] ?: '—') . '</div>') ?>
    <?php
      $rows = '';
      foreach ($approvals as $ap) {
          $by   = $users[(int) $ap['by']] ?? null;
          $tone = $ap['status'] === 'Approved' ? 'text-brand' : ($ap['status'] === 'Rejected' ? 'text-alert' : 'text-signal');
          $rows .= '<div class="flex items-center gap-2.5 px-4 py-2.5 border-b border-line last:border-0">'
              . th_avatar($by, 24)
              . '<span class="text-[13px] text-ink-500 truncate flex-1">' . esc($by['name'] ?? '—') . '</span>'
              . '<span class="text-[12.5px] font-medium ' . $tone . '">' . esc($ap['status']) . '</span></div>';
      }
      if (! $rows) {
          $rows = '<p class="px-4 py-6 text-[13px] text-muted text-center">No approvers recorded.</p>';
      }
      echo th_card(th_card_head('Approvals · ' . count($approvals)) . '<div>' . $rows . '</div>');
    ?>
  </div>
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
