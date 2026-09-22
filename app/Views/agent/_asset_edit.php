<form method="post" action="<?= site_url('app/assets/' . $a['id']) ?>" data-modal-title="Edit <?= esc($a['name'], 'attr') ?>" data-modal-sub="<?= esc($a['tag'], 'attr') ?>" data-modal-width="max-w-lg" data-submit="Save asset">
  <?= csrf_field() ?>
  <div class="grid sm:grid-cols-2 gap-3.5">
    <div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Asset name<span class="text-alert"> *</span></label>
      <input name="name" required value="<?= esc($a['name'], 'attr') ?>" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
    <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Type</label>
      <select name="type" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px]">
        <?php foreach (array_keys(TH_ASSET_ICON) as $t): ?><option <?= $a['type'] === $t ? 'selected' : '' ?>><?= $t ?></option><?php endforeach ?>
      </select></div>
    <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Status</label>
      <select name="status" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px]">
        <?php foreach (array_keys(TH_ASSET_STATUS) as $s): ?><option <?= $a['status'] === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach ?>
      </select></div>
    <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Model</label>
      <input name="model" value="<?= esc($a['model'], 'attr') ?>" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
    <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Serial number</label>
      <input name="serial" value="<?= esc($a['serial'], 'attr') ?>" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] font-mono focus:border-brand"></div>
    <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Site</label>
      <select name="site" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px]">
        <?php foreach (array_unique(array_merge(TH_SITES, ['—', $a['site']])) as $s): ?><option <?= $a['site'] === $s ? 'selected' : '' ?>><?= esc($s) ?></option><?php endforeach ?>
      </select></div>
    <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Operating system</label>
      <input name="os" value="<?= esc($a['os'], 'attr') ?>" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
    <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Assigned to</label>
      <select name="user_id" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px]">
        <option value="">In stock / nobody</option>
        <?php foreach ($people as $u): ?><option value="<?= $u['id'] ?>" <?= (int) $a['user_id'] === (int) $u['id'] ? 'selected' : '' ?>><?= esc($u['name']) ?><?= $u['dept'] ? ' — ' . esc($u['dept']) : '' ?></option><?php endforeach ?>
      </select>
      <p class="text-[11.5px] text-faint mt-1">Or use <span class="text-ink-500">Reassign</span> on the asset for a searchable picker.</p></div>
    <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Warranty until</label>
      <input name="warranty_until" type="date" value="<?= $a['warranty_until'] ? date('Y-m-d', strtotime($a['warranty_until'])) : '' ?>" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] font-mono focus:border-brand"></div>
  </div>
</form>
