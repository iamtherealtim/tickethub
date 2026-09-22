<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
<?= view('partials/head', ['title' => 'Two-factor code · TicketHub']) ?>
</head>
<body class="h-full bg-canvas">
<div class="min-h-full flex items-center justify-center p-6">
  <div class="w-full max-w-[380px]">
    <div class="flex items-center gap-2.5 mb-8">
      <span class="w-8 h-8 rounded-md bg-brand grid place-items-center font-display font-bold text-[14px] text-white">TH</span>
      <span class="font-display font-semibold text-[15px]">TicketHub</span>
    </div>
    <div class="bg-white border border-line rounded-xl shadow-card p-6">
      <div class="w-10 h-10 rounded-xl bg-brand-50 border border-brand-100 grid place-items-center text-brand mb-4"><?= th_icon('shield', 'w-5 h-5') ?></div>
      <h2 class="font-display text-[22px] font-semibold"><?= lang('Auth.twoFactor.heading') ?></h2>
      <p class="text-[13px] text-muted mt-1"><?= lang('Auth.twoFactor.intro') ?></p>

      <?php if (! empty($error)): ?>
      <div class="mt-4 flex items-start gap-2.5 rounded-lg border border-alert-100 bg-alert-50 p-3 text-[13px] text-alert">
        <?= th_icon('warn', 'w-4 h-4 mt-0.5 shrink-0') ?><?= esc($error) ?>
      </div>
      <?php endif ?>

      <form method="post" action="<?= site_url('login/2fa') ?>" class="mt-5 space-y-4">
        <?= csrf_field() ?>
        <div>
          <label class="block text-[12px] font-medium text-ink-500 mb-1.5"><?= lang('Auth.twoFactor.code') ?></label>
          <input name="code" required autofocus autocomplete="one-time-code" inputmode="text" placeholder="123 456"
            class="w-full h-11 px-3 rounded-lg border border-line bg-white text-[18px] font-mono tracking-[.2em] placeholder:text-faint placeholder:tracking-normal focus:border-brand">
        </div>
        <button type="submit" class="w-full h-10 rounded-lg bg-brand hover:bg-brand-600 text-white text-[14px] font-semibold transition"><?= lang('Auth.twoFactor.submit') ?></button>
      </form>
      <a href="<?= site_url('login') ?>" class="block text-center text-[12.5px] text-muted hover:text-ink mt-4"><?= lang('Auth.twoFactor.startOver') ?></a>
    </div>
  </div>
</div>
</body>
</html>
