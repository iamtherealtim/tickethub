<!doctype html>
<html lang="<?= esc(service('request')->getLocale(), 'attr') ?>">
<?= view('partials/head', ['title' => lang('Auth.logout.title') . ' · ' . lang('Common.app')]) ?>
<body class="bg-canvas text-ink font-sans antialiased">
<div class="min-h-screen flex items-center justify-center p-6">
  <form method="post" action="<?= site_url('logout') ?>" id="logoutForm" class="w-full max-w-sm rounded-xl border border-line bg-white p-6 text-center shadow-card">
    <?= csrf_field() ?>
    <p class="text-[14px] text-ink-500 mb-4"><?= lang('Auth.logout.working') ?></p>
    <button type="submit" class="h-10 px-4 rounded-lg bg-brand hover:bg-brand-600 text-white text-[14px] font-semibold transition"><?= lang('Auth.logout.submit') ?></button>
    <p class="text-[12px] text-faint mt-3"><?= lang('Auth.logout.hint') ?></p>
  </form>
</div>
<script>document.getElementById('logoutForm').submit();</script>
</body>
</html>
