<?= $this->extend('layouts/agent') ?>
<?= $this->section('content') ?>

<?php
  $holder    = $a['user_id'] ? ($users[(int) $a['user_id']] ?? null) : null;
  $statusCls = TH_ASSET_STATUS[$a['status']] ?? TH_ASSET_STATUS['In stock'];
  $assignUrl = site_url('app/assets/' . $a['id'] . '/assign-search');
?>
<div class="p-5 max-w-[1100px] mx-auto fade-in">
  <a href="<?= site_url('app/assets') ?>" class="inline-flex items-center gap-1 text-[12px] text-muted hover:text-ink mb-3"><?= th_icon('back', 'w-3.5 h-3.5') ?> Assets</a>

  <div class="bg-white border border-line rounded-xl shadow-card p-5 mb-4">
    <div class="flex flex-wrap items-center gap-2">
      <span class="font-mono text-[11.5px] text-faint"><?= esc($a['tag']) ?></span>
      <span class="inline-flex items-center h-[20px] px-2 rounded border text-[11px] font-semibold <?= $statusCls ?>"><?= esc($a['status']) ?></span>
      <span class="text-[12px] text-muted"><?= esc($a['type']) ?></span>
      <?php if (! empty($a['pdq_device_id'])): ?>
        <span class="inline-flex items-center h-[18px] px-1.5 rounded bg-violet-50 text-violet text-[9.5px] font-bold uppercase tracking-wide" title="Synced from PDQ Connect">PDQ</span>
      <?php endif ?>
      <span class="ml-auto flex items-center gap-2">
        <?= th_btn('Edit', 'data-fetch-modal="' . site_url('app/assets/' . $a['id'] . '/edit') . '"', 'ghost', 'edit') ?>
        <form method="post" action="<?= site_url('app/assets/' . $a['id'] . '/ticket') ?>">
          <?= csrf_field() ?><?= th_btn('Raise a ticket', 'type="submit"', 'solid', 'plus') ?>
        </form>
        <?php if (! empty($canManage)): ?>
        <form method="post" action="<?= site_url('app/assets/' . $a['id'] . '/delete') ?>"
              data-confirm="Delete <?= esc($a['tag'], 'attr') ?>? Ticket links to it are removed too."
              data-confirm-label="Delete" data-confirm-title="Delete this asset?">
          <?= csrf_field() ?><?= th_btn('Delete', 'type="submit"', 'danger', 'trash') ?>
        </form>
        <?php endif ?>
      </span>
    </div>

    <div class="flex items-center gap-3 mt-3">
      <span class="w-11 h-11 rounded-xl bg-canvas border border-line grid place-items-center text-muted shrink-0">
        <?= th_icon(TH_ASSET_ICON[$a['type']] ?? 'server', 'w-5 h-5') ?></span>
      <div class="min-w-0">
        <h1 class="font-display text-[22px] font-semibold leading-snug truncate"><?= esc($a['name']) ?></h1>
        <p class="font-mono text-[12px] text-faint mt-0.5"><?= esc($a['model']) ?> · <?= esc($a['serial']) ?></p>
      </div>
    </div>
  </div>

  <div class="grid lg:grid-cols-3 gap-4 mb-4">
    <?= th_card(th_card_head('Identity') . '<div class="p-4">' . th_asset_dl(th_asset_identity($a)) . '</div>') ?>

    <?php
      $reassign = th_btn($holder ? 'Reassign' : 'Assign', 'data-fetch-modal="' . $assignUrl . '"', 'brand', 'user');
      echo th_card(th_card_head('Assignment', $reassign) . '<div class="p-4">' . th_asset_dl(th_asset_assignment($a, $holder)) . '</div>');

      echo th_card(th_card_head('Warranty') . '<div class="p-4">' . th_asset_dl(th_asset_warranty($a), 'grid-cols-2 lg:grid-cols-1') . '</div>');
    ?>
  </div>

  <?php if (! empty($a['pdq_device_id'])): ?>
    <div class="mb-4"><?= th_card(th_card_head('PDQ Connect') . '<div class="p-4">' . th_asset_dl(th_asset_pdq($a), 'grid-cols-2 sm:grid-cols-4') . '</div>') ?></div>
  <?php endif ?>

  <?php
    $rows = '';
    foreach ($related as $t) {
        $rows .= '<a href="' . site_url('app/tickets/' . $t['code']) . '" class="flex items-center gap-3 px-4 py-2.5 border-b border-line last:border-0 row-hover">'
            . '<i class="led ' . (TH_PRIORITY[$t['priority']]['dot'] ?? 'bg-[#98A1B0]') . '"></i>'
            . '<span class="font-mono text-[11px] text-faint w-[70px]">' . esc($t['code']) . '</span>'
            . '<span class="text-[13px] text-ink truncate flex-1">' . esc($t['subject']) . '</span>'
            . th_status_chip($t['status']) . '</a>';
    }
    if (! $rows) {
        $rows = th_empty('inbox', 'No tickets reference this asset', 'Raise one from here if something is wrong with it.');
    }
    echo th_card(th_card_head('Linked tickets · ' . count($related)) . '<div>' . $rows . '</div>');
  ?>
</div>
<?= $this->endSection() ?>
