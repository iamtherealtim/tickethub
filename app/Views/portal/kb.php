<?= $this->extend('layouts/portal') ?>
<?= $this->section('content') ?>

<?php
$cats = array_merge(['All'], array_values(array_unique(array_column($articles, 'category'))));
$list = array_values(array_filter($articles, static fn ($a) => $cat === 'All' || $a['category'] === $cat));
?>
<div class="max-w-[1120px] mx-auto px-5 py-8 fade-in">
  <h1 class="font-display text-[24px] font-semibold">Knowledge base</h1>
  <p class="text-[13px] text-muted mt-1 mb-5">Step-by-step answers, written by the people who fix these things.</p>
  <div class="flex flex-wrap gap-1.5 mb-4">
    <?php foreach ($cats as $c): $on = $cat === $c; ?>
    <a href="<?= site_url('portal/kb') . ($c === 'All' ? '' : '?cat=' . urlencode($c)) ?>" class="h-8 px-3 rounded-lg text-[12.5px] font-medium border transition inline-flex items-center
      <?= $on ? 'bg-ink text-white border-ink' : 'bg-white text-muted border-line hover:text-ink' ?>"><?= esc($c) ?></a>
    <?php endforeach ?>
  </div>
  <?php if (! $list): ?>
    <?= th_card(th_empty('book', $articles ? 'Nothing in this category' : 'No articles yet', $articles ? 'Pick another category above.' : 'The service desk has not published any answers yet — raise a ticket and we will help.', '<a href="' . site_url('portal/new') . '" class="inline-flex items-center h-9 px-4 rounded-lg bg-brand text-white text-[13px] font-semibold hover:bg-brand-600">Raise a ticket</a>')) ?>
  <?php endif ?>
  <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
    <?php foreach ($list as $a): ?>
    <a href="<?= site_url('portal/kb/' . $a['id']) ?>" class="text-left bg-white border border-line rounded-xl shadow-card p-4 hover:border-brand-100 transition block">
      <span class="text-[11px] font-semibold uppercase tracking-[.09em] text-faint"><?= esc($a['category']) ?></span>
      <h3 class="font-display text-[15px] font-semibold mt-1.5 leading-snug"><?= esc($a['title']) ?></h3>
      <p class="font-mono text-[11.5px] text-faint mt-2"><?= number_format((int) $a['views']) ?> views · <?= (int) $a['up_votes'] ?> helpful</p></a>
    <?php endforeach ?>
  </div>
</div>
<?= $this->endSection() ?>
