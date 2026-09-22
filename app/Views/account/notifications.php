<?= $this->extend($layout) ?>
<?= $this->section('content') ?>
<?php
$wrap = $layout === 'layouts/agent' ? 'p-5 max-w-[860px] mx-auto fade-in' : 'max-w-[860px] mx-auto py-6';
$inApp = \App\Libraries\NotificationPrefs::IN_APP;
?>
<div class="<?= $wrap ?>">
  <div class="mb-5 flex items-start justify-between gap-4">
    <div>
      <h1 class="font-display text-[22px] font-semibold text-ink">Notifications</h1>
      <p class="text-[13px] text-muted mt-1">Choose what reaches you, and how.</p>
    </div>
    <a href="<?= site_url('account/security') ?>" class="inline-flex items-center gap-1.5 h-9 px-3 rounded-lg border border-line bg-white text-[12.5px] font-medium text-ink-500 hover:bg-canvas"><?= th_icon('shield', 'w-4 h-4') ?> Security</a>
  </div>

  <form method="post" action="<?= site_url('account/notifications') ?>">
    <?= csrf_field() ?>
    <section class="bg-white border border-line rounded-xl shadow-card">
      <div class="flex items-center justify-between gap-3 px-4 h-12 border-b border-line">
        <h2 class="font-display text-[14px] font-semibold text-ink">What to tell me about</h2>
        <span class="text-[12px] text-muted">Unticked = stay quiet</span>
      </div>
      <div class="hidden md:flex items-center gap-3 px-4 h-9 bg-canvas border-b border-line text-[11px] font-semibold uppercase tracking-[.09em] text-faint">
        <span class="flex-1">Event</span><span class="w-[90px] text-center">Email</span><span class="w-[90px] text-center">In-app bell</span>
      </div>
      <?php foreach ($triggers as $trigger => $label): $p = $prefs[$trigger] ?? ['email' => 1, 'in_app' => 1]; $isInApp = isset($inApp[$trigger]); ?>
      <div class="flex items-center gap-3 px-4 py-2.5 border-b border-line last:border-0 text-[13px]">
        <div class="flex-1 min-w-0">
          <div class="text-ink"><?= esc($label) ?></div>
          <div class="text-[11.5px] text-faint"><?= $isInApp ? 'In-app notification' : 'Email template: ' . esc($trigger) ?></div>
        </div>
        <label class="w-[90px] flex justify-center <?= $isInApp ? 'opacity-40' : '' ?>" title="<?= $isInApp ? 'This event is in-app only' : 'Email' ?>">
          <input type="checkbox" name="pref[<?= esc($trigger, 'attr') ?>][email]" value="1" <?= (int) $p['email'] ? 'checked' : '' ?> class="w-[15px] h-[15px] rounded border-line">
        </label>
        <label class="w-[90px] flex justify-center <?= $isInApp ? '' : 'opacity-40' ?>" title="<?= $isInApp ? 'In-app' : 'This event is email only' ?>">
          <input type="checkbox" name="pref[<?= esc($trigger, 'attr') ?>][in_app]" value="1" <?= (int) $p['in_app'] ? 'checked' : '' ?> class="w-[15px] h-[15px] rounded border-line">
        </label>
      </div>
      <?php endforeach ?>
    </section>

    <section class="bg-white border border-line rounded-xl shadow-card mt-4">
      <div class="flex items-center justify-between gap-3 px-4 h-12 border-b border-line">
        <h2 class="font-display text-[14px] font-semibold text-ink">Delivery</h2>
      </div>
      <div class="p-4">
        <label class="flex items-start gap-2.5 rounded-lg border border-line bg-canvas p-3 cursor-pointer">
          <input type="checkbox" name="digest" value="1" <?= ! empty($digest) ? 'checked' : '' ?> class="w-[15px] h-[15px] rounded border-line mt-0.5">
          <span class="text-[13px]"><span class="font-medium text-ink">Daily digest instead of instant email</span>
            <span class="block text-muted mt-0.5">One summary email a day listing everything new in your bell, instead of an email per event. In-app notifications are unaffected.</span></span>
        </label>
      </div>
      <div class="flex items-center gap-2 px-4 py-3.5 border-t border-line bg-canvas rounded-b-xl">
        <span class="text-[12px] text-muted">Applies to <?= esc($me['email']) ?></span>
        <div class="flex-1"></div>
        <button type="submit" class="h-9 px-3.5 rounded-lg bg-brand hover:bg-brand-600 text-white text-[13px] font-semibold">Save preferences</button>
      </div>
    </section>
  </form>
</div>
<?= $this->endSection() ?>
