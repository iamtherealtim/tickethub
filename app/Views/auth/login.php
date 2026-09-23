<!DOCTYPE html>
<html lang="<?= esc(service('request')->getLocale(), 'attr') ?>" class="h-full">
<head>
<?= view('partials/head', ['title' => lang('Auth.login.title') . ' · ' . lang('Common.app')]) ?>
</head>
<body class="h-full">
<div class="min-h-full grid lg:grid-cols-2">
  <section class="th-inverse hidden lg:flex flex-col bg-ink text-white p-10">
    <div class="flex items-center gap-2.5">
      <span class="w-8 h-8 rounded-md bg-brand grid place-items-center font-display font-bold text-[14px] text-white">TH</span>
      <div class="leading-none">
        <div class="font-display font-semibold text-[15px]"><?= lang('Common.app') ?></div>
        <div class="text-[10px] tracking-[.14em] text-[#6C7688] uppercase mt-[3px]"><?= lang('Common.tagline') ?></div>
      </div>
    </div>
    <div class="flex-1 grid place-items-center">
      <div class="max-w-[400px]">
        <h1 class="font-display text-[34px] font-semibold leading-[1.15]"><?= lang('Auth.login.pitch') ?></h1>
        <p class="text-[14px] text-[#9AA3B4] mt-4 leading-relaxed"><?= lang('Auth.login.pitchBody') ?></p>
        <div class="mt-8 space-y-3">
          <?php foreach ([['inbox', lang('Auth.login.feature1')], ['clock', lang('Auth.login.feature2')], ['book', lang('Auth.login.feature3')]] as [$ic, $line]): ?>
          <div class="flex items-center gap-3 text-[13px] text-[#C7CDD8]">
            <span class="w-8 h-8 rounded-lg bg-white/6 border border-white/10 grid place-items-center text-brand-100"><?= th_icon($ic, 'w-4 h-4') ?></span><?= esc($line) ?>
          </div>
          <?php endforeach ?>
        </div>
      </div>
    </div>
    <p class="text-[12px] text-[#6C7688]"><?= esc(lang('Auth.login.copyright', ['year' => date('Y')])) ?> · <?= esc(lang('Common.version', [TICKETHUB_VERSION])) ?></p>
  </section>

  <section class="flex items-center justify-center p-6">
    <div class="w-full max-w-[380px]">
      <div class="lg:hidden flex items-center gap-2.5 mb-8">
        <span class="w-8 h-8 rounded-md bg-brand grid place-items-center font-display font-bold text-[14px] text-white">TH</span>
        <span class="font-display font-semibold text-[15px]"><?= lang('Common.app') ?></span>
      </div>
      <h2 class="font-display text-[24px] font-semibold"><?= lang('Auth.login.heading') ?></h2>
      <p class="text-[13px] text-muted mt-1"><?= lang('Auth.login.intro') ?></p>

      <?php if (! empty($error)): ?>
      <div class="mt-4 flex items-start gap-2.5 rounded-lg border border-alert-100 bg-alert-50 p-3 text-[13px] text-alert">
        <?= th_icon('warn', 'w-4 h-4 mt-0.5 shrink-0') ?><?= esc($error) ?>
      </div>
      <?php endif ?>

      <form method="post" action="<?= site_url('login') ?>" class="mt-5 space-y-4">
        <?= csrf_field() ?>
        <div>
          <label class="block text-[12px] font-medium text-ink-500 mb-1.5"><?= lang('Auth.login.email') ?></label>
          <input name="email" type="email" required autofocus placeholder="<?= esc(lang('Auth.login.emailPlaceholder'), 'attr') ?>"
            class="w-full h-10 px-3 rounded-lg border border-line bg-white text-[14px] placeholder:text-faint focus:border-brand">
        </div>
        <div>
          <div class="flex items-center justify-between mb-1.5">
            <label class="block text-[12px] font-medium text-ink-500"><?= lang('Auth.login.password') ?></label>
            <a href="<?= site_url('forgot') ?>" class="text-[12px] text-brand font-medium hover:underline"><?= lang('Auth.login.forgot') ?></a>
          </div>
          <input name="password" type="password" required placeholder="••••••••"
            class="w-full h-10 px-3 rounded-lg border border-line bg-white text-[14px] placeholder:text-faint focus:border-brand">
        </div>
        <label class="flex items-center gap-2 text-[13px] text-ink-500 cursor-pointer">
          <input type="checkbox" name="remember" value="1" class="w-[15px] h-[15px] rounded-sm border-line"> <?= lang('Auth.login.remember') ?>
        </label>
        <button type="submit" class="w-full h-10 rounded-lg bg-brand hover:bg-brand-600 text-white text-[14px] font-semibold transition"><?= lang('Auth.login.submit') ?></button>
      </form>

      <?php if (! empty($ldapEnabled)): ?>
      <p class="mt-3 flex items-center gap-2 text-[12.5px] text-muted"><?= th_icon('server', 'w-3.5 h-3.5 text-faint') ?> Sign in with your directory account — your usual network username or email and password.</p>
      <?php endif ?>

      <?php if (! empty($azureEnabled) || ! empty($oidcEnabled) || ! empty($samlEnabled)): ?>
      <div class="flex items-center gap-3 my-5">
        <span class="h-px flex-1 bg-line"></span><span class="text-[11px] uppercase tracking-[.09em] text-faint"><?= lang('Auth.login.or') ?></span><span class="h-px flex-1 bg-line"></span>
      </div>
      <div class="space-y-2.5">
      <?php if (! empty($azureEnabled)): ?>
      <a href="<?= site_url('auth/azure') ?>" class="w-full h-10 rounded-lg border border-line bg-white hover:bg-canvas text-[14px] font-medium text-ink inline-flex items-center justify-center gap-2.5 transition">
        <svg class="w-4 h-4" viewBox="0 0 21 21" aria-hidden="true">
          <rect x="1" y="1" width="9" height="9" fill="#F25022"/><rect x="11" y="1" width="9" height="9" fill="#7FBA00"/>
          <rect x="1" y="11" width="9" height="9" fill="#00A4EF"/><rect x="11" y="11" width="9" height="9" fill="#FFB900"/>
        </svg>
        <?= lang('Auth.login.microsoft') ?>
      </a>
      <?php endif ?>
      <?php if (! empty($oidcEnabled)): ?>
      <a href="<?= site_url('auth/oidc') ?>" class="w-full h-10 rounded-lg border border-line bg-white hover:bg-canvas text-[14px] font-medium text-ink inline-flex items-center justify-center gap-2.5 transition">
        <?php if (str_contains(strtolower($oidcLabel ?? ''), 'google')): ?>
        <svg class="w-4 h-4" viewBox="0 0 24 24" aria-hidden="true">
          <path fill="#4285F4" d="M23.5 12.3c0-.8-.1-1.6-.2-2.3H12v4.5h6.5c-.3 1.5-1.1 2.7-2.4 3.6v3h3.9c2.3-2.1 3.5-5.2 3.5-8.8z"/>
          <path fill="#34A853" d="M12 24c3.2 0 6-1.1 8-2.9l-3.9-3c-1.1.7-2.5 1.2-4.1 1.2-3.1 0-5.8-2.1-6.7-5H1.2v3.1C3.2 21.3 7.3 24 12 24z"/>
          <path fill="#FBBC05" d="M5.3 14.3c-.2-.7-.4-1.5-.4-2.3s.1-1.6.4-2.3V6.6H1.2C.4 8.2 0 10 0 12s.4 3.8 1.2 5.4l4.1-3.1z"/>
          <path fill="#EA4335" d="M12 4.8c1.8 0 3.3.6 4.6 1.8l3.4-3.4C18 1.2 15.2 0 12 0 7.3 0 3.2 2.7 1.2 6.6l4.1 3.1c.9-2.9 3.6-4.9 6.7-4.9z"/>
        </svg>
        <?php else: ?>
        <?= th_icon('shield', 'w-4 h-4 text-brand') ?>
        <?php endif ?>
        <?= esc($oidcLabel ?? 'Sign in with SSO') ?>
      </a>
      <?php endif ?>
      <?php if (! empty($samlEnabled)): ?>
      <a href="<?= site_url('auth/saml') ?>" class="w-full h-10 rounded-lg border border-line bg-white hover:bg-canvas text-[14px] font-medium text-ink inline-flex items-center justify-center gap-2.5 transition">
        <?= th_icon('shield', 'w-4 h-4 text-brand') ?>
        <?= esc($samlLabel ?? 'Sign in with SSO') ?>
      </a>
      <?php endif ?>
      </div>
      <?php endif ?>

      <?php if (ENVIRONMENT !== 'production'): ?>
      <div class="mt-6 rounded-xl border border-line bg-white p-4">
        <div class="text-[11px] font-semibold uppercase tracking-[.09em] text-faint mb-2"><?= lang('Auth.login.demoTitle') ?></div>
        <p class="text-[11.5px] text-faint mb-2 leading-relaxed"><?= lang('Auth.login.demoBody') ?></p>
        <div class="space-y-1.5 text-[12.5px]">
          <div class="flex justify-between gap-3"><span class="text-ink-500"><?= lang('Auth.login.demoAdmin') ?></span><span class="font-mono text-muted">maya.ortiz@tickethub.co</span></div>
          <div class="flex justify-between gap-3"><span class="text-ink-500"><?= lang('Auth.login.demoAgent') ?></span><span class="font-mono text-muted">priya.raman@tickethub.co</span></div>
          <div class="flex justify-between gap-3"><span class="text-ink-500"><?= lang('Auth.login.demoEmployee') ?></span><span class="font-mono text-muted">jordan.whitfield@tickethub.co</span></div>
        </div>
      </div>
      <?php endif ?>
    </div>
  </section>
</div>
</body>
</html>
