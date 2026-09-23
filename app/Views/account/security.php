<?= $this->extend($layout) ?>
<?= $this->section('content') ?>
<?php
$inputCls = 'w-full h-9 px-2.5 rounded-lg border border-line bg-white text-[13px] placeholder:text-faint focus:border-brand';
$wrap = $layout === 'layouts/agent' ? 'p-5 max-w-[860px] mx-auto fade-in' : 'max-w-[860px] mx-auto py-6';
?>
<div class="<?= $wrap ?>">
  <div class="mb-5 flex items-start justify-between gap-4">
    <div>
      <h1 class="font-display text-[22px] font-semibold text-ink">Security</h1>
      <p class="text-[13px] text-muted mt-1">Two-factor authentication for <?= esc($me['email']) ?></p>
    </div>
    <a href="<?= site_url('account/notifications') ?>" class="inline-flex items-center gap-1.5 h-9 px-3 rounded-lg border border-line bg-white text-[12.5px] font-medium text-ink-500 hover:bg-canvas"><?= th_icon('bell', 'w-4 h-4') ?> Notification preferences</a>
  </div>

  <?php if (! empty($mfaRequired) && empty($totpEnabled)): ?>
  <div class="mb-4 rounded-xl border border-alert/40 bg-alert/5 px-4 py-3 text-[13px] text-ink flex items-start gap-2.5">
    <span class="text-alert mt-0.5"><?= th_icon('shield', 'w-4 h-4') ?></span>
    <div><b>Your administrator requires two-factor authentication for your role.</b>
      <span class="text-muted">Set it up below to get back to the rest of TicketHub.</span></div>
  </div>
  <?php endif ?>

  <?php if (! empty($newCodes)): ?>
  <section class="bg-white border border-brand rounded-xl shadow-card mb-4">
    <div class="flex items-center justify-between gap-3 px-4 h-12 border-b border-line">
      <h2 class="font-display text-[14px] font-semibold text-ink">Your recovery codes — save these now</h2>
      <span class="text-[12px] text-alert font-semibold">Shown once</span>
    </div>
    <div class="p-4">
      <p class="text-[13px] text-muted mb-3">Each code signs you in once if you lose your phone. Keep them somewhere safe (a password manager is ideal). They will not be shown again.</p>
      <div class="grid grid-cols-2 sm:grid-cols-5 gap-2 font-mono text-[13.5px]" id="recoveryCodes">
        <?php foreach ($newCodes as $c): ?>
        <div class="rounded-lg border border-line bg-canvas px-2.5 py-2 text-center select-all"><?= esc($c) ?></div>
        <?php endforeach ?>
      </div>
      <div class="flex items-center gap-2 mt-3">
        <button type="button" onclick="navigator.clipboard.writeText([...document.querySelectorAll('#recoveryCodes div')].map(d=>d.textContent.trim()).join('\n')).then(()=>this.textContent='Copied')" class="inline-flex items-center gap-1.5 h-8 px-2.5 rounded-lg border border-line bg-white text-[12.5px] font-medium text-ink-500 hover:bg-canvas">Copy all</button>
        <a download="tickethub-recovery-codes.txt" href="data:text/plain;charset=utf-8,<?= rawurlencode("TicketHub recovery codes for " . $me['email'] . "\n\n" . implode("\n", $newCodes) . "\n") ?>" class="inline-flex items-center gap-1.5 h-8 px-2.5 rounded-lg border border-line bg-white text-[12.5px] font-medium text-ink-500 hover:bg-canvas">Download</a>
      </div>
    </div>
  </section>
  <?php endif ?>

  <?php if (! empty($totpEnabled)): ?>
  <?= th_card(
      th_card_head('Two-factor authentication', '<span class="text-brand font-semibold">On</span>')
      . '<div class="p-4 space-y-4">'
      . '<div class="flex items-start gap-3">'
      . '<span class="w-9 h-9 rounded-lg bg-brand-50 border border-brand-100 grid place-items-center text-brand shrink-0">' . th_icon('shield', 'w-4 h-4') . '</span>'
      . '<div class="text-[13px]"><div class="font-medium text-ink">Authenticator app is enrolled</div>'
      . '<div class="text-muted mt-0.5">Enabled ' . esc(th_date($totpEnabledAt)) . ' · ' . (int) $recoveryLeft . ' of 10 recovery codes unused'
      . ((int) $recoveryLeft <= 2 ? ' — <span class="text-alert font-medium">generate new ones</span>' : '') . '</div></div></div>'
      . '</div>'
  ) ?>

  <div class="grid md:grid-cols-2 gap-4 mt-4">
    <form method="post" action="<?= site_url('account/security/recovery') ?>">
      <?= csrf_field() ?>
      <?= th_card(
          th_card_head('New recovery codes')
          . '<div class="p-4 space-y-3">'
          . '<p class="text-[12.5px] text-muted">Replaces every unused code with ten fresh ones.</p>'
          . '<div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">' . ($localPassword ? 'Your password' : 'Current authenticator code') . '</label>'
          . ($localPassword
              ? '<input name="password" type="password" required autocomplete="current-password" class="' . $inputCls . '">'
              : '<input name="code" required inputmode="numeric" autocomplete="one-time-code" placeholder="123456" class="' . $inputCls . ' font-mono">')
          . '</div>'
          . '<button type="submit" class="h-9 px-3.5 rounded-lg border border-line bg-white text-[13px] font-medium text-ink-500 hover:bg-canvas">Generate new codes</button>'
          . '</div>'
      ) ?>
    </form>
    <form method="post" action="<?= site_url('account/security/disable') ?>" onsubmit="return confirm('Turn off two-factor authentication?')">
      <?= csrf_field() ?>
      <?= th_card(
          th_card_head('Turn off two-factor')
          . '<div class="p-4 space-y-3">'
          . '<p class="text-[12.5px] text-muted">Your password alone will sign you in again.' . (! empty($mfaRequired) ? ' <b class="text-alert">Your role requires 2FA — you will be asked to enrol again.</b>' : '') . '</p>'
          . '<div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">' . ($localPassword ? 'Your password' : 'Current authenticator code') . '</label>'
          . ($localPassword
              ? '<input name="password" type="password" required autocomplete="current-password" class="' . $inputCls . '">'
              : '<input name="code" required inputmode="numeric" autocomplete="one-time-code" placeholder="123456" class="' . $inputCls . ' font-mono">')
          . '</div>'
          . '<button type="submit" class="h-9 px-3.5 rounded-lg border border-alert-100 bg-white text-[13px] font-medium text-alert hover:bg-alert-50">Turn off</button>'
          . '</div>'
      ) ?>
    </form>
  </div>

  <?php elseif (! empty($pendingSecret)): ?>
  <section class="bg-white border border-line rounded-xl shadow-card">
    <div class="flex items-center justify-between gap-3 px-4 h-12 border-b border-line">
      <h2 class="font-display text-[14px] font-semibold text-ink">Set up your authenticator app</h2>
      <span class="text-[12px] text-muted">Step 2 of 2</span>
    </div>
    <div class="p-4 grid md:grid-cols-[200px_1fr] gap-5">
      <div>
        <div id="qr" class="w-[200px] h-[200px] rounded-xl border border-line bg-white grid place-items-center overflow-hidden"></div>
        <p class="text-[11.5px] text-faint mt-2 text-center">Google Authenticator, Microsoft Authenticator, Authy, 1Password…</p>
      </div>
      <div class="space-y-4">
        <ol class="text-[13px] text-ink-500 leading-relaxed list-decimal ml-4 space-y-1">
          <li>Scan the QR code with your authenticator app, or enter this key by hand:
            <code class="block mt-1 font-mono text-[12.5px] bg-canvas border border-line rounded-sm px-2 py-1.5 select-all tracking-[.1em]"><?= esc(trim(chunk_split($pendingSecret, 4, ' '))) ?></code></li>
          <li>Type the 6-digit code the app shows to confirm.</li>
        </ol>
        <form method="post" action="<?= site_url('account/security/enable') ?>" class="flex items-end gap-2">
          <?= csrf_field() ?>
          <div class="flex-1 max-w-[200px]">
            <label class="block text-[12px] font-medium text-ink-500 mb-1.5">Code from the app</label>
            <input name="code" required autofocus inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" placeholder="123 456" class="<?= $inputCls ?> font-mono text-[15px] tracking-[.15em]">
          </div>
          <button type="submit" class="h-9 px-3.5 rounded-lg bg-brand hover:bg-brand-600 text-white text-[13px] font-semibold">Turn on 2FA</button>
        </form>
        <form method="post" action="<?= site_url('account/security/enrol') ?>" class="inline"><?= csrf_field() ?>
          <button type="submit" class="text-[12px] text-muted hover:text-ink underline">Generate a different key</button>
        </form>
      </div>
    </div>
  </section>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
  <script>
    (function () {
      var el = document.getElementById('qr');
      if (window.QRCode) {
        new QRCode(el, { text: <?= json_encode($pendingUri) ?>, width: 184, height: 184, correctLevel: QRCode.CorrectLevel.M });
      } else {
        el.innerHTML = '<span class="text-[12px] text-muted p-3 text-center">QR library unavailable — enter the key by hand.</span>';
      }
    })();
  </script>

  <?php else: ?>
  <?= th_card(
      th_card_head('Two-factor authentication', '<span class="text-faint font-semibold">Off</span>')
      . '<div class="p-4 flex flex-col sm:flex-row sm:items-center gap-4">'
      . '<div class="flex items-start gap-3 flex-1">'
      . '<span class="w-9 h-9 rounded-lg bg-canvas border border-line grid place-items-center text-faint shrink-0">' . th_icon('lock', 'w-4 h-4') . '</span>'
      . '<div class="text-[13px]"><div class="font-medium text-ink">Add a second step to your sign-in</div>'
      . '<div class="text-muted mt-0.5">After your password you will type a 6-digit code from an authenticator app on your phone. Works with Google Authenticator, Microsoft Authenticator, Authy and most password managers.</div></div></div>'
      . '<form method="post" action="' . site_url('account/security/enrol') . '">' . csrf_field()
      . '<button type="submit" class="h-9 px-3.5 rounded-lg bg-brand hover:bg-brand-600 text-white text-[13px] font-semibold whitespace-nowrap">Set up 2FA</button></form>'
      . '</div>'
  ) ?>
  <?php endif ?>

  <?php if (! empty($authProvider) && $authProvider !== 'local'): ?>
  <p class="text-[12px] text-faint mt-4">You last signed in via <?= esc(['ldap' => 'your directory (LDAP)', 'oidc' => 'single sign-on', 'azure' => 'Microsoft'][$authProvider] ?? $authProvider) ?>. Two-factor codes are asked for after that step too.</p>
  <?php endif ?>
</div>
<?= $this->endSection() ?>
