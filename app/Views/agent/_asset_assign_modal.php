<?php
/**
 * Assign-holder picker. Searchable across name, email, department, site and
 * title, and it lists everyone active — agents hold equipment too.
 */
$rows = '';
$currentId = (int) ($a['user_id'] ?? 0);

foreach ($results as $r) {
    $u       = $r['u'];
    $isNow   = (int) $u['id'] === $currentId;
    $why     = $r['why'] ? implode(' · ', array_map('esc', $r['why'])) : '';
    $isAgent = in_array($u['role'], ['Administrator', 'Supervisor', 'Agent'], true);

    $rows .= '<label class="flex items-center gap-3 p-2.5 rounded-lg border ' . ($isNow ? 'border-brand bg-brand-50' : 'border-line') . ' hover:border-[#CBD1DC] hover:bg-canvas cursor-pointer transition has-[:checked]:border-brand has-[:checked]:bg-brand-50">'
        . '<input type="radio" name="user_id" value="' . (int) $u['id'] . '" ' . ($isNow ? 'checked' : '') . ' class="w-[15px] h-[15px] shrink-0">'
        . th_avatar($u, 30)
        . '<span class="min-w-0 flex-1">'
        . '<span class="flex items-center gap-2">'
        . '<span class="text-[13.5px] font-medium text-ink truncate">' . esc($u['name']) . '</span>'
        . ($isAgent ? '<span class="inline-flex items-center h-[16px] px-1 rounded bg-canvas border border-line text-[9.5px] font-bold uppercase tracking-wide text-muted">' . esc($u['role']) . '</span>' : '')
        . '</span>'
        . '<span class="block text-[11.5px] text-faint truncate">'
        . esc(implode(' · ', array_filter([$u['title'] ?? '', $u['dept'] ?? '', $u['site'] ?? ''])))
        . ($why ? ' <span class="text-muted">— ' . $why . '</span>' : '')
        . '</span></span>'
        . '<span class="text-[11px] text-faint font-mono shrink-0 text-right">' . (int) $r['held'] . ' asset' . ((int) $r['held'] === 1 ? '' : 's') . '</span>'
        . '</label>';
}

if (! $rows) {
    $rows = '<p class="py-8 text-center text-[13px] text-muted">Nobody matches &ldquo;' . esc($q) . '&rdquo;.</p>';
}

if (! empty($listOnly)) {
    echo $rows;

    return;
}
?>
<form method="post" action="<?= site_url('app/assets/' . $a['id'] . '/assign') ?>"
      data-modal-title="Assign <?= esc($a['tag'], 'attr') ?>"
      data-modal-sub="<?= esc($a['name'] . ' · ' . $a['model'], 'attr') ?>"
      data-modal-width="max-w-lg" data-submit="Assign asset">
  <?= csrf_field() ?>

  <div class="relative mb-3">
    <span class="absolute left-2.5 top-1/2 -translate-y-1/2 text-faint"><?= th_icon('search', 'w-4 h-4') ?></span>
    <input type="search" value="<?= esc($q, 'attr') ?>" autocomplete="off"
           data-live-search="<?= site_url('app/assets/' . $a['id'] . '/assign-search') ?>"
           placeholder="Search by name, email, department or site…"
           class="w-full h-9 pl-8 pr-3 rounded-lg border border-line bg-white text-[13px] placeholder:text-faint focus:border-brand">
  </div>

  <label class="flex items-center gap-3 p-2.5 mb-2 rounded-lg border border-line hover:bg-canvas cursor-pointer transition has-[:checked]:border-brand has-[:checked]:bg-brand-50">
    <input type="radio" name="user_id" value="" <?= $currentId === 0 ? 'checked' : '' ?> class="w-[15px] h-[15px] shrink-0">
    <span class="w-[30px] h-[30px] rounded-full border border-line bg-canvas grid place-items-center text-faint shrink-0"><?= th_icon('server', 'w-4 h-4') ?></span>
    <span class="min-w-0 flex-1">
      <span class="block text-[13.5px] font-medium text-ink">Nobody — return to stock</span>
      <span class="block text-[11.5px] text-faint">Clears the holder<?= $a['status'] === 'In use' ? ' and sets the status to In stock' : '' ?></span>
    </span>
  </label>

  <p data-live-caption class="text-[11.5px] text-faint mb-2"
     data-suggest="Current holder first, then people at the same site"
     data-search="Search results">
    <?= $q !== '' ? 'Search results' : 'Current holder first, then people at the same site' ?>
  </p>

  <div data-live-results class="space-y-1.5 max-h-[340px] overflow-y-auto -mx-1 px-1"><?= $rows ?></div>
</form>
