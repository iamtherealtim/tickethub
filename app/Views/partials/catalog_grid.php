<?php
/**
 * Shared catalog grid + per-item request modal templates.
 * Expects: $items, $cat (active category), $baseUrl (page url), $requestUrlFn (fn id => url),
 *          $onBehalf (bool), $requesters (when $onBehalf)
 */
$cats = array_merge(['All'], array_values(array_unique(array_column($items, 'category'))));
$list = array_values(array_filter($items, static fn ($c) => $cat === 'All' || $c['category'] === $cat));
?>
<div class="flex flex-wrap gap-1.5 mb-4">
  <?php foreach ($cats as $c): $on = $cat === $c; ?>
  <a href="<?= $baseUrl . ($c === 'All' ? '' : '?cat=' . urlencode($c)) ?>" class="h-8 px-3 rounded-lg text-[12.5px] font-medium border transition inline-flex items-center
    <?= $on ? 'bg-ink text-white border-ink' : 'bg-white text-muted border-line hover:text-ink' ?>"><?= esc($c) ?></a>
  <?php endforeach ?>
</div>
<?php
$editable = $editable ?? false;
// A stored field entry is only rendered when it has the bits the form needs;
// anything malformed (hand-typed JSON from before validation) is skipped, not fatal.
$safeFields = static function (?string $json): array {
    $fields = json_decode((string) $json, true);
    if (! is_array($fields)) {
        return [];
    }
    $out = [];
    foreach ($fields as $f) {
        if (! is_array($f) || ! isset($f['k'], $f['label']) || ! is_scalar($f['k']) || ! is_scalar($f['label']) || trim((string) $f['k']) === '') {
            continue;
        }
        $type = isset($f['type']) && is_scalar($f['type']) ? strtolower((string) $f['type']) : 'text';
        $opts = $f['options'] ?? $f['opts'] ?? [];
        $out[] = [
            'k' => (string) $f['k'], 'label' => (string) $f['label'],
            'type' => in_array($type, ['text', 'select', 'textarea', 'date', 'number'], true) ? $type : 'text',
            'options' => is_array($opts) ? array_values(array_filter($opts, 'is_scalar')) : [],
            'required' => filter_var($f['required'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    return $out;
};
?>
<?php if (! $list): ?>
  <?= th_card(th_empty('layers', $items ? 'Nothing in this category' : 'The catalog is empty', $items ? 'Pick another category above.' : ($editable ? 'Add the first item people can request.' : 'Nothing can be requested yet — raise a ticket instead.'), $editable && ! $items ? th_btn('Add item', 'data-modal="addCatalog"', 'brand', 'plus') : '')) ?>
<?php endif ?>
<div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
  <?php foreach ($list as $c): ?>
  <button data-modal="catalog-<?= $c['id'] ?>" class="relative text-left bg-white border border-line rounded-xl shadow-card p-4 hover:border-brand-100 hover:shadow-pop transition group">
    <?php if ($editable): ?>
    <span data-modal="editCatalog-<?= $c['id'] ?>" class="absolute top-3 right-3 w-7 h-7 grid place-items-center rounded-md text-faint hover:text-ink hover:bg-canvas" title="Edit item"><?= th_icon('edit', 'w-3.5 h-3.5') ?></span>
    <?php endif ?>
    <div class="w-9 h-9 rounded-lg bg-brand-50 border border-brand-100 grid place-items-center text-brand mb-3"><?= th_icon($c['icon'], 'w-[18px] h-[18px]') ?></div>
    <h3 class="font-display text-[15px] font-semibold text-ink"><?= esc($c['name']) ?></h3>
    <p class="text-[12.5px] text-muted mt-1 leading-relaxed"><?= esc($c['description']) ?></p>
    <div class="flex items-center gap-2 mt-3 pt-3 border-t border-line text-[11.5px]">
      <span class="font-mono text-faint"><?= esc($c['sla']) ?></span>
      <?php if ($c['approval'] !== 'None'): ?><span class="inline-flex items-center gap-1 text-signal"><?= th_icon('shield', 'w-3 h-3') ?><?= esc($c['approval']) ?> approval</span><?php endif ?>
      <span class="ml-auto text-brand font-semibold group-hover:underline"><?= $onBehalf ? 'Raise' : 'Request' ?></span>
    </div>
  </button>
  <?php endforeach ?>
</div>

<?php foreach ($items as $c): $fields = $safeFields($c['fields'] ?? '[]'); ?>
<template id="tpl-catalog-<?= $c['id'] ?>">
  <form method="post" action="<?= $requestUrlFn((int) $c['id']) ?>" data-modal-title="<?= esc($c['name'], 'attr') ?>"
        data-modal-sub="<?= esc($c['sla'] . ' · ' . ($c['approval'] === 'None' ? 'No approval needed' : $c['approval'] . ' approval required'), 'attr') ?>"
        data-modal-width="max-w-xl" data-submit="Submit request">
    <?= csrf_field() ?>
    <div class="grid sm:grid-cols-2 gap-3.5">
      <?php if ($onBehalf): ?>
      <div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Requesting for</label>
        <select name="requester_id" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px]">
          <?php foreach ($requesters as $u): ?><option value="<?= $u['id'] ?>"><?= esc($u['name']) ?> — <?= esc($u['dept']) ?></option><?php endforeach ?>
        </select></div>
      <?php endif ?>
      <?php foreach ($fields as $f): $half = $f['type'] !== 'textarea'; $req = $f['required'] ? ' required' : ''; ?>
      <div class="<?= $half ? '' : 'sm:col-span-2' ?>">
        <label class="block text-[12px] font-medium text-ink-500 mb-1.5"><?= esc($f['label']) ?><?= $f['required'] ? '<span class="text-alert"> *</span>' : '' ?></label>
        <?php if ($f['type'] === 'select'): ?>
        <select name="<?= esc($f['k'], 'attr') ?>"<?= $req ?> class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px]">
          <?php if (! $f['required']): ?><option value="">—</option><?php endif ?>
          <?php foreach ($f['options'] as $o): ?><option><?= esc($o) ?></option><?php endforeach ?>
        </select>
        <?php elseif ($f['type'] === 'textarea'): ?>
        <textarea name="<?= esc($f['k'], 'attr') ?>" rows="3"<?= $req ?> class="w-full px-2.5 py-2 rounded-lg border border-line text-[13px] leading-relaxed focus:border-brand"></textarea>
        <?php else: ?>
        <input name="<?= esc($f['k'], 'attr') ?>" type="<?= in_array($f['type'], ['date', 'number'], true) ? $f['type'] : 'text' ?>"<?= $req ?> class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand">
        <?php endif ?>
      </div>
      <?php endforeach ?>
      <div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Anything else we should know?</label>
        <textarea name="notes" rows="3" placeholder="Optional" class="w-full px-2.5 py-2 rounded-lg border border-line text-[13px] leading-relaxed placeholder:text-faint focus:border-brand"></textarea></div>
    </div>
  </form>
</template>
<?php endforeach ?>

<?php if ($editable):
  $itemFields = static function (array $c = []) use ($groups) {
      $icons = ['laptop', 'layers', 'lock', 'users', 'logout', 'phone', 'monitor', 'zap', 'mail', 'server', 'book', 'shield'];
      $html = '<div class="grid sm:grid-cols-2 gap-3.5">'
          . '<div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Item name<span class="text-alert"> *</span></label>'
          . '<input name="name" required value="' . esc($c['name'] ?? '', 'attr') . '" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>'
          . '<div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Category</label>'
          . '<input name="category" value="' . esc($c['category'] ?? 'Hardware', 'attr') . '" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>'
          . '<div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Icon</label>'
          . '<select name="icon" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px]">';
      foreach ($icons as $ic) {
          $html .= '<option' . (($c['icon'] ?? 'layers') === $ic ? ' selected' : '') . '>' . $ic . '</option>';
      }
      $html .= '</select></div>'
          . '<div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Description</label>'
          . '<input name="description" value="' . esc($c['description'] ?? '', 'attr') . '" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>'
          . '<div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Fulfilment promise</label>'
          . '<input name="sla" value="' . esc($c['sla'] ?? '3 business days', 'attr') . '" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>'
          . '<div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Approval</label>'
          . '<input name="approval" value="' . esc($c['approval'] ?? 'None', 'attr') . '" placeholder="None / Manager / System owner" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>'
          . '<div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Fulfilling team</label>'
          . '<select name="group_id" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px]">'
          . '<option value="">Automatic — route by rules</option>'
          . implode('', array_map(static fn ($g) => '<option value="' . $g['id'] . '"' . ((int) ($c['group_id'] ?? 0) === (int) $g['id'] ? ' selected' : '') . '>' . esc($g['name']) . '</option>', $groups))
          . '</select></div>'
          . '<div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Form fields (JSON)</label>'
          . '<textarea name="fields" rows="5" class="w-full px-2.5 py-2 rounded-lg border border-line text-[12px] font-mono leading-relaxed focus:border-brand">'
          . esc($c ? json_encode(json_decode($c['fields'] ?? '[]', true) ?: [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : '[]')
          . '</textarea>'
          . '<p class="text-[11.5px] text-faint mt-1">Array of <code class="font-mono">{"k":"slug","label":"…","type":"text|select|textarea|date|number","required":false,"options":[…]}</code> — <code class="font-mono">options</code> only for select. Leave <code class="font-mono">[]</code> for none.</p></div>'
          . '</div>';

      return $html;
  };
?>
<template id="tpl-addCatalog">
  <form method="post" action="<?= site_url('app/catalog') ?>" data-modal-title="Add catalog item" data-modal-width="max-w-xl" data-submit="Add item">
    <?= csrf_field() ?>
    <?= $itemFields() ?>
  </form>
</template>
<?php foreach ($items as $c): ?>
<template id="tpl-editCatalog-<?= $c['id'] ?>">
  <div data-modal-title="Edit <?= esc($c['name'], 'attr') ?>" data-modal-width="max-w-xl" data-submit="Save item">
    <form method="post" action="<?= site_url('app/catalog/' . $c['id'] . '/update') ?>" data-primary>
      <?= csrf_field() ?>
      <?= $itemFields($c) ?>
    </form>
    <form method="post" action="<?= site_url('app/catalog/' . $c['id'] . '/delete') ?>" data-confirm="Remove &ldquo;<?= esc($c['name'], 'attr') ?>&rdquo; from the catalog?" data-confirm-label="Delete" class="mt-3">
      <?= csrf_field() ?>
      <button type="submit" class="inline-flex items-center gap-1.5 h-8 px-2.5 rounded-lg border border-alert-100 bg-white text-[12.5px] font-medium text-alert hover:bg-alert-50"><?= th_icon('trash', 'w-3.5 h-3.5') ?>Delete item</button>
    </form>
  </div>
</template>
<?php endforeach ?>
<?php endif ?>
