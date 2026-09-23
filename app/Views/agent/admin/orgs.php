<?php
/** @var array $orgs @var array $orgMembers @var bool $orgsReady @var string $inputCls */
$orgFields = static function (array $o = []) use ($inputCls) {
    return '<div class="grid sm:grid-cols-2 gap-3.5">'
        . '<div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Name<span class="text-alert"> *</span></label>'
        . '<input name="name" required value="' . esc($o['name'] ?? '', 'attr') . '" class="' . $inputCls . '"></div>'
        . '<div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Email domain</label>'
        . '<input name="domain" value="' . esc($o['domain'] ?? '', 'attr') . '" placeholder="example.com" class="' . $inputCls . ' font-mono text-[12px]">'
        . '<p class="text-[11.5px] text-faint mt-1">People whose email ends in this domain can be attached automatically.</p></div>'
        . '<div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Notes</label>'
        . '<textarea name="notes" rows="2" class="w-full px-2.5 py-2 rounded-lg border border-line text-[12.5px] leading-relaxed focus:border-brand">' . esc($o['notes'] ?? '') . '</textarea></div>'
        . '<label class="sm:col-span-2 inline-flex items-center gap-2 text-[12.5px] text-ink-500">'
        . '<input type="checkbox" name="auto_assign" value="1" checked class="w-[15px] h-[15px] rounded-sm border-line">'
        . 'Auto-assign by email domain on save — attaches every person with a matching email who is not in an organization yet</label>'
        . '</div>';
};
?>
<?php if (! $orgsReady): ?>
<?= th_card('<div class="p-4 text-[13px] text-muted">Run <code class="font-mono">php spark migrate</code> to create the organizations table.</div>') ?>
<?php else: ?>
<?= th_card(
    th_card_head('Organizations', th_btn('New organization', 'data-modal="addOrg"', 'brand', 'plus'))
    . th_table_head([['Organization', 'flex-1'], ['Domain', 'w-[200px]'], ['Members', 'w-[80px] text-right'], ['', 'w-[70px]']])
    . (count($orgs) === 0
        ? th_empty('home', 'No organizations yet', 'Group requesters by company or department, then filter tickets and history by organization.')
        : implode('', array_map(static function ($o) use ($orgMembers) {
            return '<div class="flex flex-wrap md:flex-nowrap items-center gap-3 px-4 py-2.5 border-b border-line last:border-0">'
                . '<div class="flex-1 min-w-0"><div class="text-[13px] font-medium text-ink truncate">' . esc($o['name']) . '</div>'
                . ($o['notes'] ? '<div class="text-[11.5px] text-faint truncate">' . esc($o['notes']) . '</div>' : '') . '</div>'
                . '<span class="w-[200px] font-mono text-[12px] text-muted truncate">' . ($o['domain'] ? '@' . esc($o['domain']) : '—') . '</span>'
                . '<span class="w-[80px] text-right font-mono text-[12px] text-muted">' . ($orgMembers[(int) $o['id']] ?? 0) . '</span>'
                . '<span class="w-[70px] flex justify-end">' . th_btn('Edit', 'data-modal="editOrg-' . $o['id'] . '"', 'ghost', 'edit') . '</span>'
                . '</div>';
        }, $orgs)))
) ?>

<template id="tpl-addOrg">
  <form method="post" action="<?= site_url('app/admin/orgs') ?>" data-modal-title="New organization" data-submit="Create">
    <?= csrf_field() ?>
    <?= $orgFields() ?>
  </form>
</template>

<?php foreach ($orgs as $o): ?>
<template id="tpl-editOrg-<?= $o['id'] ?>">
  <div data-modal-title="Edit <?= esc($o['name'], 'attr') ?>" data-modal-sub="<?= ($orgMembers[(int) $o['id']] ?? 0) ?> member(s)" data-submit="Save">
    <form method="post" action="<?= site_url('app/admin/orgs/' . $o['id']) ?>" data-primary>
      <?= csrf_field() ?>
      <?= $orgFields($o) ?>
    </form>
    <form method="post" action="<?= site_url('app/admin/orgs/' . $o['id'] . '/delete') ?>" class="mt-3 pt-1"
          data-confirm="Delete &ldquo;<?= esc($o['name'], 'attr') ?>&rdquo;? Its members are kept and simply detached." data-confirm-label="Delete" data-confirm-title="Delete this organization?">
      <?= csrf_field() ?>
      <button type="submit" class="inline-flex items-center gap-1.5 h-8 px-2.5 rounded-lg border border-alert-100 bg-white text-[12.5px] font-medium text-alert hover:bg-alert-50"><?= th_icon('trash', 'w-3.5 h-3.5') ?>Delete</button>
    </form>
  </div>
</template>
<?php endforeach ?>
<?php endif ?>
