<?php
/**
 * Merge dialog. Rendered whole on open, and re-rendered (list only) on each
 * keystroke so search and suggestions share exactly one code path.
 */
$rows = '';
foreach ($results as $r) {
    $t   = $r['t'];
    $req = $users[(int) $t['requester_id']] ?? null;
    $why = $r['why'] ? implode(' · ', array_map('esc', $r['why'])) : 'related';
    $strength = $r['score'] >= 60 ? ['Strong match', 'bg-brand-50 text-brand border-brand-100']
        : ($r['score'] >= 35 ? ['Likely', 'bg-signal-50 text-signal border-signal-100']
        : ['Possible', 'bg-canvas text-muted border-line']);

    $rows .= '<label class="flex items-start gap-3 p-3 rounded-lg border border-line hover:border-[#CBD1DC] hover:bg-canvas cursor-pointer transition has-checked:border-brand has-checked:bg-brand-50">'
        . '<input type="radio" name="target" value="' . esc($t['code'], 'attr') . '" class="mt-1 w-[15px] h-[15px] shrink-0" required>'
        . '<span class="min-w-0 flex-1">'
        . '<span class="flex flex-wrap items-center gap-2">'
        . '<span class="font-mono text-[11px] text-faint">' . esc($t['code']) . '</span>'
        . th_status_chip($t['status'])
        . '<span class="inline-flex items-center h-[18px] px-1.5 rounded-sm border text-[10px] font-bold uppercase tracking-wide ' . $strength[1] . '">' . $strength[0] . '</span>'
        . '</span>'
        . '<span class="block text-[13.5px] font-medium text-ink mt-1 truncate">' . esc($t['subject']) . '</span>'
        . '<span class="block text-[11.5px] text-faint mt-1">'
        . esc($req['name'] ?? '—') . ' · ' . esc($t['category']) . ' · updated ' . th_rel($t['updated_at'])
        . ' <span class="text-muted">— ' . $why . '</span></span>'
        . '</span></label>';
}

if (! $rows) {
    $rows = '<div class="py-8 text-center">'
        . '<p class="text-[13px] text-muted">' . ($q !== ''
            ? 'Nothing matches “' . esc($q) . '”.'
            : 'No similar tickets found. Search above to pick one manually.') . '</p></div>';
}

if (! empty($listOnly)) {
    echo $rows;

    return;
}
?>
<form method="post" action="<?= site_url('app/tickets/' . $src['code'] . '/merge') ?>"
      data-modal-title="Merge <?= esc($src['code'], 'attr') ?>"
      data-modal-sub="Its conversation and tasks move into the ticket you choose, then <?= esc($src['code'], 'attr') ?> is deleted"
      data-modal-width="max-w-2xl" data-submit="Merge tickets">
  <?= csrf_field() ?>

  <div class="relative mb-3">
    <span class="absolute left-2.5 top-1/2 -translate-y-1/2 text-faint"><?= th_icon('search', 'w-4 h-4') ?></span>
    <input type="search" value="<?= esc($q, 'attr') ?>" autocomplete="off"
           data-live-search="<?= site_url('app/tickets/' . $src['code'] . '/merge-search') ?>"
           placeholder="Search by ticket code, subject, requester or wording…"
           class="w-full h-9 pl-8 pr-3 rounded-lg border border-line bg-white text-[13px] placeholder:text-faint focus:border-brand">
  </div>

  <p data-live-caption class="text-[11.5px] text-faint mb-2"
     data-suggest="Suggested — same requester first, then tickets about the same thing"
     data-search="Search results">
    <?= $q !== '' ? 'Search results' : 'Suggested — same requester first, then tickets about the same thing' ?>
  </p>

  <div data-live-results class="space-y-2 max-h-[380px] overflow-y-auto -mx-1 px-1"><?= $rows ?></div>

  <p class="text-[11.5px] text-faint mt-3 flex items-start gap-1.5">
    <?= th_icon('warn', 'w-3.5 h-3.5 mt-0.5 shrink-0') ?>
    This cannot be undone. Attachments stay with their messages; linked assets on <?= esc($src['code']) ?> are dropped.
  </p>
</form>
