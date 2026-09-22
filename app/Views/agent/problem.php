<?= $this->extend('layouts/agent') ?>
<?= $this->section('content') ?>

<?php $owner = $users[(int) $p['owner_id']] ?? null; ?>
<div class="p-5 max-w-[1100px] mx-auto fade-in">
  <a href="<?= site_url('app/problems') ?>" class="inline-flex items-center gap-1 text-[12px] text-muted hover:text-ink mb-3"><?= th_icon('back', 'w-3.5 h-3.5') ?> Problems</a>

  <div class="bg-white border border-line rounded-xl shadow-card p-5 mb-4">
    <div class="flex flex-wrap items-center gap-2">
      <span class="font-mono text-[11.5px] text-faint"><?= esc($p['code']) ?></span>
      <span class="inline-flex items-center h-[20px] px-2 rounded border text-[11px] font-semibold <?= TH_PROBLEM_STATUS[$p['status']] ?? 'bg-canvas text-muted border-line' ?>"><?= esc($p['status']) ?></span>
      <?= th_priority_tag($p['priority']) ?>
      <span class="ml-auto flex items-center gap-2">
        <?= th_btn('Edit', 'data-modal="editProblem"', 'ghost', 'edit') ?>
        <form method="post" action="<?= site_url('app/problems/' . $p['id'] . '/delete') ?>" data-confirm="Delete <?= esc($p['code'], 'attr') ?>? Linked incidents keep their history but lose the link." data-confirm-label="Delete">
          <?= csrf_field() ?><?= th_btn('Delete', 'type="submit"', 'danger', 'trash') ?>
        </form>
      </span>
    </div>
    <h1 class="font-display text-[22px] font-semibold mt-2 leading-snug"><?= esc($p['title']) ?></h1>
    <div class="flex items-center gap-2 mt-2 text-[12.5px] text-muted">
      <?= th_avatar($owner, 22) ?><?= esc($owner['name'] ?? '—') ?>
      <span class="text-line">·</span>Opened <?= th_rel($p['opened_at']) ?>
    </div>
    <div class="grid sm:grid-cols-2 gap-3 mt-4">
      <div class="rounded-lg bg-canvas border border-line p-3">
        <div class="text-[11px] uppercase tracking-[.09em] text-faint mb-1">Root cause</div>
        <p class="text-[13px] text-ink-500 leading-relaxed"><?= esc($p['cause']) ?></p></div>
      <div class="rounded-lg bg-canvas border border-line p-3">
        <div class="text-[11px] uppercase tracking-[.09em] text-faint mb-1">Workaround</div>
        <p class="text-[13px] text-ink-500 leading-relaxed"><?= esc($p['workaround']) ?></p></div>
    </div>
  </div>

  <?php
    $rows = '';
    foreach ($linked as $t) {
        $rows .= '<div class="flex items-center gap-3 px-4 py-2.5 border-b border-line last:border-0 row-hover">'
            . '<i class="led ' . TH_PRIORITY[$t['priority']]['dot'] . '"></i>'
            . '<a href="' . site_url('app/tickets/' . $t['code']) . '" class="flex items-center gap-3 min-w-0 flex-1">'
            . '<span class="font-mono text-[11px] text-faint w-[70px]">' . esc($t['code']) . '</span>'
            . '<span class="text-[13px] text-ink truncate flex-1">' . esc($t['subject']) . '</span></a>'
            . th_status_chip($t['status'])
            . '<form method="post" action="' . site_url('app/problems/' . $p['id'] . '/unlink/' . $t['id']) . '">' . csrf_field()
            . '<button type="submit" class="w-7 h-7 grid place-items-center rounded-md text-faint hover:text-alert hover:bg-alert-50" title="Unlink">' . th_icon('x', 'w-3.5 h-3.5') . '</button></form>'
            . '</div>';
    }
    if (! $rows) {
        $rows = '<p class="px-4 py-6 text-[13px] text-muted text-center">No incidents linked yet. Link the tickets that share this root cause.</p>';
    }
    $linkBtn = th_btn('Link incidents', 'data-fetch-modal="' . site_url('app/problems/' . $p['id'] . '/link-search') . '"', 'brand', 'link');
    echo th_card(th_card_head('Linked incidents · ' . count($linked), $linkBtn) . '<div>' . $rows . '</div>');
  ?>
</div>
<?= $this->endSection() ?>

<?= $this->section('modals') ?>
<template id="tpl-editProblem">
  <form method="post" action="<?= site_url('app/problems/' . $p['id']) ?>" data-modal-title="Edit <?= esc($p['code'], 'attr') ?>" data-modal-width="max-w-xl" data-submit="Save">
    <?= csrf_field() ?>
    <div class="grid sm:grid-cols-2 gap-3.5">
      <div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Title<span class="text-alert"> *</span></label>
        <input name="title" required value="<?= esc($p['title'], 'attr') ?>" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Status</label>
        <select name="status" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px]">
          <?php foreach (['Under investigation', 'Root cause identified', 'Resolved'] as $s): ?><option <?= $p['status'] === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach ?>
        </select></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Priority</label>
        <select name="priority" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px]">
          <?php foreach (['Urgent', 'High', 'Medium', 'Low'] as $s): ?><option <?= $p['priority'] === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach ?>
        </select></div>
      <div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Owner</label>
        <select name="owner_id" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px]">
          <?php foreach ($agents as $a): if ((int) $a['active']): ?><option value="<?= $a['id'] ?>" <?= (int) $p['owner_id'] === (int) $a['id'] ? 'selected' : '' ?>><?= esc($a['name']) ?></option><?php endif; endforeach ?>
        </select></div>
      <div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Root cause</label>
        <textarea name="cause" rows="3" class="w-full px-2.5 py-2 rounded-lg border border-line text-[13px] leading-relaxed focus:border-brand"><?= esc($p['cause']) ?></textarea></div>
      <div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Workaround</label>
        <textarea name="workaround" rows="3" class="w-full px-2.5 py-2 rounded-lg border border-line text-[13px] leading-relaxed focus:border-brand"><?= esc($p['workaround']) ?></textarea></div>
    </div>
  </form>
</template>
<?= $this->endSection() ?>
