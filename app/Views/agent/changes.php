<?= $this->extend('layouts/agent') ?>
<?= $this->section('content') ?>

<?php
$now = time();
$awaiting = count(array_filter($changes, static fn ($c) => $c['state'] === 'Awaiting approval'));
// "Scheduled" only counts windows that actually fall in the next two weeks.
$scheduled = count(array_filter($changes, static function ($c) use ($now) {
    $w = strtotime($c['window_at']);

    return $c['state'] === 'Scheduled' && $w >= $now && $w < $now + 14 * 86400;
}));
$inProgress = count(array_filter($changes, static fn ($c) => $c['state'] === 'In progress'));
// Success rate over changes whose window fell in the last 90 days and that reached an outcome.
$recent    = array_filter($changes, static fn ($c) => strtotime($c['window_at']) >= $now - 90 * 86400 && strtotime($c['window_at']) <= $now);
$completed = count(array_filter($recent, static fn ($c) => $c['state'] === 'Completed'));
$closedN   = count(array_filter($recent, static fn ($c) => in_array($c['state'], ['Completed', 'Rejected', 'Cancelled'], true)));
$successPct = $closedN ? (int) round($completed / $closedN * 100) . '%' : '—';
$canManage = $canManage ?? false;
?>
<div class="p-5 max-w-[1400px] mx-auto fade-in">
  <div class="flex flex-wrap items-end justify-between gap-3 mb-5">
    <div>
      <h1 class="font-display text-[22px] font-semibold text-ink">Changes</h1>
      <p class="text-[13px] text-muted mt-1">Planned work, who signed it off, and how we back out</p>
    </div>
    <?= th_btn('Raise change', 'data-modal="newChange"', 'brand', 'plus') ?>
  </div>

  <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
    <?= th_kpi('Awaiting approval', $awaiting, 'Blocking scheduled work', 'signal') ?>
    <?= th_kpi('Scheduled', $scheduled, 'Window in the next 14 days') ?>
    <?= th_kpi('In progress', $inProgress, 'Running now', 'brand') ?>
    <?= th_kpi('Success rate', $successPct, $closedN ? $completed . ' completed of ' . $closedN . ' closed · last 90 days' : 'No closed changes in the last 90 days', 'brand') ?>
  </div>

  <?php if (! $changes): ?>
    <?= th_card(th_empty('branch', 'No changes yet', 'Raise the first change to plan work, capture the backout and get it signed off.', th_btn('Raise change', 'data-modal="newChange"', 'brand', 'plus'))) ?>
  <?php endif ?>
  <div class="space-y-3">
    <?php foreach ($changes as $c):
      $windowTs = strtotime($c['window_at']);
      $future = $windowTs > time();
      $approvals = json_decode($c['approvals'] ?? '[]', true) ?: [];
    ?>
    <div class="bg-white border border-line rounded-xl shadow-card p-4">
      <div class="flex flex-wrap items-start gap-3">
        <div class="min-w-0 flex-1">
          <div class="flex flex-wrap items-center gap-2">
            <span class="font-mono text-[11.5px] text-faint"><?= esc($c['code']) ?></span>
            <span class="inline-flex items-center h-[20px] px-2 rounded-sm border text-[11px] font-semibold <?= TH_CHANGE_STATE[$c['state']] ?? 'bg-canvas text-muted border-line' ?>"><?= esc($c['state']) ?></span>
            <span class="inline-flex items-center h-[20px] px-2 rounded-sm border text-[11px] font-semibold <?= TH_RISK[$c['risk']] ?? 'bg-canvas text-muted border-line' ?>"><?= esc($c['risk']) ?> risk</span>
            <span class="text-[11.5px] text-faint"><?= esc($c['type']) ?></span>
          </div>
          <h3 class="font-display text-[15px] font-semibold text-ink mt-1.5"><?= esc($c['title']) ?></h3>
          <p class="text-[12.5px] text-muted mt-1 leading-relaxed"><?= esc($c['plan']) ?></p>
          <p class="text-[12.5px] text-muted mt-1"><span class="text-faint">Back out:</span> <?= esc($c['backout']) ?></p>
        </div>
        <div class="w-full sm:w-[210px] shrink-0 sm:border-l sm:border-line sm:pl-4 space-y-2">
          <div><div class="text-[11px] uppercase tracking-[.09em] text-faint">Window</div>
            <div class="font-mono text-[12.5px] text-ink"><?= th_date($c['window_at']) ?> · <?= esc($c['duration']) ?></div>
            <div class="text-[11.5px] <?= $future ? 'text-muted' : 'text-faint' ?>"><?= $future ? 'in ' . th_dur($windowTs - time()) : th_rel($c['window_at']) ?></div></div>
          <div><div class="text-[11px] uppercase tracking-[.09em] text-faint">Impact</div>
            <div class="text-[12.5px] text-ink-500"><?= esc($c['impact']) ?></div></div>
          <div><div class="text-[11px] uppercase tracking-[.09em] text-faint mb-1">Approvals</div>
            <div class="space-y-1">
              <?php foreach ($approvals as $ap): $by = $users[(int) ($ap['by'] ?? 0)] ?? null; $st = (string) ($ap['status'] ?? 'Pending'); ?>
              <div class="flex items-center gap-1.5 text-[12px]" <?= ! empty($ap['decided_at']) ? 'title="' . esc(th_date($ap['decided_at']), 'attr') . '"' : '' ?>>
                <?= th_avatar($by, 20) ?><span class="text-ink-500 truncate flex-1"><?= esc($by['name'] ?? '—') ?></span>
                <span class="<?= $st === 'Approved' ? 'text-brand' : ($st === 'Rejected' ? 'text-alert' : 'text-signal') ?> font-medium"><?= esc($st) ?></span>
              </div>
              <?php endforeach ?>
            </div></div>
          <?php if ($canManage && $c['state'] === 'Awaiting approval' && (int) $c['owner_id'] !== (int) $me['id']): ?>
          <div class="flex gap-2 pt-1">
            <form method="post" action="<?= site_url('app/changes/' . $c['id'] . '/approve') ?>" class="flex-1"><?= csrf_field() ?>
              <button type="submit" class="w-full h-8 rounded-lg bg-brand text-white text-[12.5px] font-semibold hover:bg-brand-600">Approve</button></form>
            <form method="post" action="<?= site_url('app/changes/' . $c['id'] . '/reject') ?>"><?= csrf_field() ?>
              <button type="submit" class="h-8 px-2.5 rounded-lg border border-alert-100 text-alert text-[12.5px] font-medium hover:bg-alert-50">Reject</button></form>
          </div>
          <?php elseif ($canManage && $c['state'] === 'Scheduled'): ?>
          <form method="post" action="<?= site_url('app/changes/' . $c['id'] . '/state') ?>" class="pt-1"><?= csrf_field() ?>
            <input type="hidden" name="to" value="In progress">
            <button type="submit" class="w-full h-8 rounded-lg bg-ink text-white text-[12.5px] font-semibold hover:bg-ink-700">Start work</button></form>
          <?php elseif ($canManage && $c['state'] === 'In progress'): ?>
          <form method="post" action="<?= site_url('app/changes/' . $c['id'] . '/state') ?>" class="pt-1"><?= csrf_field() ?>
            <input type="hidden" name="to" value="Completed">
            <button type="submit" class="w-full h-8 rounded-lg bg-brand text-white text-[12.5px] font-semibold hover:bg-brand-600">Mark completed</button></form>
          <?php endif ?>
          <div class="flex flex-col gap-1.5 pt-1">
            <a href="<?= site_url('app/changes/' . $c['id']) ?>" class="inline-flex items-center justify-center gap-1.5 h-8 px-2.5 rounded-lg border border-line bg-white text-[12.5px] font-medium text-ink-500 hover:bg-canvas transition"><?= th_icon('ext', 'w-3.5 h-3.5') ?>Open</a>
            <?php if ($canManage): ?>
            <div class="flex gap-1.5">
              <?= th_btn('Edit', 'data-modal="editChange-' . $c['id'] . '"', 'ghost', 'edit') ?>
              <form method="post" action="<?= site_url('app/changes/' . $c['id'] . '/delete') ?>" data-confirm="Delete <?= esc($c['code'], 'attr') ?>?" data-confirm-label="Delete" class="flex-1">
                <?= csrf_field() ?><?= th_btn('Delete', 'type="submit"', 'danger', 'trash') ?></form>
            </div>
            <?php endif ?>
          </div>
        </div>
      </div>
    </div>
    <?php endforeach ?>
  </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('modals') ?>
<?php if ($canManage): foreach ($changes as $c): ?>
<template id="tpl-editChange-<?= $c['id'] ?>">
  <form method="post" action="<?= site_url('app/changes/' . $c['id']) ?>" data-modal-title="Edit <?= esc($c['code'], 'attr') ?>" data-modal-width="max-w-xl" data-submit="Save">
    <?= csrf_field() ?>
    <div class="grid sm:grid-cols-2 gap-3.5">
      <div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Title<span class="text-alert"> *</span></label>
        <input name="title" required value="<?= esc($c['title'], 'attr') ?>" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Risk</label>
        <select name="risk" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px]">
          <?php foreach (['Low', 'Medium', 'High'] as $r): ?><option <?= $c['risk'] === $r ? 'selected' : '' ?>><?= $r ?></option><?php endforeach ?>
        </select></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Type</label>
        <select name="type" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px]">
          <?php foreach (['Standard', 'Normal', 'Emergency'] as $ct): ?><option <?= $c['type'] === $ct ? 'selected' : '' ?>><?= $ct ?></option><?php endforeach ?>
        </select></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Window</label>
        <input name="window_at" type="datetime-local" value="<?= date('Y-m-d\TH:i', strtotime($c['window_at'])) ?>" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] font-mono focus:border-brand"></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Duration</label>
        <input name="duration" value="<?= esc($c['duration'], 'attr') ?>" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
      <div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Who is affected?</label>
        <input name="impact" value="<?= esc($c['impact'], 'attr') ?>" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
      <div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Implementation plan</label>
        <textarea name="plan" rows="3" class="w-full px-2.5 py-2 rounded-lg border border-line text-[13px] leading-relaxed focus:border-brand"><?= esc($c['plan']) ?></textarea></div>
      <div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Back-out plan</label>
        <textarea name="backout" rows="2" class="w-full px-2.5 py-2 rounded-lg border border-line text-[13px] leading-relaxed focus:border-brand"><?= esc($c['backout']) ?></textarea></div>
    </div>
  </form>
</template>
<?php endforeach; endif ?>

<template id="tpl-newChange">
  <form method="post" action="<?= site_url('app/changes') ?>" data-modal-title="Raise a change" data-modal-width="max-w-xl" data-submit="Submit for approval">
    <?= csrf_field() ?>
    <div class="grid sm:grid-cols-2 gap-3.5">
      <div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">What is changing?<span class="text-alert"> *</span></label>
        <input name="title" required class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Risk</label>
        <select name="risk" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px]"><option>Low</option><option>Medium</option><option>High</option></select></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Type</label>
        <select name="type" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px]"><option>Standard</option><option>Normal</option><option>Emergency</option></select></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Who is affected?</label>
        <input name="impact" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Expected duration</label>
        <input name="duration" placeholder="e.g. 4h" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
      <div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Implementation plan<span class="text-alert"> *</span></label>
        <textarea name="plan" rows="4" required class="w-full px-2.5 py-2 rounded-lg border border-line text-[13px] leading-relaxed focus:border-brand"></textarea></div>
      <div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Back-out plan<span class="text-alert"> *</span></label>
        <textarea name="backout" rows="3" required class="w-full px-2.5 py-2 rounded-lg border border-line text-[13px] leading-relaxed focus:border-brand"></textarea></div>
    </div>
  </form>
</template>
<?= $this->endSection() ?>
