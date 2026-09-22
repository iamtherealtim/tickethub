<?php
/**
 * Link-incidents dialog for a problem. Multi-select, because a problem is
 * usually raised once a cluster of incidents already exists.
 */
$rows = '';
foreach ($results as $r) {
    $t   = $r['t'];
    $req = $users[(int) $t['requester_id']] ?? null;
    $why = $r['why'] ? implode(' · ', array_map('esc', $r['why'])) : 'recent open ticket';
    $strength = $r['score'] >= 55 ? ['Strong match', 'bg-brand-50 text-brand border-brand-100']
        : ($r['score'] >= 32 ? ['Likely', 'bg-signal-50 text-signal border-signal-100']
        : ['Possible', 'bg-canvas text-muted border-line']);

    $rows .= '<label class="flex items-start gap-3 p-3 rounded-lg border border-line hover:border-[#CBD1DC] hover:bg-canvas cursor-pointer transition has-[:checked]:border-brand has-[:checked]:bg-brand-50">'
        . '<input type="checkbox" name="codes[]" value="' . esc($t['code'], 'attr') . '" class="mt-1 w-[15px] h-[15px] rounded border-line shrink-0">'
        . '<span class="min-w-0 flex-1">'
        . '<span class="flex flex-wrap items-center gap-2">'
        . '<span class="font-mono text-[11px] text-faint">' . esc($t['code']) . '</span>'
        . th_status_chip($t['status'])
        . '<span class="inline-flex items-center h-[18px] px-1.5 rounded border text-[10px] font-bold uppercase tracking-wide ' . $strength[1] . '">' . $strength[0] . '</span>'
        . '</span>'
        . '<span class="block text-[13.5px] font-medium text-ink mt-1 truncate">' . esc($t['subject']) . '</span>'
        . '<span class="block text-[11.5px] text-faint mt-1">'
        . esc($req['name'] ?? '—') . ' · ' . esc($t['category']) . ' · updated ' . th_rel($t['updated_at'])
        . ' <span class="text-muted">— ' . $why . '</span></span>'
        . '</span></label>';
}

if (! $rows) {
    $rows = '<div class="py-8 text-center"><p class="text-[13px] text-muted">'
        . ($q !== ''
            ? 'Nothing unlinked matches “' . esc($q) . '”.'
            : 'No similar unlinked incidents found. Search above to pick one manually.')
        . '</p></div>';
}

if (! empty($listOnly)) {
    echo $rows;

    return;
}
?>
<form method="post" action="<?= site_url('app/problems/' . $p['id'] . '/link') ?>"
      data-modal-title="Link incidents to <?= esc($p['code'], 'attr') ?>"
      data-modal-sub="<?= esc(mb_substr($p['title'], 0, 90), 'attr') ?>"
      data-modal-width="max-w-2xl" data-submit="Link selected">
  <?= csrf_field() ?>

  <div class="relative mb-3">
    <span class="absolute left-2.5 top-1/2 -translate-y-1/2 text-faint"><?= th_icon('search', 'w-4 h-4') ?></span>
    <input type="search" value="<?= esc($q, 'attr') ?>" autocomplete="off"
           data-live-search="<?= site_url('app/problems/' . $p['id'] . '/link-search') ?>"
           placeholder="Search unlinked tickets by code, subject, requester or wording…"
           class="w-full h-9 pl-8 pr-3 rounded-lg border border-line bg-white text-[13px] placeholder:text-faint focus:border-brand">
  </div>

  <p data-live-caption class="text-[11.5px] text-faint mb-2"
     data-suggest="Suggested — incidents matching this problem and the ones already linked"
     data-search="Search results">
    <?= $q !== '' ? 'Search results' : 'Suggested — incidents matching this problem and the ones already linked' ?>
  </p>

  <div data-live-results class="space-y-2 max-h-[380px] overflow-y-auto -mx-1 px-1"><?= $rows ?></div>

  <p class="text-[11.5px] text-faint mt-3">Tick as many as apply — only tickets not already attached to a problem are listed.</p>
</form>
