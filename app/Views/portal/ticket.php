<?= $this->extend('layouts/portal') ?>
<?= $this->section('content') ?>

<?php
$s = th_sla($t);
$open = th_is_open($t);
$agent = $t['agent_id'] ? ($users[(int) $t['agent_id']] ?? null) : null;
// Resolved tickets stay repliable for five days — that reopens them, as promised below.
$reopenWindow = ! $open && $t['status'] === 'Resolved' && $t['resolved_at']
    && (time() - strtotime($t['resolved_at'])) < 5 * 86400;
$canReply = $open || $reopenWindow;
?>
<div class="max-w-[820px] mx-auto px-5 py-8 fade-in">
  <a href="<?= site_url('portal/tickets') ?>" class="inline-flex items-center gap-1 text-[12.5px] text-muted hover:text-ink mb-4"><?= th_icon('back', 'w-3.5 h-3.5') ?> My tickets</a>
  <div class="bg-white border border-line rounded-xl shadow-card p-5">
    <div class="flex flex-wrap items-center gap-2 mb-2">
      <span class="font-mono text-[12px] text-faint"><?= esc($t['code']) ?></span><?= th_status_chip($t['status']) ?>
      <span class="text-[12px] text-muted"><?= esc($t['type']) ?> · <?= esc($t['category']) ?></span>
    </div>
    <h1 class="font-display text-[22px] font-semibold leading-snug"><?= esc($t['subject']) ?></h1>
    <div class="grid sm:grid-cols-3 gap-3 mt-4 pt-4 border-t border-line">
      <div><div class="text-[11px] uppercase tracking-[.09em] text-faint">Opened</div>
        <div class="text-[13px] text-ink-500 mt-0.5"><?= th_date($t['created_at']) ?></div></div>
      <div><div class="text-[11px] uppercase tracking-[.09em] text-faint">Handled by</div>
        <div class="text-[13px] text-ink-500 mt-0.5 flex items-center gap-1.5"><?= $agent ? th_avatar($agent, 20) . esc($agent['name']) : 'Being assigned' ?></div></div>
      <div><div class="text-[11px] uppercase tracking-[.09em] text-faint"><?= $open ? 'We aim to resolve by' : 'Resolved' ?></div>
        <div class="text-[13px] mt-0.5 <?= $open && $s['remaining'] < 0 ? 'text-alert' : 'text-ink-500' ?>"><?= $open ? th_date($t['res_due']) : th_date($t['resolved_at'] ?? $t['updated_at']) ?></div></div>
    </div>
    <?php if (! empty($fieldValues)): ?>
    <div class="grid sm:grid-cols-3 gap-3 mt-4 pt-4 border-t border-line">
      <?php foreach ($fieldValues as $fv): ?>
      <div><div class="text-[11px] uppercase tracking-[.09em] text-faint"><?= esc($fv['label']) ?></div>
        <div class="text-[13px] text-ink-500 mt-0.5 break-words"><?= esc($fv['value']) ?></div></div>
      <?php endforeach ?>
    </div>
    <?php endif ?>
  </div>

  <?php if (! $open && $t['csat_score'] === null): ?>
  <div class="mt-4 bg-white border border-brand-100 rounded-xl p-5">
    <h2 class="font-display text-[15px] font-semibold">How did we do?</h2>
    <p class="text-[13px] text-muted mt-1">One tap. It helps us fix the parts that keep going wrong.</p>
    <div class="flex gap-1.5 mt-3">
      <?php for ($n = 1; $n <= 5; $n++): ?>
      <form method="post" action="<?= site_url('portal/tickets/' . $t['code'] . '/rate') ?>"><?= csrf_field() ?>
        <input type="hidden" name="score" value="<?= $n ?>">
        <button type="submit" class="w-10 h-10 rounded-lg border border-line grid place-items-center text-line hover:text-signal-400 hover:border-signal-100 transition" aria-label="<?= $n ?> out of 5">
          <?= th_icon('star', 'w-5 h-5 fill-current') ?></button>
      </form>
      <?php endfor ?>
    </div>
  </div>
  <?php endif ?>
  <?php if ($t['csat_score'] !== null): ?>
  <div class="mt-4 bg-brand-50 border border-brand-100 rounded-xl p-4 flex items-center gap-3">
    <div class="flex gap-0.5 text-signal-400">
      <?php for ($i = 0; $i < 5; $i++): ?><?= th_icon('star', 'w-4 h-4 ' . ($i < (int) $t['csat_score'] ? 'fill-current' : 'text-brand-100 fill-current')) ?><?php endfor ?>
    </div>
    <span class="text-[13px] text-ink-500">Thanks for rating this <?= (int) $t['csat_score'] ?> out of 5.</span>
  </div>
  <?php // A low score without a reason tells the service desk nothing actionable. ?>
  <?php if ((int) $t['csat_score'] <= 3 && ! $t['csat_comment']): ?>
  <div class="mt-3 bg-white border border-line rounded-xl p-5">
    <h2 class="font-display text-[15px] font-semibold">What went wrong?</h2>
    <p class="text-[13px] text-muted mt-1">Optional, but it is the part that actually changes anything.</p>
    <form method="post" action="<?= site_url('portal/tickets/' . $t['code'] . '/rate') ?>" class="mt-3 flex flex-wrap gap-2">
      <?= csrf_field() ?>
      <input name="comment" required maxlength="2000" placeholder="e.g. It took three attempts to get through"
             class="flex-1 min-w-[220px] h-9 px-2.5 rounded-lg border border-line text-[13px] placeholder:text-faint focus:border-brand">
      <button type="submit" class="h-9 px-3.5 rounded-lg bg-ink hover:bg-ink-700 text-white text-[13px] font-semibold">Send</button>
    </form>
  </div>
  <?php elseif ($t['csat_comment']): ?>
  <div class="mt-3 bg-canvas border border-line rounded-xl p-4">
    <div class="text-[11px] uppercase tracking-[.09em] text-faint mb-1">Your feedback</div>
    <p class="text-[13px] text-ink-500 leading-relaxed"><?= esc($t['csat_comment']) ?></p>
  </div>
  <?php endif ?>
  <?php endif ?>

  <div class="mt-4 space-y-3">
    <?php foreach ($messages as $m):
      $by = $users[(int) $m['user_id']] ?? null;
      $isAgent = $by && in_array($by['role'], ['Administrator', 'Supervisor', 'Agent'], true);
      if ($m['kind'] === 'system'): ?>
      <div class="flex items-center gap-3 py-1.5"><span class="h-px flex-1 bg-line"></span>
        <span class="text-[11.5px] text-faint"><?= esc($m['body']) ?> · <?= th_rel($m['created_at']) ?></span><span class="h-px flex-1 bg-line"></span></div>
    <?php else: ?>
      <div class="flex gap-3">
        <div class="pt-0.5"><?= th_avatar($by, 32) ?></div>
        <div class="min-w-0 flex-1 rounded-xl border p-3.5 bg-white border-line">
          <div class="flex items-center gap-2 mb-1.5">
            <span class="text-[13px] font-semibold text-ink"><?= esc($by['name'] ?? 'Unknown') ?></span>
            <span class="text-[11px] text-faint"><?= $isAgent ? esc($by['title'] ?? 'Agent') : 'Requester' ?></span>
            <?php if ($m['kind'] === 'description'): ?><span class="text-[10px] font-bold uppercase tracking-wide text-faint">Original request</span><?php endif ?>
            <span class="ml-auto text-[11.5px] text-faint" title="<?= th_date($m['created_at']) ?>"><?= th_rel($m['created_at']) ?></span>
          </div>
          <div class="text-[13.5px] leading-relaxed text-ink-500 whitespace-pre-line"><?= esc($m['body']) ?></div>
          <?= th_att_chips($m['attachments']) ?>
        </div>
      </div>
    <?php endif; endforeach ?>
  </div>

  <?php if ($canReply): ?>
  <div class="mt-4 bg-white border <?= $reopenWindow ? 'border-signal-100' : 'border-line' ?> rounded-xl shadow-card p-4">
    <?php if ($reopenWindow): ?>
    <p class="text-[12.5px] text-signal mb-2 flex items-start gap-1.5"><?= th_icon('warn', 'w-3.5 h-3.5 mt-0.5 shrink-0') ?>
      This request is resolved. Replying reopens it — you have <?= th_dur(5 * 86400 - (time() - strtotime($t['resolved_at']))) ?> left.</p>
    <?php endif ?>
    <form method="post" action="<?= site_url('portal/tickets/' . $t['code'] . '/reply') ?>" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <label class="block text-[12.5px] font-medium text-ink-500 mb-2"><?= $reopenWindow ? 'Reopen this request' : 'Add to this request' ?></label>
      <textarea name="body" rows="4" placeholder="Anything new — an error message, a screenshot, or a nudge if it is now urgent."
        class="w-full px-3 py-2.5 rounded-lg border border-line text-[13.5px] leading-relaxed placeholder:text-faint focus:border-brand"></textarea>
      <div class="flex items-center gap-2 mt-2">
        <label class="inline-flex items-center gap-1.5 h-9 px-3 rounded-lg border border-line text-[12.5px] text-muted hover:bg-canvas cursor-pointer">
          <?= th_icon('clip', 'w-3.5 h-3.5') ?>Attach<input type="file" name="files[]" multiple class="hidden" onchange="this.parentNode.querySelector('span').textContent = this.files.length + ' file(s)'"><span></span></label>
        <div class="flex-1"></div>
        <button type="submit" class="inline-flex items-center gap-1.5 h-9 px-3.5 rounded-lg bg-brand hover:bg-brand-600 text-white text-[13px] font-semibold"><?= th_icon('send', 'w-3.5 h-3.5') ?>Send</button>
      </div>
    </form>
  </div>
  <?php else: ?>
  <div class="mt-4 rounded-xl border border-line bg-white p-4 text-[13px] text-muted">
    This ticket is <?= strtolower($t['status']) ?> and can no longer be reopened.
    <a href="<?= site_url('portal/new') ?>" class="text-brand font-medium hover:underline ml-1">Raise a new request</a>
  </div>
  <?php endif ?>
</div>
<?= $this->endSection() ?>
