<?= $this->extend('layouts/agent') ?>
<?= $this->section('content') ?>

<?php
$cats = array_merge(['All'], array_values(array_unique(array_column($articles, 'category'))));
$list = array_values(array_filter($articles, static fn ($a) => $cat === 'All' || $a['category'] === $cat));
?>
<div class="p-5 max-w-[1400px] mx-auto fade-in">
  <div class="flex flex-wrap items-end justify-between gap-3 mb-5">
    <div>
      <h1 class="font-display text-[22px] font-semibold text-ink">Knowledge</h1>
      <p class="text-[13px] text-muted mt-1">Answers that stop tickets being raised twice</p>
    </div>
    <?= th_btn('New article', 'data-modal="newArticle"', 'brand', 'plus') ?>
  </div>

  <div class="grid lg:grid-cols-[190px_1fr] gap-4 items-start">
    <section class="bg-white border border-line rounded-xl shadow-card">
      <div class="p-2">
        <?php foreach ($cats as $c): $on = $cat === $c;
          $n = $c === 'All' ? count($articles) : count(array_filter($articles, static fn ($a) => $a['category'] === $c)); ?>
        <a href="<?= site_url('app/kb') . ($c === 'All' ? '' : '?cat=' . urlencode($c)) ?>" class="flex items-center justify-between w-full text-left h-8 px-2.5 rounded-lg text-[12.5px] font-medium transition
          <?= $on ? 'bg-ink text-white' : 'text-muted hover:bg-canvas hover:text-ink' ?>">
          <?= esc($c) ?><span class="font-mono text-[11px] <?= $on ? 'text-white/60' : 'text-faint' ?>"><?= $n ?></span></a>
        <?php endforeach ?>
      </div>
    </section>
    <div class="grid md:grid-cols-2 gap-3">
      <?php foreach ($list as $a): ?>
      <a href="<?= site_url('app/kb/' . $a['id']) ?>" class="text-left bg-white border border-line rounded-xl shadow-card p-4 hover:border-[#CBD1DC] transition">
        <div class="flex items-center gap-2 mb-2">
          <span class="text-[11px] font-semibold uppercase tracking-[.09em] text-faint"><?= esc($a['category']) ?></span>
          <?php if ($a['status'] === 'Draft'): ?><span class="inline-flex items-center h-[18px] px-1.5 rounded bg-signal-50 text-signal text-[10px] font-bold uppercase">Draft</span><?php endif ?>
        </div>
        <h3 class="font-display text-[15px] font-semibold text-ink leading-snug"><?= esc($a['title']) ?></h3>
        <div class="flex items-center gap-3 mt-3 text-[11.5px] text-faint font-mono">
          <span><?= number_format((int) $a['views']) ?> views</span><span><?= (int) $a['up_votes'] ?> helpful</span><span><?= th_rel($a['updated_at']) ?></span>
        </div>
      </a>
      <?php endforeach ?>
    </div>
  </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('modals') ?>
<template id="tpl-newArticle">
  <form method="post" action="<?= site_url('app/kb') ?>" data-modal-title="New article" data-modal-width="max-w-xl" data-submit="Save article">
    <?= csrf_field() ?>
    <div class="grid sm:grid-cols-2 gap-3.5">
      <div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Title<span class="text-alert"> *</span></label>
        <input name="title" required placeholder="Write it as the question people ask" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Category</label>
        <select name="category" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px]">
          <?php foreach (array_slice($cats, 1) as $c): ?><option><?= esc($c) ?></option><?php endforeach ?>
        </select></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Status</label>
        <select name="status" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px]"><option>Draft</option><option>Published</option></select></div>
      <div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Body<span class="text-alert"> *</span></label>
        <textarea name="body" rows="7" required placeholder="Steps, in the order someone would take them." class="w-full px-2.5 py-2 rounded-lg border border-line text-[13px] leading-relaxed focus:border-brand"></textarea></div>
    </div>
  </form>
</template>
<?= $this->endSection() ?>
