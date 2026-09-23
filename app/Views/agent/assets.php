<?= $this->extend('layouts/agent') ?>
<?= $this->section('content') ?>

<div class="p-5 max-w-[1400px] mx-auto fade-in">
  <div class="flex flex-wrap items-end justify-between gap-3 mb-5">
    <div>
      <h1 class="font-display text-[22px] font-semibold text-ink">Assets</h1>
      <p class="text-[13px] text-muted mt-1">Everything the service desk is accountable for, and who holds it</p>
    </div>
    <div class="flex items-center gap-2">
      <?php if ($pdqConfigured && $isAdmin): ?>
      <form method="post" action="<?= site_url('app/admin/pdq/sync') ?>" class="flex items-center gap-2">
        <?= csrf_field() ?>
        <?php if ($pdqLastSync): ?><span class="text-[11.5px] <?= ! empty($pdqLastError) ? 'text-alert' : 'text-faint' ?> hidden md:block" title="<?= esc($pdqLastError ?? '', 'attr') ?>">PDQ synced <?= th_rel($pdqLastSync) ?><?= ! empty($pdqLastError) ? ' · last run failed' : '' ?></span><?php endif ?>
        <?= th_btn('Sync from PDQ', 'type="submit"', 'ghost', 'refresh') ?>
      </form>
      <?php endif ?>
      <?php if (! empty($canManage)): ?><?= th_btn('Import', 'data-modal="importAssets"', 'ghost', 'clip') ?><?php endif ?>
      <?= th_btn('Add asset', 'data-modal="addAsset"', 'brand', 'plus') ?>
    </div>
  </div>

  <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
    <?php $siteCount = count(array_filter(array_unique(array_column($assets, 'site')), static fn ($s) => $s !== '' && $s !== '—')); ?>
    <?= th_kpi('Total assets', count($assets), 'Across ' . $siteCount . ' site' . ($siteCount === 1 ? '' : 's')) ?>
    <?= th_kpi('In use', count(array_filter($assets, static fn ($a) => $a['status'] === 'In use')), 'Assigned or deployed', 'brand') ?>
    <?= th_kpi('Needs repair', count(array_filter($assets, static fn ($a) => $a['status'] === 'Needs repair')), 'Blocking someone right now', 'alert') ?>
    <?= th_kpi('Warranty <90d', $expiring, 'Plan replacement or renewal', 'signal') ?>
  </div>

  <section class="bg-white border border-line rounded-xl shadow-card">
    <form method="get" action="<?= site_url('app/assets') ?>" class="flex flex-wrap items-center gap-2 p-3 border-b border-line">
      <div class="relative flex-1 min-w-[200px]">
        <span class="absolute left-2.5 top-1/2 -translate-y-1/2 text-faint"><?= th_icon('search', 'w-4 h-4') ?></span>
        <input name="q" value="<?= esc($q ?? '', 'attr') ?>" placeholder="Search name, model, serial or holder"
          class="w-full h-8 pl-8 pr-3 rounded-lg border border-line text-[13px] placeholder:text-faint">
      </div>
      <?= th_select('type', $type, array_merge([['', 'Any type']], array_map(static fn ($t) => [$t, $t], $types)), 'data-autosubmit') ?>
    </form>
    <div class="hidden md:flex items-center gap-3 px-4 h-9 bg-canvas border-b border-line text-[11px] font-semibold uppercase tracking-[.09em] text-faint">
      <span class="flex-1">Asset</span><span class="w-[90px]">Type</span><span class="w-[150px]">Assigned to</span>
      <span class="w-[110px]">Site</span><span class="w-[110px]">Status</span><span class="w-[110px] text-right">Warranty</span>
    </div>
    <div>
      <?php if (! $list): ?>
        <?= th_empty('server', 'No assets match', 'Adjust the search or type filter.') ?>
      <?php endif ?>
      <?php foreach ($list as $a):
        $wts  = $a['warranty_until'] ? strtotime($a['warranty_until']) : null;
        $exp  = $wts !== null && $wts < time();
        $soon = $wts !== null && ! $exp && $wts < time() + 90 * 86400;
        $holder = $a['user_id'] ? ($users[(int) $a['user_id']] ?? null) : null;
      ?>
      <button data-fetch-modal="<?= site_url('app/assets/' . $a['id'] . '/modal') ?>" class="w-full flex flex-wrap md:flex-nowrap items-center gap-3 px-4 py-2.5 border-b border-line last:border-0 row-hover text-left">
        <span class="flex items-center gap-2.5 flex-1 min-w-0">
          <span class="w-8 h-8 rounded-lg bg-canvas border border-line grid place-items-center text-muted shrink-0"><?= th_icon(TH_ASSET_ICON[$a['type']] ?? 'server', 'w-4 h-4') ?></span>
          <span class="min-w-0"><span class="flex items-center gap-1.5 text-[13px] font-medium text-ink"><span class="truncate"><?= esc($a['name']) ?></span>
            <?php if (! empty($a['pdq_device_id'])): ?><span class="inline-flex items-center h-[16px] px-1 rounded-sm bg-violet-50 text-violet text-[9.5px] font-bold uppercase tracking-wide shrink-0" title="Synced from PDQ Connect">PDQ</span><?php endif ?></span>
          <span class="block font-mono text-[11px] text-faint truncate"><?= esc($a['tag']) ?> · <?= esc($a['serial']) ?></span></span>
        </span>
        <span class="w-[90px] text-[12.5px] text-muted"><?= esc($a['type']) ?></span>
        <span class="w-[150px] flex items-center gap-1.5">
          <?php if ($holder): ?><?= th_avatar($holder, 22) ?><span class="text-[12.5px] text-ink-500 truncate"><?= esc($holder['name']) ?></span>
          <?php else: ?><span class="text-[12.5px] text-faint italic">Unassigned</span><?php endif ?>
        </span>
        <span class="w-[110px] text-[12.5px] text-muted truncate"><?= esc($a['site']) ?></span>
        <span class="w-[110px]"><span class="inline-flex items-center h-[22px] px-2 rounded-md border text-[11px] font-semibold <?= TH_ASSET_STATUS[$a['status']] ?? TH_ASSET_STATUS['In stock'] ?>"><?= esc($a['status']) ?></span></span>
        <span class="w-[110px] text-right font-mono text-[11.5px] <?= $exp ? 'text-alert' : ($soon ? 'text-signal' : 'text-muted') ?>"><?= $exp ? 'Expired' : th_day($a['warranty_until']) ?></span>
      </button>
      <?php endforeach ?>
    </div>
    <?= th_pager($total, $page, $perPage, site_url('app/assets'), array_filter(['q' => $q, 'type' => $type])) ?>
  </section>
</div>
<?php if (! empty($importOpen) && ! empty($canManage)): ?>
<script>
  // /app/assets/import is the list with the import dialog already open.
  document.addEventListener('DOMContentLoaded', function () {
    var b = document.querySelector('[data-modal="importAssets"]');
    if (b) b.click();
  });
</script>
<?php endif ?>
<?= $this->endSection() ?>

<?= $this->section('modals') ?>
<?php if (! empty($canManage)): ?>
<template id="tpl-importAssets">
  <form method="post" action="<?= site_url('app/assets/import') ?>" enctype="multipart/form-data" data-modal-title="Import assets from CSV" data-modal-sub="Existing assets are matched on serial, then on name, and updated; the rest are created." data-submit="Import">
    <?= csrf_field() ?>
    <label class="block text-[12px] font-medium text-ink-500 mb-1.5">CSV file<span class="text-alert"> *</span></label>
    <input type="file" name="csv" accept=".csv,text/csv" required class="w-full text-[12.5px] text-muted file:mr-3 file:h-8 file:px-3 file:rounded-lg file:border file:border-line file:bg-white file:text-[12.5px] file:font-medium file:text-ink-500 file:cursor-pointer">
    <div class="mt-3 rounded-lg bg-canvas border border-line p-3 text-[12px] text-muted leading-relaxed">
      <div class="font-semibold text-ink-500 mb-1">Header row</div>
      <code class="font-mono text-[11.5px]">name,type,model,serial,status,site,holder_email</code>
      <p class="mt-1.5">Only <code class="font-mono">name</code> is required. Type: Laptop, Mobile, Printer, Server, Switch, Dock or License. Status: <?= esc(implode(', ', array_keys(TH_ASSET_STATUS))) ?>. The holder must be an existing active user's email.</p>
    </div>
  </form>
</template>
<?php endif ?>
<template id="tpl-addAsset">
  <form method="post" action="<?= site_url('app/assets') ?>" data-modal-title="Add asset" data-submit="Add asset">
    <?= csrf_field() ?>
    <div class="grid sm:grid-cols-2 gap-3.5">
      <div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Asset name<span class="text-alert"> *</span></label>
        <input name="name" required class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Type</label>
        <select name="type" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px]">
          <option>Laptop</option><option>Mobile</option><option>Printer</option><option>Server</option><option>Switch</option><option>Dock</option><option>License</option>
        </select></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Model</label>
        <input name="model" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Serial number</label>
        <input name="serial" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Site</label>
        <select name="site" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px]">
          <?php foreach (TH_SITES as $s): ?><option><?= $s ?></option><?php endforeach ?>
        </select></div>
      <div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Assign to</label>
        <select name="user_id" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px]">
          <option value="">In stock</option>
          <?php foreach ($assignablePeople as $u): ?><option value="<?= $u['id'] ?>"><?= esc($u['name']) ?><?= $u['dept'] ? ' — ' . esc($u['dept']) : '' ?></option><?php endforeach ?>
        </select>
        <p class="text-[11.5px] text-faint mt-1">You can also assign later from the asset, with search.</p></div>
    </div>
  </form>
</template>
<?= $this->endSection() ?>
