<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
<?= view('partials/head', ['title' => 'Reset password · TicketHub']) ?>
</head>
<body class="h-full">
<div class="min-h-full grid place-items-center p-6 bg-canvas">
  <div class="w-full max-w-[380px]">
    <div class="flex items-center gap-2.5 mb-8">
      <span class="w-8 h-8 rounded-md bg-brand grid place-items-center font-display font-bold text-[14px] text-white">TH</span>
      <span class="font-display font-semibold text-[15px]">TicketHub</span>
    </div>
    <h1 class="font-display text-[24px] font-semibold">Forgot your password?</h1>
    <p class="text-[13px] text-muted mt-1">Enter your work email and we will send a one-hour reset link.</p>

    <?php if (! empty($sent)): ?>
    <div class="mt-4 flex items-start gap-2.5 rounded-lg border border-brand-100 bg-brand-50 p-3 text-[13px] text-brand">
      <?= th_icon('check', 'w-4 h-4 mt-0.5 shrink-0') ?><?= esc($sent) ?>
    </div>
    <?php endif ?>
    <?php if (! empty($error)): ?>
    <div class="mt-4 flex items-start gap-2.5 rounded-lg border border-alert-100 bg-alert-50 p-3 text-[13px] text-alert">
      <?= th_icon('warn', 'w-4 h-4 mt-0.5 shrink-0') ?><?= esc($error) ?>
    </div>
    <?php endif ?>

    <form method="post" action="<?= site_url('forgot') ?>" class="mt-5 space-y-4">
      <?= csrf_field() ?>
      <div>
        <label class="block text-[12px] font-medium text-ink-500 mb-1.5">Work email</label>
        <input name="email" type="email" required autofocus placeholder="you@tickethub.co"
          class="w-full h-10 px-3 rounded-lg border border-line bg-white text-[14px] placeholder:text-faint focus:border-brand">
      </div>
      <button type="submit" class="w-full h-10 rounded-lg bg-brand hover:bg-brand-600 text-white text-[14px] font-semibold transition">Send reset link</button>
    </form>
    <a href="<?= site_url('login') ?>" class="inline-flex items-center gap-1 text-[12.5px] text-muted hover:text-ink mt-5"><?= th_icon('back', 'w-3.5 h-3.5') ?> Back to sign in</a>
  </div>
</div>
</body>
</html>
