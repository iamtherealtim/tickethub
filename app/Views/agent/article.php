<?= $this->extend('layouts/agent') ?>
<?= $this->section('content') ?>

<?php $author = $users[(int) $a['author_id']] ?? null; $tags = json_decode($a['tags'] ?? '[]', true) ?: []; ?>
<div class="p-5 max-w-[860px] mx-auto fade-in">
  <a href="<?= site_url('app/kb') ?>" class="inline-flex items-center gap-1 text-[12px] text-muted hover:text-ink mb-3"><?= th_icon('back', 'w-3.5 h-3.5') ?> Knowledge</a>
  <section class="bg-white border border-line rounded-xl shadow-card">
    <article class="p-6">
      <div class="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-[.09em] text-faint mb-2"><?= esc($a['category']) ?>
        <?php if ($a['status'] === 'Draft'): ?><span class="px-1.5 rounded bg-signal-50 text-signal">Draft</span><?php endif ?></div>
      <h1 class="font-display text-[26px] font-semibold leading-tight"><?= esc($a['title']) ?></h1>
      <div class="flex items-center gap-3 mt-3 pb-4 border-b border-line text-[12px] text-muted">
        <?= th_avatar($author, 24) ?><span><?= esc($author['name'] ?? '—') ?></span><span class="text-line">·</span>
        <span>Updated <?= th_rel($a['updated_at']) ?></span><span class="text-line">·</span><span class="font-mono"><?= number_format((int) $a['views']) ?> views</span>
        <div class="ml-auto flex gap-2">
          <?= th_btn('Edit', 'data-modal="editArticle"', 'ghost', 'edit') ?>
          <form method="post" action="<?= site_url('app/kb/' . $a['id'] . '/delete') ?>" data-confirm="Delete &ldquo;<?= esc($a['title'], 'attr') ?>&rdquo;? Portal links to it will stop working." data-confirm-label="Delete">
            <?= csrf_field() ?><?= th_btn('Delete', 'type="submit"', 'danger', 'trash') ?></form>
        </div>
      </div>
      <div class="prose-kb mt-4 text-[14px]"><?= $a['body'] ?></div>
      <?php if ($tags): ?><div class="flex flex-wrap gap-1.5 mt-6"><?= implode('', array_map('th_tag_pill', $tags)) ?></div><?php endif ?>
      <div class="mt-6 pt-4 border-t border-line flex flex-wrap items-center gap-3">
        <span class="text-[13px] text-muted">Did this solve it?</span>
        <form method="post" action="<?= site_url('app/kb/' . $a['id'] . '/vote') ?>"><?= csrf_field() ?><input type="hidden" name="vote" value="up">
          <button type="submit" class="inline-flex items-center gap-1.5 h-8 px-3 rounded-lg border border-line text-[12.5px] hover:bg-brand-50 hover:border-brand-100 hover:text-brand"><?= th_icon('check', 'w-3.5 h-3.5') ?> Yes <span class="font-mono text-faint"><?= (int) $a['up_votes'] ?></span></button></form>
        <form method="post" action="<?= site_url('app/kb/' . $a['id'] . '/vote') ?>"><?= csrf_field() ?><input type="hidden" name="vote" value="down">
          <button type="submit" class="inline-flex items-center gap-1.5 h-8 px-3 rounded-lg border border-line text-[12.5px] hover:bg-alert-50 hover:border-alert-100 hover:text-alert"><?= th_icon('x', 'w-3.5 h-3.5') ?> No <span class="font-mono text-faint"><?= (int) $a['down_votes'] ?></span></button></form>
      </div>
    </article>
  </section>
  <?php if ($related): ?>
  <div class="mt-4">
    <h2 class="font-display text-[14px] font-semibold mb-2">More in <?= esc($a['category']) ?></h2>
    <div class="grid sm:grid-cols-3 gap-3">
      <?php foreach ($related as $r): ?>
      <a href="<?= site_url('app/kb/' . $r['id']) ?>" class="text-left bg-white border border-line rounded-xl p-3 hover:border-[#CBD1DC]">
        <div class="text-[13px] font-medium leading-snug"><?= esc($r['title']) ?></div>
        <div class="font-mono text-[11px] text-faint mt-1.5"><?= (int) $r['up_votes'] ?> found this helpful</div></a>
      <?php endforeach ?>
    </div>
  </div>
  <?php endif ?>
</div>
<?= $this->endSection() ?>

<?= $this->section('modals') ?>
<template id="tpl-editArticle">
  <form method="post" action="<?= site_url('app/kb/' . $a['id'] . '/update') ?>" data-modal-title="Edit article" data-modal-width="max-w-2xl" data-submit="Save article">
    <?= csrf_field() ?>
    <div class="grid sm:grid-cols-2 gap-3.5">
      <div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Title<span class="text-alert"> *</span></label>
        <input name="title" required value="<?= esc($a['title'], 'attr') ?>" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Category</label>
        <input name="category" value="<?= esc($a['category'], 'attr') ?>" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Status</label>
        <select name="status" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px]">
          <option <?= $a['status'] === 'Draft' ? 'selected' : '' ?>>Draft</option>
          <option <?= $a['status'] === 'Published' ? 'selected' : '' ?>>Published</option>
        </select></div>
      <div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Body (HTML)<span class="text-alert"> *</span></label>
        <textarea name="body" rows="14" required class="w-full px-2.5 py-2 rounded-lg border border-line text-[12.5px] font-mono leading-relaxed focus:border-brand"><?= esc($a['body']) ?></textarea>
        <p class="text-[11.5px] text-faint mt-1">Supports <code class="font-mono">&lt;h2&gt;</code>, <code class="font-mono">&lt;p&gt;</code>, <code class="font-mono">&lt;ul&gt;&lt;li&gt;</code> and <code class="font-mono">&lt;code&gt;</code>.</p></div>
    </div>
  </form>
</template>
<?= $this->endSection() ?>
