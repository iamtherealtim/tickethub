<?php if ($arts || $items): ?>
  <?php if ($arts): ?><div class="px-3 pt-2.5 pb-1 text-[11px] font-semibold uppercase tracking-[.09em] text-faint">Answers</div><?php endif ?>
  <?php foreach ($arts as $a): ?>
  <a href="<?= site_url('portal/kb/' . $a['id']) ?>" class="w-full flex items-center gap-2.5 px-3 h-10 hover:bg-canvas text-left">
    <span class="text-faint"><?= th_icon('book', 'w-4 h-4') ?></span><span class="text-[13px] truncate flex-1"><?= esc($a['title']) ?></span>
    <span class="text-[11px] text-faint shrink-0"><?= esc($a['category']) ?></span></a>
  <?php endforeach ?>
  <?php if ($items): ?><div class="px-3 pt-2.5 pb-1 text-[11px] font-semibold uppercase tracking-[.09em] text-faint border-t border-line">Services</div><?php endif ?>
  <?php foreach ($items as $c): ?>
  <a href="<?= site_url('portal/catalog?cat=' . urlencode($c['category'])) ?>" class="w-full flex items-center gap-2.5 px-3 h-10 hover:bg-canvas text-left">
    <span class="text-brand"><?= th_icon($c['icon'], 'w-4 h-4') ?></span><span class="text-[13px] truncate flex-1"><?= esc($c['name']) ?></span>
    <span class="text-[11px] text-faint shrink-0"><?= esc($c['sla']) ?></span></a>
  <?php endforeach ?>
<?php else: ?>
  <div class="px-3 py-4 text-[13px] text-muted">No answer for &ldquo;<?= esc($q) ?>&rdquo;. <a href="<?= site_url('portal/new') ?>" class="text-brand font-medium">Raise a ticket instead</a></div>
<?php endif ?>
