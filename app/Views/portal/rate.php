<?php
/**
 * CSAT from the resolution email. Standalone (no session user, so not the
 * portal layout). Vars: state (invalid|open|pick|thanks|rated|commented),
 * t (ticket|null), token, canComment.
 */
$score = $t['csat_score'] ?? null;
$canComment = $canComment ?? false;
$stars = static function (int $n, string $active = 'text-signal-400') {
    $html = '<div class="flex gap-0.5 ' . $active . '">';
    for ($i = 0; $i < 5; $i++) {
        $html .= th_icon('star', 'w-5 h-5 fill-current ' . ($i < $n ? '' : 'text-line'));
    }

    return $html . '</div>';
};
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
<?= view('partials/head', ['title' => 'Rate this ticket · TicketHub Help']) ?>
</head>
<body class="h-full">
<div class="min-h-full bg-canvas flex flex-col">
  <header class="bg-white border-b border-line">
    <div class="max-w-[640px] mx-auto px-5 h-16 flex items-center gap-2.5">
      <span class="w-7 h-7 rounded-md bg-ink grid place-items-center font-display font-bold text-[13px] text-white">TH</span>
      <span class="font-display font-semibold text-[14px]">TicketHub <span class="text-muted font-normal">Help</span></span>
    </div>
  </header>
  <main class="flex-1">
    <div class="max-w-[640px] mx-auto px-5 py-10 fade-in">
      <?php if ($state === 'invalid'): ?>
      <div class="bg-white border border-line rounded-xl shadow-card p-6">
        <h1 class="font-display text-[20px] font-semibold">That link has expired</h1>
        <p class="text-[13.5px] text-muted mt-2 leading-relaxed">Rating links work once and only for a resolved ticket. If you still want to tell us how it went, sign in and rate it from the ticket page.</p>
        <a href="<?= site_url('portal/tickets') ?>" class="inline-flex items-center mt-4 h-9 px-3.5 rounded-lg bg-ink hover:bg-ink-700 text-white text-[13px] font-semibold">My tickets</a>
      </div>

      <?php elseif ($state === 'open'): ?>
      <div class="bg-white border border-line rounded-xl shadow-card p-6">
        <div class="font-mono text-[12px] text-faint"><?= esc($t['code']) ?></div>
        <h1 class="font-display text-[20px] font-semibold mt-1"><?= esc($t['subject']) ?></h1>
        <p class="text-[13.5px] text-muted mt-2 leading-relaxed">This ticket has been reopened, so there is nothing to rate yet. We will ask again once it is resolved.</p>
        <a href="<?= site_url('portal/tickets/' . $t['code']) ?>" class="inline-flex items-center mt-4 h-9 px-3.5 rounded-lg border border-line bg-white text-[13px] font-medium text-ink-500 hover:bg-canvas">Open the ticket</a>
      </div>

      <?php elseif ($state === 'pick'): ?>
      <div class="bg-white border border-line rounded-xl shadow-card p-6">
        <div class="font-mono text-[12px] text-faint"><?= esc($t['code']) ?></div>
        <h1 class="font-display text-[20px] font-semibold mt-1">How did we do?</h1>
        <p class="text-[13.5px] text-muted mt-1"><?= esc($t['subject']) ?></p>
        <?php $pre = (int) ($preselect ?? 0); ?>
        <form method="post" action="<?= site_url('portal/rate/' . $token . '/submit') ?>" class="flex gap-2 mt-5">
          <?= csrf_field() ?>
          <?php foreach ([1 => 'Poor', 2 => 'Fair', 3 => 'OK', 4 => 'Good', 5 => 'Excellent'] as $n => $label): ?>
          <button type="submit" name="score" value="<?= $n ?>" class="flex-1 flex flex-col items-center gap-1.5 py-3 rounded-xl border transition <?= $pre === $n ? 'border-signal-100 bg-signal-50 text-signal-400' : 'border-line text-line hover:border-signal-100 hover:bg-signal-50 hover:text-signal-400' ?>" aria-label="<?= $n ?> out of 5">
            <?= th_icon('star', 'w-6 h-6 fill-current') ?><span class="text-[11.5px] text-muted"><?= $label ?></span></button>
          <?php endforeach ?>
        </form>
        <p class="text-[12px] text-faint mt-4"><?= $pre ? 'Confirm your score — tap it again (or pick another).' : 'One tap records your score.' ?> No sign-in needed.</p>
      </div>

      <?php else: // thanks | rated | commented ?>
      <div class="bg-white border border-brand-100 rounded-xl shadow-card p-6">
        <div class="font-mono text-[12px] text-faint"><?= esc($t['code']) ?></div>
        <h1 class="font-display text-[20px] font-semibold mt-1"><?= $state === 'thanks' ? 'Thanks for the feedback' : ($state === 'commented' ? 'Thank you — that helps us fix it' : 'Already rated') ?></h1>
        <div class="flex items-center gap-3 mt-3">
          <?= $stars((int) $score) ?>
          <span class="text-[13px] text-ink-500"><?= (int) $score ?> out of 5 for “<?= esc($t['subject']) ?>”</span>
        </div>

        <?php if ($canComment): ?>
        <form method="post" action="<?= site_url('portal/rate/' . $token) ?>" class="mt-5 pt-5 border-t border-line">
          <?= csrf_field() ?>
          <label class="block text-[13px] font-medium text-ink-500 mb-1.5"><?= (int) $score <= 3 ? 'What went wrong?' : 'Anything you would like to add?' ?> <span class="text-faint font-normal">(optional)</span></label>
          <textarea name="comment" rows="3" maxlength="2000" placeholder="<?= (int) $score <= 3 ? 'e.g. It took three attempts to get through' : 'e.g. Quick and clearly explained' ?>"
            class="w-full px-3 py-2.5 rounded-lg border border-line text-[13.5px] leading-relaxed placeholder:text-faint focus:border-brand"></textarea>
          <div class="flex items-center gap-2 mt-2">
            <span class="text-[11.5px] text-faint">You can add this once, within seven days.</span>
            <div class="flex-1"></div>
            <button type="submit" class="h-9 px-3.5 rounded-lg bg-ink hover:bg-ink-700 text-white text-[13px] font-semibold">Send</button>
          </div>
        </form>
        <?php elseif (! empty($t['csat_comment'])): ?>
        <div class="mt-4 bg-canvas border border-line rounded-xl p-4">
          <div class="text-[11px] uppercase tracking-[.09em] text-faint mb-1">Your feedback</div>
          <p class="text-[13px] text-ink-500 leading-relaxed"><?= esc($t['csat_comment']) ?></p>
        </div>
        <?php endif ?>
        <a href="<?= site_url('portal/tickets/' . $t['code']) ?>" class="inline-flex items-center mt-5 text-[13px] text-brand font-medium hover:underline">Open the ticket in the portal →</a>
      </div>
      <?php endif ?>
    </div>
  </main>
  <footer class="border-t border-line">
    <div class="max-w-[640px] mx-auto px-5 py-5 text-[12.5px] text-muted">Service desk · ext. 4400 · Open 08:00–18:00 ET, Mon–Fri</div>
  </footer>
</div>
</body>
</html>
