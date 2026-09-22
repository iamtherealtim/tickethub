<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
<?= view('partials/head', ['title' => 'Sign in · TicketHub']) ?>
</head>
<body class="h-full">
<div class="min-h-full grid lg:grid-cols-2">
  <section class="hidden lg:flex flex-col bg-ink text-white p-10">
    <div class="flex items-center gap-2.5">
      <span class="w-8 h-8 rounded-md bg-brand grid place-items-center font-display font-bold text-[14px] text-white">TH</span>
      <div class="leading-none">
        <div class="font-display font-semibold text-[15px]">TicketHub</div>
        <div class="text-[10px] tracking-[.14em] text-[#6C7688] uppercase mt-[3px]">Service desk</div>
      </div>
    </div>
    <div class="flex-1 grid place-items-center">
      <div class="max-w-[400px]">
        <h1 class="font-display text-[34px] font-semibold leading-[1.15]">Every ticket, on the clock.</h1>
        <p class="text-[14px] text-[#9AA3B4] mt-4 leading-relaxed">Incidents, requests, changes, and problems in one queue — with the SLA burn always in sight, from first response to resolution.</p>
        <div class="mt-8 space-y-3">
          <?php foreach ([['inbox', 'Unified queue with saved views and bulk triage'], ['clock', 'SLA breach horizon across every open ticket'], ['book', 'Knowledge base that deflects repeat tickets']] as [$ic, $line]): ?>
          <div class="flex items-center gap-3 text-[13px] text-[#C7CDD8]">
            <span class="w-8 h-8 rounded-lg bg-white/[.06] border border-white/10 grid place-items-center text-brand-100"><?= th_icon($ic, 'w-4 h-4') ?></span><?= $line ?>
          </div>
          <?php endforeach ?>
        </div>
      </div>
    </div>
    <p class="text-[12px] text-[#6C7688]">© <?= date('Y') ?> TicketHub</p>
  </section>

  <section class="flex items-center justify-center p-6">
    <div class="w-full max-w-[380px]">
      <div class="lg:hidden flex items-center gap-2.5 mb-8">
        <span class="w-8 h-8 rounded-md bg-brand grid place-items-center font-display font-bold text-[14px] text-white">TH</span>
        <span class="font-display font-semibold text-[15px]">TicketHub</span>
      </div>
      <h2 class="font-display text-[24px] font-semibold">Sign in</h2>
      <p class="text-[13px] text-muted mt-1">Use your work email. Agents land in the workspace, everyone else in the help portal.</p>

      <?php if (! empty($error)): ?>
      <div class="mt-4 flex items-start gap-2.5 rounded-lg border border-alert-100 bg-alert-50 p-3 text-[13px] text-alert">
        <?= th_icon('warn', 'w-4 h-4 mt-0.5 shrink-0') ?><?= esc($error) ?>
      </div>
      <?php endif ?>

      <form method="post" action="<?= site_url('login') ?>" class="mt-5 space-y-4">
        <?= csrf_field() ?>
        <div>
          <label class="block text-[12px] font-medium text-ink-500 mb-1.5">Work email</label>
          <input name="email" type="email" required autofocus placeholder="you@tickethub.co"
            class="w-full h-10 px-3 rounded-lg border border-line bg-white text-[14px] placeholder:text-faint focus:border-brand">
        </div>
        <div>
          <div class="flex items-center justify-between mb-1.5">
            <label class="block text-[12px] font-medium text-ink-500">Password</label>
            <a href="<?= site_url('forgot') ?>" class="text-[12px] text-brand font-medium hover:underline">Forgot password?</a>
          </div>
          <input name="password" type="password" required placeholder="••••••••"
            class="w-full h-10 px-3 rounded-lg border border-line bg-white text-[14px] placeholder:text-faint focus:border-brand">
        </div>
        <label class="flex items-center gap-2 text-[13px] text-ink-500 cursor-pointer">
          <input type="checkbox" name="remember" value="1" class="w-[15px] h-[15px] rounded border-line"> Keep me signed in for 30 days
        </label>
        <button type="submit" class="w-full h-10 rounded-lg bg-brand hover:bg-brand-600 text-white text-[14px] font-semibold transition">Sign in</button>
      </form>

      <?php if (! empty($azureEnabled)): ?>
      <div class="flex items-center gap-3 my-5">
        <span class="h-px flex-1 bg-line"></span><span class="text-[11px] uppercase tracking-[.09em] text-faint">or</span><span class="h-px flex-1 bg-line"></span>
      </div>
      <a href="<?= site_url('auth/azure') ?>" class="w-full h-10 rounded-lg border border-line bg-white hover:bg-canvas text-[14px] font-medium text-ink inline-flex items-center justify-center gap-2.5 transition">
        <svg class="w-4 h-4" viewBox="0 0 21 21" aria-hidden="true">
          <rect x="1" y="1" width="9" height="9" fill="#F25022"/><rect x="11" y="1" width="9" height="9" fill="#7FBA00"/>
          <rect x="1" y="11" width="9" height="9" fill="#00A4EF"/><rect x="11" y="11" width="9" height="9" fill="#FFB900"/>
        </svg>
        Sign in with Microsoft
      </a>
      <?php endif ?>

      <div class="mt-6 rounded-xl border border-line bg-white p-4">
        <div class="text-[11px] font-semibold uppercase tracking-[.09em] text-faint mb-2">Demo accounts · password is “password”</div>
        <p class="text-[11.5px] text-faint mb-2 leading-relaxed">Seeded demo logins only. Accounts created from Admin get a random one-time password and must set their own.</p>
        <div class="space-y-1.5 text-[12.5px]">
          <div class="flex justify-between gap-3"><span class="text-ink-500">Agent admin</span><span class="font-mono text-muted">maya.ortiz@tickethub.co</span></div>
          <div class="flex justify-between gap-3"><span class="text-ink-500">Agent</span><span class="font-mono text-muted">priya.raman@tickethub.co</span></div>
          <div class="flex justify-between gap-3"><span class="text-ink-500">Employee</span><span class="font-mono text-muted">jordan.whitfield@tickethub.co</span></div>
        </div>
      </div>
    </div>
  </section>
</div>
</body>
</html>
