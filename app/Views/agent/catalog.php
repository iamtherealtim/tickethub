<?= $this->extend('layouts/agent') ?>
<?= $this->section('content') ?>

<div class="p-5 max-w-[1400px] mx-auto fade-in">
  <div class="flex flex-wrap items-end justify-between gap-3 mb-5">
    <div>
      <h1 class="font-display text-[22px] font-semibold text-ink">Service catalog</h1>
      <p class="text-[13px] text-muted mt-1">What people can ask for, and what we promise in return</p>
    </div>
    <?= th_btn('Add item', 'data-modal="addCatalog"', 'brand', 'plus') ?>
  </div>
  <?= view('partials/catalog_grid', [
      'items' => $items, 'cat' => $cat,
      'baseUrl' => site_url('app/catalog'),
      'requestUrlFn' => static fn (int $id) => site_url('app/catalog/' . $id . '/request'),
      'onBehalf' => true, 'requesters' => $requesters, 'editable' => true,
  ]) ?>
</div>
<?= $this->endSection() ?>
