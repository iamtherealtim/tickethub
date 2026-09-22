<?= $this->extend('layouts/agent') ?>
<?= $this->section('content') ?>

<div class="p-5 max-w-[1400px] mx-auto fade-in">
  <div class="flex flex-wrap items-end justify-between gap-3 mb-5">
    <div>
      <h1 class="font-display text-[22px] font-semibold text-ink">Problems</h1>
      <p class="text-[13px] text-muted mt-1">Recurring causes behind clusters of incidents</p>
    </div>
    <?= th_btn('Raise problem', 'data-modal="newProblem"', 'brand', 'plus') ?>
  </div>

  <?php if (! $problems): ?>
    <?= th_card(th_empty('warn', 'No problems raised', 'When the same incident keeps coming back, raise a problem to track the root cause.', th_btn('Raise problem', 'data-modal="newProblem"', 'brand', 'plus'))) ?>
  <?php endif ?>
  <div class="space-y-3">
    <?php foreach ($problems as $p): $owner = $users[(int) $p['owner_id']] ?? null; ?>
    <div class="bg-white border border-line rounded-xl shadow-card p-4">
      <div class="flex flex-wrap items-center gap-2">
        <span class="font-mono text-[11.5px] text-faint"><?= esc($p['code']) ?></span>
        <span class="inline-flex items-center h-[20px] px-2 rounded border text-[11px] font-semibold <?= TH_PROBLEM_STATUS[$p['status']] ?? 'bg-canvas text-muted border-line' ?>"><?= esc($p['status']) ?></span>
        <?= th_priority_tag($p['priority']) ?>
        <span class="ml-auto flex items-center gap-1.5 text-[12px] text-muted"><?= th_avatar($owner, 22) ?><?= esc($owner['name'] ?? '—') ?></span>
      </div>
      <h3 class="font-display text-[15px] font-semibold mt-2"><?= esc($p['title']) ?></h3>
      <div class="grid sm:grid-cols-2 gap-3 mt-3">
        <div class="rounded-lg bg-canvas border border-line p-3">
          <div class="text-[11px] uppercase tracking-[.09em] text-faint mb-1">Root cause</div>
          <p class="text-[12.5px] text-ink-500 leading-relaxed"><?= esc($p['cause']) ?></p></div>
        <div class="rounded-lg bg-canvas border border-line p-3">
          <div class="text-[11px] uppercase tracking-[.09em] text-faint mb-1">Workaround</div>
          <p class="text-[12.5px] text-ink-500 leading-relaxed"><?= esc($p['workaround']) ?></p></div>
      </div>
      <div class="flex items-center gap-3 mt-3 text-[12px] text-muted">
        <a href="<?= site_url('app/problems/' . $p['id']) ?>" class="inline-flex items-center gap-1.5 text-brand font-medium hover:underline"><?= th_icon('link', 'w-3.5 h-3.5') ?><?= $linkedCounts[(int) $p['id']] ?? 0 ?> linked incidents</a>
        <span class="text-line">·</span><span>Opened <?= th_rel($p['opened_at']) ?></span>
        <a href="<?= site_url('app/problems/' . $p['id']) ?>" class="ml-auto inline-flex items-center gap-1 text-[12.5px] text-ink-500 font-medium hover:text-brand">Open <?= th_icon('right', 'w-3.5 h-3.5') ?></a>
      </div>
    </div>
    <?php endforeach ?>
  </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('modals') ?>
<template id="tpl-newProblem">
  <form method="post" action="<?= site_url('app/problems') ?>" data-modal-title="Raise a problem" data-submit="Raise problem">
    <?= csrf_field() ?>
    <div class="grid sm:grid-cols-2 gap-3.5">
      <div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Pattern you are seeing<span class="text-alert"> *</span></label>
        <input name="title" required class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Priority</label>
        <select name="priority" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px]">
          <option>Urgent</option><option>High</option><option selected>Medium</option><option>Low</option>
        </select></div>
      <div class="flex items-end"><p class="text-[11.5px] text-faint leading-relaxed pb-2">Link incidents from the problem page once it exists.</p></div>
      <div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Known workaround</label>
        <textarea name="workaround" rows="3" class="w-full px-2.5 py-2 rounded-lg border border-line text-[13px] leading-relaxed focus:border-brand"></textarea></div>
    </div>
  </form>
</template>
<?= $this->endSection() ?>
