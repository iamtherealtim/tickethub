<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
<?= view('partials/head', ['title' => 'Choose a new password · TicketHub']) ?>
</head>
<body class="h-full">
<div class="min-h-full grid place-items-center p-6 bg-canvas">
  <div class="w-full max-w-[380px]">
    <div class="flex items-center gap-2.5 mb-8">
      <span class="w-8 h-8 rounded-md bg-brand grid place-items-center font-display font-bold text-[14px] text-white">TH</span>
      <span class="font-display font-semibold text-[15px]">TicketHub</span>
    </div>
    <h1 class="font-display text-[24px] font-semibold">Choose a new password</h1>
    <p class="text-[13px] text-muted mt-1">At least 8 characters. This signs out any remembered devices.</p>

    <?php if (! empty($error)): ?>
    <div class="mt-4 flex items-start gap-2.5 rounded-lg border border-alert-100 bg-alert-50 p-3 text-[13px] text-alert">
      <?= th_icon('warn', 'w-4 h-4 mt-0.5 shrink-0') ?><?= esc($error) ?>
    </div>
    <?php endif ?>

    <form method="post" action="<?= site_url('reset/' . $token) ?>" class="mt-5 space-y-4">
      <?= csrf_field() ?>
      <div>
        <label class="block text-[12px] font-medium text-ink-500 mb-1.5">New password</label>
        <input name="password" type="password" required minlength="8" autofocus
          class="w-full h-10 px-3 rounded-lg border border-line bg-white text-[14px] focus:border-brand">
      </div>
      <div>
        <label class="block text-[12px] font-medium text-ink-500 mb-1.5">Repeat it</label>
        <input name="confirm" type="password" required minlength="8"
          class="w-full h-10 px-3 rounded-lg border border-line bg-white text-[14px] focus:border-brand">
      </div>
      <button type="submit" class="w-full h-10 rounded-lg bg-brand hover:bg-brand-600 text-white text-[14px] font-semibold transition">Set password</button>
    </form>
  </div>
</div>
</body>
</html>
