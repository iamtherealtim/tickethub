<?php
/**
 * Deflection panel: articles that may answer the request being typed.
 * Deliberately quiet — a suggestion, never a barrier to raising a ticket.
 */
?>
<div class="rounded-lg border border-brand-100 bg-brand-50 p-3">
  <div class="flex items-center gap-1.5 mb-2">
    <span class="text-brand"><?= th_icon('book', 'w-3.5 h-3.5') ?></span>
    <span class="text-[11px] font-semibold uppercase tracking-[.09em] text-brand">This might already be answered</span>
  </div>
  <div class="space-y-1">
    <?php foreach ($results as $r): $a = $r['a']; ?>
    <a href="<?= site_url($base . $a['id']) ?>" target="_blank" rel="noopener"
       class="flex items-center gap-2 px-2 py-1.5 rounded-md bg-white/70 hover:bg-white transition">
      <span class="text-[12.5px] text-ink truncate flex-1"><?= esc($a['title']) ?></span>
      <span class="text-[11px] text-faint shrink-0"><?= esc($a['category']) ?></span>
      <span class="text-faint shrink-0"><?= th_icon('ext', 'w-3 h-3') ?></span>
    </a>
    <?php endforeach ?>
  </div>
</div>
