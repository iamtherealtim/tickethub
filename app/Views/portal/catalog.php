<?= $this->extend('layouts/portal') ?>
<?= $this->section('content') ?>

<div class="max-w-[1120px] mx-auto px-5 py-8 fade-in">
  <h1 class="font-display text-[24px] font-semibold"><?= lang('Portal.catalog.title') ?></h1>
  <p class="text-[13px] text-muted mt-1 mb-5"><?= lang('Portal.catalog.intro') ?></p>
  <?= view('partials/catalog_grid', [
      'items' => $items, 'cat' => $cat,
      'baseUrl' => site_url('portal/catalog'),
      'requestUrlFn' => static fn (int $id) => site_url('portal/catalog/' . $id . '/request'),
      'onBehalf' => false, 'requesters' => [],
  ]) ?>
</div>
<?= $this->endSection() ?>
