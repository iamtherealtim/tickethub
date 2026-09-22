<?= $this->extend('layouts/portal') ?>
<?= $this->section('content') ?>

<?php helper('i18n'); $author = $users[(int) $a['author_id']] ?? null; ?>
<div class="max-w-[760px] mx-auto px-5 py-8 fade-in">
  <a href="<?= site_url('portal/kb') ?>" class="inline-flex items-center gap-1 text-[12.5px] text-muted hover:text-ink mb-4"><?= th_icon('back', 'w-3.5 h-3.5') ?> <?= lang('Portal.article.back') ?></a>
  <article class="bg-white border border-line rounded-xl shadow-card p-6">
    <div class="text-[11px] font-semibold uppercase tracking-[.09em] text-faint"><?= esc($a['category']) ?></div>
    <h1 class="font-display text-[26px] font-semibold leading-tight mt-1.5"><?= esc($a['title']) ?></h1>
    <p class="text-[12.5px] text-faint mt-2"><?= esc(lang('Portal.article.updatedBy', ['when' => th_rel($a['updated_at']), 'who' => $author['name'] ?? lang('Common.label.none')])) ?></p>
    <div class="prose-kb mt-5 text-[14.5px]"><?= $a['body'] ?></div>
    <div class="mt-7 pt-4 border-t border-line flex flex-wrap items-center gap-3">
      <span class="text-[13px] text-muted"><?= lang('Portal.article.solved') ?></span>
      <form method="post" action="<?= site_url('portal/kb/' . $a['id'] . '/vote') ?>"><?= csrf_field() ?><input type="hidden" name="vote" value="up">
        <button type="submit" class="inline-flex items-center gap-1.5 h-9 px-3.5 rounded-lg border border-line text-[13px] hover:bg-brand-50 hover:border-brand-100 hover:text-brand"><?= th_icon('check', 'w-4 h-4') ?> <?= lang('Portal.article.yes') ?></button></form>
      <form method="post" action="<?= site_url('portal/kb/' . $a['id'] . '/vote') ?>"><?= csrf_field() ?><input type="hidden" name="vote" value="down">
        <button type="submit" class="inline-flex items-center gap-1.5 h-9 px-3.5 rounded-lg border border-line text-[13px] hover:bg-alert-50 hover:border-alert-100 hover:text-alert"><?= th_icon('x', 'w-4 h-4') ?> <?= lang('Portal.article.notQuite') ?></button></form>
      <button type="button" data-copy="<?= esc(site_url('portal/kb/' . $a['id']), 'attr') ?>" class="ml-auto inline-flex items-center gap-1.5 h-9 px-3.5 rounded-lg border border-line text-[13px] text-ink-500 hover:bg-canvas" title="<?= esc(lang('Portal.article.copyLink'), 'attr') ?>"><?= th_icon('link', 'w-4 h-4') ?> <?= lang('Common.btn.share') ?></button>
      <a href="<?= site_url('portal/new') ?>" class="text-[13px] text-brand font-medium hover:underline"><?= lang('Portal.article.stillStuck') ?></a>
    </div>
  </article>
</div>
<?= $this->endSection() ?>
