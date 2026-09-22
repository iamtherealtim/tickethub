<?php
/**
 * Quick-look asset preview. Deliberately a modal: it opens from the ticket
 * sidebar, where navigating away would cost the agent a half-typed reply.
 * The full record lives at /app/assets/{id} — see the footer link.
 * Field sets come from th_asset_*() so this and agent/asset.php cannot drift.
 */
$holder    = $a['user_id'] ? ($users[(int) $a['user_id']] ?? null) : null;
$statusCls = TH_ASSET_STATUS[$a['status']] ?? TH_ASSET_STATUS['In stock'];

$section = static function (string $title, string $inner, string $right = ''): string {
    return '<div class="mt-4 first:mt-0">'
        . '<div class="flex items-center justify-between mb-2">'
        . '<h3 class="text-[11px] font-semibold uppercase tracking-[.09em] text-faint">' . esc($title) . '</h3>'
        . $right . '</div>' . $inner . '</div>';
};

$footer = '<form method="post" action="' . site_url('app/assets/' . $a['id'] . '/delete') . '"'
    . ' data-confirm="' . esc('Delete ' . $a['tag'] . '? Ticket links to it are removed too.', 'attr') . '"'
    . ' data-confirm-label="Delete" data-confirm-title="Delete this asset?" class="mr-auto">'
    . '<input type="hidden" name="' . csrf_token() . '" value="' . csrf_hash() . '">'
    . '<button type="submit" class="h-9 px-3 rounded-lg border border-alert-100 bg-white text-[13px] font-medium text-alert hover:bg-alert-50">Delete</button></form>'
    . '<a href="' . site_url('app/assets/' . $a['id']) . '" class="inline-flex items-center gap-1.5 h-9 px-3.5 rounded-lg border border-line bg-white text-[13px] font-medium text-ink-500 hover:bg-canvas">'
    . th_icon('ext', 'w-3.5 h-3.5') . 'Open record</a>'
    . '<button type="button" data-fetch-modal="' . site_url('app/assets/' . $a['id'] . '/edit') . '" class="h-9 px-3.5 rounded-lg border border-line bg-white text-[13px] font-medium text-ink-500 hover:bg-canvas">Edit</button>'
    . '<button type="button" data-close-modal class="h-9 px-3.5 rounded-lg border border-line bg-white text-[13px] font-medium text-ink-500 hover:bg-canvas">Close</button>'
    . '<form method="post" action="' . site_url('app/assets/' . $a['id'] . '/ticket') . '">'
    . '<input type="hidden" name="' . csrf_token() . '" value="' . csrf_hash() . '">'
    . '<button type="submit" class="h-9 px-3.5 rounded-lg bg-ink hover:bg-ink-700 text-white text-[13px] font-semibold whitespace-nowrap">Raise a ticket</button></form>';
?>
<div data-modal-title="<?= esc($a['name'], 'attr') ?>"
     data-modal-sub="<?= esc($a['tag'] . ' · ' . $a['model'], 'attr') ?>"
     data-modal-width="max-w-lg"
     data-modal-footer="<?= esc($footer, 'attr') ?>">

  <div class="flex flex-wrap items-center gap-2 pb-3 border-b border-line">
    <span class="w-9 h-9 rounded-lg bg-canvas border border-line grid place-items-center text-muted shrink-0">
      <?= th_icon(TH_ASSET_ICON[$a['type']] ?? 'server', 'w-[18px] h-[18px]') ?></span>
    <span class="inline-flex items-center h-[22px] px-2 rounded-md border text-[11px] font-semibold <?= $statusCls ?>"><?= esc($a['status']) ?></span>
    <span class="text-[12.5px] text-muted"><?= esc($a['type']) ?></span>
    <?php if (! empty($a['pdq_device_id'])): ?>
      <span class="inline-flex items-center h-[18px] px-1.5 rounded bg-violet-50 text-violet text-[9.5px] font-bold uppercase tracking-wide" title="Synced from PDQ Connect">PDQ</span>
    <?php endif ?>
  </div>

  <?php
    $reassign = '<button type="button" data-fetch-modal="' . site_url('app/assets/' . $a['id'] . '/assign-search') . '"'
        . ' class="text-[12px] text-brand font-medium hover:underline">' . ($holder ? 'Reassign' : 'Assign to someone') . '</button>';

    echo $section('Identity', th_asset_dl(th_asset_identity($a)));
    echo $section('Assignment', th_asset_dl(th_asset_assignment($a, $holder)), $reassign);
    echo $section('Warranty', th_asset_dl(th_asset_warranty($a)));

    if (! empty($a['pdq_device_id'])) {
        echo $section('PDQ Connect', th_asset_dl(th_asset_pdq($a)));
    }
  ?>

  <div class="mt-4 pt-4 border-t border-line">
    <h3 class="text-[11px] font-semibold uppercase tracking-[.09em] text-faint mb-2">
      Linked tickets<?= $related ? ' · ' . count($related) : '' ?>
    </h3>
    <?php if ($related): ?>
      <?php foreach ($related as $t): ?>
      <a href="<?= site_url('app/tickets/' . $t['code']) ?>" class="w-full flex items-center gap-2 py-1.5 text-left hover:text-brand">
        <i class="led <?= TH_PRIORITY[$t['priority']]['dot'] ?? 'bg-[#98A1B0]' ?>"></i>
        <span class="font-mono text-[11.5px] text-faint shrink-0"><?= esc($t['code']) ?></span>
        <span class="text-[13px] truncate flex-1"><?= esc($t['subject']) ?></span>
        <?= th_status_chip($t['status']) ?>
      </a>
      <?php endforeach ?>
    <?php else: ?>
      <p class="text-[13px] text-muted">No tickets reference this asset.</p>
    <?php endif ?>
  </div>
</div>
