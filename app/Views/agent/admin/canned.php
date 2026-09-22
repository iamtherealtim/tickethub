<?php
/* Admin → Canned responses. Vars from canned.tab.php: cannedRows, cannedScoped, cannedOwners; plus groups, me. */
$groupsById = [];
foreach ($groups as $g) {
    $groupsById[(int) $g['id']] = $g['name'];
}
$scopeChip = static function (array $c) use ($groupsById, $cannedOwners): string {
    $scope = $c['scope'] ?? 'global';
    if ($scope === 'group') {
        return '<span class="inline-flex h-[20px] px-1.5 rounded border text-[11px] font-semibold bg-violet-50 text-violet border-violet/20">' . esc($groupsById[(int) $c['group_id']] ?? 'Group') . '</span>';
    }
    if ($scope === 'personal') {
        return '<span class="inline-flex h-[20px] px-1.5 rounded border text-[11px] font-semibold bg-canvas text-muted border-line">' . esc($cannedOwners[(int) $c['owner_id']] ?? 'Personal') . '</span>';
    }

    return '<span class="inline-flex h-[20px] px-1.5 rounded border text-[11px] font-semibold bg-brand-50 text-brand border-brand-100">Global</span>';
};
$inputCls = 'w-full h-9 px-2.5 rounded-lg border border-line bg-white text-[13px] placeholder:text-faint focus:border-brand';
$formFields = static function (array $c = []) use ($groups, $inputCls): string {
    $scope = $c['scope'] ?? 'global';
    $html = '<div class="grid sm:grid-cols-2 gap-3.5">'
        . '<div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Title<span class="text-alert"> *</span></label>'
        . '<input name="title" required maxlength="80" value="' . esc($c['title'] ?? '', 'attr') . '" class="' . $inputCls . '"></div>'
        . '<div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Who can use it</label>'
        . '<select name="scope" class="' . $inputCls . '">'
        . '<option value="global"' . ($scope === 'global' ? ' selected' : '') . '>Everyone</option>'
        . '<option value="group"' . ($scope === 'group' ? ' selected' : '') . '>One group</option></select></div>'
        . '<div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Group <span class="text-faint font-normal">(for group scope)</span></label>'
        . '<select name="group_id" class="' . $inputCls . '"><option value="">—</option>';
    foreach ($groups as $g) {
        $html .= '<option value="' . $g['id'] . '"' . ((int) ($c['group_id'] ?? 0) === (int) $g['id'] ? ' selected' : '') . '>' . esc($g['name']) . '</option>';
    }
    $html .= '</select></div>'
        . '<div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Shortcut</label>'
        . '<div class="flex items-center h-9 rounded-lg border border-line bg-white text-[13px] focus-within:border-brand"><span class="pl-2.5 text-faint font-mono">/</span>'
        . '<input name="shortcut" maxlength="40" value="' . esc($c['shortcut'] ?? '', 'attr') . '" placeholder="reset" class="flex-1 min-w-0 h-full px-1.5 rounded-r-lg border-0 text-[13px] font-mono focus:ring-0"></div>'
        . '<p class="text-[11.5px] text-faint mt-1">Agents type /shortcut at the start of a line and press Tab.</p></div>'
        . '<div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Body<span class="text-alert"> *</span></label>'
        . '<textarea name="body" rows="7" required class="w-full px-2.5 py-2 rounded-lg border border-line text-[13px] leading-relaxed focus:border-brand">' . esc($c['body'] ?? '') . '</textarea>'
        . '<p class="text-[11.5px] text-faint mt-1 leading-relaxed">Markdown is fine. Placeholders filled in on insert: <code class="font-mono">{{requester_first_name}}</code> <code class="font-mono">{{requester_name}}</code> <code class="font-mono">{{agent_name}}</code> <code class="font-mono">{{ticket_code}}</code> <code class="font-mono">{{ticket_subject}}</code></p></div>'
        . '</div>';

    return $html;
};
?>
<?php if (! $cannedScoped): ?>
<div class="mb-3 rounded-xl border border-signal-100 bg-signal-50 p-3.5 text-[13px] text-signal">Run <code class="font-mono">php spark migrate</code> to enable scopes and shortcuts — until then every response is global.</div>
<?php endif ?>

<?= th_card(
    th_card_head('Canned responses', th_btn('Add response', 'data-modal="addCanned"', 'brand', 'plus'))
    . '<div class="px-4 py-2.5 bg-canvas border-b border-line text-[12px] text-muted">Global responses are offered to every agent, group ones to that team only. Personal responses are created by agents from the reply box ("Save as response") and are listed here for reference.</div>'
    . th_table_head([['Response', 'flex-1'], ['Scope', 'w-[150px]'], ['Shortcut', 'w-[120px]'], ['', 'w-[150px] text-right']])
    . ($cannedRows ? implode('', array_map(static function ($c) use ($scopeChip, $cannedScoped) {
        $personal = ($c['scope'] ?? 'global') === 'personal';

        return '<div class="flex flex-wrap md:flex-nowrap items-center gap-3 px-4 py-2.5 border-b border-line last:border-0">'
            . '<div class="flex-1 min-w-0"><div class="text-[13px] font-medium text-ink truncate">' . esc($c['title']) . '</div>'
            . '<div class="text-[11.5px] text-muted truncate">' . esc(mb_substr(preg_replace('/\s+/', ' ', $c['body']), 0, 110)) . '</div></div>'
            . '<span class="w-[150px]">' . $scopeChip($c) . '</span>'
            . '<span class="w-[120px] min-w-0 truncate font-mono text-[12px] text-ink-500">' . (! empty($c['shortcut']) ? '/' . esc($c['shortcut']) : '<span class="text-faint">—</span>') . '</span>'
            . '<span class="w-[150px] flex items-center justify-end gap-1.5">'
            . ($personal ? '<span class="text-[11.5px] text-faint">Agent-managed</span>' : th_btn('Edit', 'data-modal="editCanned-' . $c['id'] . '"', 'ghost', 'edit'))
            . '<form method="post" action="' . site_url('app/admin/canned/' . $c['id'] . '/delete') . '" data-confirm="Remove &quot;' . esc($c['title'], 'attr') . '&quot;? Agents will no longer see it." data-confirm-label="Remove" data-confirm-title="Remove this response?">' . csrf_field()
            . '<button type="submit" class="w-8 h-8 grid place-items-center rounded-lg text-faint hover:text-alert hover:bg-alert-50" title="Remove">' . th_icon('trash', 'w-3.5 h-3.5') . '</button></form>'
            . '</span></div>';
    }, $cannedRows))
    : th_empty('note', 'No canned responses yet', 'Add the answers your team types most — password resets, "need more detail", vendor waits.'))
) ?>

<template id="tpl-addCanned">
  <form method="post" action="<?= site_url('app/admin/canned') ?>" data-modal-title="New canned response" data-modal-width="max-w-2xl" data-submit="Add response">
    <?= csrf_field() ?>
    <?= $formFields() ?>
  </form>
</template>
<?php foreach ($cannedRows as $c): if (($c['scope'] ?? 'global') === 'personal') { continue; } ?>
<template id="tpl-editCanned-<?= $c['id'] ?>">
  <form method="post" action="<?= site_url('app/admin/canned/' . $c['id']) ?>" data-modal-title="Edit response" data-modal-width="max-w-2xl" data-submit="Save">
    <?= csrf_field() ?>
    <?= $formFields($c) ?>
  </form>
</template>
<?php endforeach ?>
