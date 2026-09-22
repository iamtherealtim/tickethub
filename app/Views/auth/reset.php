<!DOCTYPE html>
<html lang="<?= esc(service('request')->getLocale(), 'attr') ?>" class="h-full">
<head>
<?= view('partials/head', ['title' => lang('Auth.reset.title') . ' · ' . lang('Common.app')]) ?>
</head>
<body class="h-full">
<div class="min-h-full grid place-items-center p-6 bg-canvas">
  <div class="w-full max-w-[380px]">
    <div class="flex items-center gap-2.5 mb-8">
      <span class="w-8 h-8 rounded-md bg-brand grid place-items-center font-display font-bold text-[14px] text-white">TH</span>
      <span class="font-display font-semibold text-[15px]"><?= lang('Common.app') ?></span>
    </div>
    <h1 class="font-display text-[24px] font-semibold"><?= lang('Auth.reset.heading') ?></h1>
    <p class="text-[13px] text-muted mt-1"><?= lang('Auth.reset.intro') ?></p>

    <?php if (! empty($error)): ?>
    <div class="mt-4 flex items-start gap-2.5 rounded-lg border border-alert-100 bg-alert-50 p-3 text-[13px] text-alert">
      <?= th_icon('warn', 'w-4 h-4 mt-0.5 shrink-0') ?><?= esc($error) ?>
    </div>
    <?php endif ?>

    <form method="post" action="<?= site_url('reset/' . $token) ?>" class="mt-5 space-y-4">
      <?= csrf_field() ?>
      <div>
        <label class="block text-[12px] font-medium text-ink-500 mb-1.5"><?= lang('Auth.reset.password') ?></label>
        <input name="password" type="password" required minlength="12" autofocus
          class="w-full h-10 px-3 rounded-lg border border-line bg-white text-[14px] focus:border-brand">
      </div>
      <div>
        <label class="block text-[12px] font-medium text-ink-500 mb-1.5"><?= lang('Auth.reset.repeat') ?></label>
        <input name="confirm" type="password" required minlength="12"
          class="w-full h-10 px-3 rounded-lg border border-line bg-white text-[14px] focus:border-brand">
      </div>
      <button type="submit" class="w-full h-10 rounded-lg bg-brand hover:bg-brand-600 text-white text-[14px] font-semibold transition"><?= lang('Auth.reset.submit') ?></button>
    </form>
  </div>
</div>
</body>
</html>
