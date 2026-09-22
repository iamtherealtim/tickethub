<?php
/* Admin → Ticket templates. Vars from templates.tab.php: tplRows, recurringRows, tplReady, tplRequesters;
   plus groups, agents, settings, me from admin.php. */
$inputCls = 'w-full h-9 px-2.5 rounded-lg border border-line bg-white text-[13px] placeholder:text-faint focus:border-brand';
$groupsById = [];
foreach ($groups as $g) {
    $groupsById[(int) $g['id']] = $g['name'];
}
$agentsById = [];
foreach ($agents as $a) {
    $agentsById[(int) $a['id']] = $a['name'];
}
$appTz = $settings['app_timezone'] ?? 'UTC';
$weekdays = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

$tplFields = static function (array $t = []) use ($groups, $agents, $inputCls): string {
    $opt = static fn (array $list, $cur, $labelKey = 'name') => implode('', array_map(
        static fn ($x) => '<option value="' . $x['id'] . '"' . ((int) $cur === (int) $x['id'] ? ' selected' : '') . '>' . esc($x[$labelKey]) . '</option>',
        $list
    ));
    $sel = static fn (array $opts, string $cur) => implode('', array_map(static fn ($o) => '<option' . ($o === $cur ? ' selected' : '') . '>' . $o . '</option>', $opts));
    $tasks = implode("\n", json_decode((string) ($t['tasks'] ?? '[]'), true) ?: []);

    return '<div class="grid sm:grid-cols-2 gap-3.5">'
        . '<div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Template name<span class="text-alert"> *</span></label>'
        . '<input name="name" required maxlength="100" value="' . esc($t['name'] ?? '', 'attr') . '" placeholder="e.g. New starter onboarding" class="' . $inputCls . '"></div>'
        . '<div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Ticket subject<span class="text-alert"> *</span></label>'
        . '<input name="subject" required maxlength="250" value="' . esc($t['subject'] ?? '', 'attr') . '" class="' . $inputCls . '"></div>'
        . '<div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Type</label><select name="type" class="' . $inputCls . '">' . $sel(['Incident', 'Service request'], $t['type'] ?? 'Incident') . '</select></div>'
        . '<div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Priority</label><select name="priority" class="' . $inputCls . '">' . $sel(array_keys(TH_PRIORITY), $t['priority'] ?? 'Medium') . '</select></div>'
        . '<div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Category</label><select name="category" class="' . $inputCls . '">' . $sel(TH_CATEGORIES, $t['category'] ?? 'Software') . '</select></div>'
        . '<div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Group</label><select name="group_id" class="' . $inputCls . '"><option value="">Automatic — route by rules</option>' . $opt($groups, $t['group_id'] ?? 0) . '</select></div>'
        . '<div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Assign to</label><select name="agent_id" class="' . $inputCls . '"><option value="">Leave unassigned</option>' . $opt($agents, $t['agent_id'] ?? 0) . '</select></div>'
        . '<div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Description<span class="text-alert"> *</span></label>'
        . '<textarea name="body" rows="5" required class="w-full px-2.5 py-2 rounded-lg border border-line text-[13px] leading-relaxed focus:border-brand">' . esc($t['body'] ?? '') . '</textarea></div>'
        . '<div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Tasks <span class="text-faint font-normal">(one per line)</span></label>'
        . '<textarea name="tasks" rows="4" placeholder="Create AD account&#10;Order laptop&#10;Add to Teams channels" class="w-full px-2.5 py-2 rounded-lg border border-line text-[13px] leading-relaxed placeholder:text-faint focus:border-brand">' . esc($tasks) . '</textarea></div>'
        . '</div>';
};

$recFields = static function (array $r = []) use ($tplRows, $tplRequesters, $inputCls, $appTz, $weekdays): string {
    $every = $r['every'] ?? 'week';
    $html = '<div class="grid sm:grid-cols-2 gap-3.5">'
        . '<div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Template<span class="text-alert"> *</span></label><select name="template_id" required class="' . $inputCls . '">';
    foreach ($tplRows as $t) {
        $html .= '<option value="' . $t['id'] . '"' . ((int) ($r['template_id'] ?? 0) === (int) $t['id'] ? ' selected' : '') . '>' . esc($t['name']) . '</option>';
    }
    $html .= '</select></div>'
        . '<div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Raise on behalf of<span class="text-alert"> *</span></label><select name="requester_id" required class="' . $inputCls . '">';
    foreach ($tplRequesters as $u) {
        $html .= '<option value="' . $u['id'] . '"' . ((int) ($r['requester_id'] ?? 0) === (int) $u['id'] ? ' selected' : '') . '>' . esc($u['name']) . ($u['dept'] ? ' — ' . esc($u['dept']) : '') . '</option>';
    }
    $html .= '</select></div>'
        . '<div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Repeat every</label><div class="flex gap-2">'
        . '<input name="interval" type="number" min="1" max="52" value="' . (int) ($r['interval'] ?? 1) . '" class="w-[70px] h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand">'
        . '<select name="every" class="' . $inputCls . '">'
        . '<option value="day"' . ($every === 'day' ? ' selected' : '') . '>day(s)</option>'
        . '<option value="week"' . ($every === 'week' ? ' selected' : '') . '>week(s)</option>'
        . '<option value="month"' . ($every === 'month' ? ' selected' : '') . '>month(s)</option></select></div></div>'
        . '<div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">At</label>'
        . '<input name="at_time" type="time" value="' . esc(substr((string) ($r['at_time'] ?? '09:00'), 0, 5), 'attr') . '" class="' . $inputCls . '"></div>'
        . '<div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">On weekday <span class="text-faint font-normal">(weekly)</span></label><select name="weekday" class="' . $inputCls . '"><option value="">Same day as the first run</option>';
    foreach ($weekdays as $i => $d) {
        $html .= '<option value="' . $i . '"' . (($r['weekday'] ?? '') !== '' && $r['weekday'] !== null && (int) $r['weekday'] === $i ? ' selected' : '') . '>' . $d . '</option>';
    }
    $html .= '</select></div>'
        . '<div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">On day of month <span class="text-faint font-normal">(monthly)</span></label>'
        . '<input name="day_of_month" type="number" min="1" max="31" value="' . esc((string) ($r['day_of_month'] ?? ''), 'attr') . '" placeholder="1–31 (clamped to short months)" class="' . $inputCls . '"></div>'
        . '<div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Timezone</label>'
        . '<input name="tz" value="' . esc($r['tz'] ?? $appTz, 'attr') . '" list="th-tz-list" class="' . $inputCls . ' font-mono">'
        . '<p class="text-[11.5px] text-faint mt-1">Schedules are evaluated in this zone, so "09:00" stays 09:00 across daylight-saving changes. The cron (<code class="font-mono">php spark tickets:cron</code>) must be running.</p></div>'
        . '</div>';

    return $html;
};
?>
<?php if (! $tplReady): ?>
<div class="mb-3 rounded-xl border border-signal-100 bg-signal-50 p-3.5 text-[13px] text-signal">Run <code class="font-mono">php spark migrate</code> to create the ticket_templates and recurring_tickets tables.</div>
<?php else: ?>

<?= th_card(
    th_card_head('Ticket templates', th_btn('Add template', 'data-modal="addTemplate"', 'brand', 'plus'))
    . '<div class="px-4 py-2.5 bg-canvas border-b border-line text-[12px] text-muted">Agents pick one at the top of the New ticket form; it fills the ticket and adds the task list. Supervisors can also save any ticket as a template from its page.</div>'
    . th_table_head([['Template', 'flex-1'], ['Type · priority', 'w-[170px]'], ['Group / assignee', 'w-[190px]'], ['Tasks', 'w-[60px] text-right'], ['', 'w-[110px] text-right']])
    . ($tplRows ? implode('', array_map(static function ($t) use ($groupsById, $agentsById) {
        $n = count(json_decode((string) ($t['tasks'] ?? '[]'), true) ?: []);

        return '<div class="flex flex-wrap md:flex-nowrap items-center gap-3 px-4 py-2.5 border-b border-line last:border-0">'
            . '<div class="flex-1 min-w-0"><div class="text-[13px] font-medium text-ink truncate">' . esc($t['name']) . '</div>'
            . '<div class="text-[11.5px] text-muted truncate">' . esc($t['subject']) . '</div></div>'
            . '<span class="w-[170px] text-[12.5px] text-muted">' . esc($t['type']) . ' · ' . esc($t['priority']) . '</span>'
            . '<span class="w-[190px] text-[12.5px] text-muted truncate">' . esc($groupsById[(int) $t['group_id']] ?? 'Auto-route') . ($t['agent_id'] ? ' / ' . esc($agentsById[(int) $t['agent_id']] ?? '?') : '') . '</span>'
            . '<span class="w-[60px] text-right font-mono text-[12px] ' . ($n ? 'text-ink' : 'text-faint') . '">' . $n . '</span>'
            . '<span class="w-[110px] flex items-center justify-end gap-1.5">' . th_btn('Edit', 'data-modal="editTemplate-' . $t['id'] . '"', 'ghost', 'edit')
            . '<form method="post" action="' . site_url('app/admin/ticket-templates/' . $t['id'] . '/delete') . '" data-confirm="Remove &quot;' . esc($t['name'], 'attr') . '&quot;? Any recurring schedule using it is removed too." data-confirm-label="Remove" data-confirm-title="Remove this template?">' . csrf_field()
            . '<button type="submit" class="w-8 h-8 grid place-items-center rounded-lg text-faint hover:text-alert hover:bg-alert-50" title="Remove">' . th_icon('trash', 'w-3.5 h-3.5') . '</button></form></span>'
            . '</div>';
    }, $tplRows))
    : th_empty('layers', 'No templates yet', 'Templates pre-fill the New ticket form and carry a task list — onboarding, leaver, monthly patching.'))
) ?>

<div class="mt-4">
<?= th_card(
    th_card_head('Recurring tickets', $tplRows ? th_btn('Add schedule', 'data-modal="addRecurring"', 'brand', 'plus') : '<span class="text-[12px] text-faint">Add a template first</span>')
    . th_table_head([['Schedule', 'flex-1'], ['Next run', 'w-[170px]'], ['Last run', 'w-[120px]'], ['On', 'w-[130px] text-right']])
    . ($recurringRows ? implode('', array_map(static function ($r) use ($weekdays) {
        $when = 'Every ' . ((int) $r['interval'] > 1 ? $r['interval'] . ' ' : '') . $r['every'] . ((int) $r['interval'] > 1 ? 's' : '')
            . ($r['every'] === 'week' && $r['weekday'] !== null && $r['weekday'] !== '' ? ' on ' . $weekdays[(int) $r['weekday'] % 7] : '')
            . ($r['every'] === 'month' && $r['day_of_month'] ? ' on day ' . (int) $r['day_of_month'] : '')
            . ' at ' . substr((string) $r['at_time'], 0, 5) . ' ' . $r['tz'];

        return '<div class="flex flex-wrap md:flex-nowrap items-center gap-3 px-4 py-2.5 border-b border-line last:border-0 ' . ((int) $r['active'] ? '' : 'opacity-60') . '">'
            . '<div class="flex-1 min-w-0"><div class="text-[13px] font-medium text-ink truncate">' . esc($r['template_name']) . ' <span class="text-muted font-normal">for ' . esc($r['requester_name'] ?? '?') . '</span></div>'
            . '<div class="text-[11.5px] text-muted truncate">' . esc($when) . '</div></div>'
            . '<span class="w-[170px] text-[12.5px] text-ink-500" title="' . esc($r['next_run_at'], 'attr') . ' UTC">' . th_date($r['next_run_at']) . ' <span class="text-faint">(' . th_rel($r['next_run_at']) . ')</span></span>'
            . '<span class="w-[120px] text-[12.5px] text-muted">' . ($r['last_run_at'] ? th_rel($r['last_run_at']) : '—') . '</span>'
            . '<span class="w-[130px] flex items-center justify-end gap-1.5">' . th_toggle((bool) $r['active'], site_url('app/admin/recurring/' . $r['id'] . '/toggle'))
            . th_btn('', 'data-modal="editRecurring-' . $r['id'] . '" title="Edit"', 'ghost', 'edit')
            . '<form method="post" action="' . site_url('app/admin/recurring/' . $r['id'] . '/delete') . '" data-confirm="Stop raising this ticket?" data-confirm-label="Remove" data-confirm-title="Remove this schedule?">' . csrf_field()
            . '<button type="submit" class="w-8 h-8 grid place-items-center rounded-lg text-faint hover:text-alert hover:bg-alert-50" title="Remove">' . th_icon('trash', 'w-3.5 h-3.5') . '</button></form></span>'
            . '</div>';
    }, $recurringRows))
    : '<div class="px-4 py-6 text-[13px] text-muted">No schedules. A schedule raises a ticket from a template on a cadence — weekly backup checks, monthly patch windows.</div>')
) ?>
</div>

<datalist id="th-tz-list">
  <?php foreach (['UTC', 'America/Toronto', 'America/Vancouver', 'America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles', 'Europe/London', 'Europe/Paris', 'Europe/Berlin', 'Asia/Kolkata', 'Asia/Singapore', 'Australia/Sydney'] as $z): ?>
  <option value="<?= $z ?>"><?php endforeach ?>
</datalist>

<template id="tpl-addTemplate">
  <form method="post" action="<?= site_url('app/admin/ticket-templates') ?>" data-modal-title="New ticket template" data-modal-width="max-w-2xl" data-submit="Add template">
    <?= csrf_field() ?><?= $tplFields() ?>
  </form>
</template>
<?php foreach ($tplRows as $t): ?>
<template id="tpl-editTemplate-<?= $t['id'] ?>">
  <form method="post" action="<?= site_url('app/admin/ticket-templates/' . $t['id']) ?>" data-modal-title="Edit template" data-modal-width="max-w-2xl" data-submit="Save">
    <?= csrf_field() ?><?= $tplFields($t) ?>
  </form>
</template>
<?php endforeach ?>

<template id="tpl-addRecurring">
  <form method="post" action="<?= site_url('app/admin/recurring') ?>" data-modal-title="New recurring ticket" data-modal-sub="Raised by the cron from the template, on the cadence below" data-modal-width="max-w-2xl" data-submit="Add schedule">
    <?= csrf_field() ?><?= $recFields() ?>
  </form>
</template>
<?php foreach ($recurringRows as $r): ?>
<template id="tpl-editRecurring-<?= $r['id'] ?>">
  <form method="post" action="<?= site_url('app/admin/recurring/' . $r['id']) ?>" data-modal-title="Edit schedule" data-modal-sub="The next run is recomputed from now" data-modal-width="max-w-2xl" data-submit="Save">
    <?= csrf_field() ?><?= $recFields($r) ?>
  </form>
</template>
<?php endforeach ?>
<?php endif ?>
