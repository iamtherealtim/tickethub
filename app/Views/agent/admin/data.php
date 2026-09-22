<?php
/** @var array $retentionPlan @var array $retentionSettings @var array $retentionTotals @var string $inputCls */
$s = $retentionSettings;
$p = $retentionPlan;
$num = static fn ($n) => '<span class="font-mono">' . number_format((int) $n) . '</span>';
$field = static function (string $name, string $label, int $value, string $help) use ($inputCls) {
    return '<div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">' . $label . '</label>'
        . '<input type="number" min="0" max="3650" name="' . $name . '" value="' . $value . '" class="' . $inputCls . ' font-mono">'
        . '<p class="text-[11.5px] text-faint mt-1">' . $help . '</p></div>';
};
?>
<form method="post" action="<?= site_url('app/admin/data') ?>">
  <?= csrf_field() ?>
  <?= th_card(
      th_card_head('Retention policy', '<span class="text-muted">0 = keep forever</span>')
      . '<div class="p-4 grid sm:grid-cols-2 gap-3.5">'
      . $field('retention_closed_days', 'Closed tickets — purge after (days)', $s['closed_days'], 'Closed tickets not touched for this long are deleted with their messages, tasks, time, links, watchers, approvals, custom values, notifications and automation history.')
      . '<label class="flex items-center gap-2.5 rounded-lg border border-line bg-canvas p-3 cursor-pointer self-end">'
      . '<input type="checkbox" name="retention_purge_attachments" value="1" ' . ($s['purge_attachments'] ? 'checked' : '') . ' class="w-[15px] h-[15px] rounded border-line">'
      . '<span class="text-[13px] font-medium text-ink">Also delete their attachment files</span></label>'
      . $field('retention_audit_days', 'Audit log — purge after (days)', $s['audit_days'], 'Sign-ins, admin changes and rule runs older than this.')
      . $field('retention_webhook_delivery_days', 'Webhook deliveries — purge after (days)', $s['webhook_days'], 'Delivery log rows (payloads and responses).')
      . $field('retention_notification_days', 'Read notifications — purge after (days)', $s['notification_days'], 'Only notifications that have been read; unread ones stay.')
      . '</div>'
      . '<div class="flex items-center gap-2 px-4 py-3.5 border-t border-line bg-canvas rounded-b-xl">'
      . '<span class="text-[12px] text-muted">Applied by <code class="font-mono text-[11.5px] bg-white border border-line rounded px-1.5 py-0.5">php spark tickethub:retention</code> (add <code class="font-mono">--dry-run</code> to preview). Schedule it daily. Orphaned upload files older than 7 days are always swept.</span>'
      . '<div class="flex-1"></div>'
      . '<button type="submit" class="h-9 px-3.5 rounded-lg bg-brand hover:bg-brand-600 text-white text-[13px] font-semibold">Save</button>'
      . '</div>'
  ) ?>
</form>

<div class="mt-3">
<?php if (isset($p['error'])): ?>
<?= th_card('<div class="p-4 text-[13px] text-alert">Could not compute the preview: ' . esc($p['error']) . '</div>') ?>
<?php else: ?>
<?php
$row = static function (string $label, string $count, string $sub) {
    return '<div class="flex flex-wrap items-center gap-3 px-4 py-2.5 border-b border-line last:border-0">'
        . '<span class="flex-1 text-[13px] text-ink">' . $label . '</span>'
        . '<span class="text-[12px] text-muted">' . $sub . '</span>'
        . '<span class="w-[90px] text-right text-[13px] font-semibold text-ink">' . $count . '</span></div>';
};
echo th_card(
    th_card_head('What the next run would purge', '<span class="text-muted">Same numbers as <code class="font-mono">--dry-run</code></span>')
    . $row('Closed tickets', $num($p['tickets']), $s['closed_days'] ? 'of ' . number_format($retentionTotals['closed']) . ' closed · ' . number_format($p['messages']) . ' messages · ' . number_format($p['attachment_files']) . ' attachment file(s), ' . number_format($p['attachment_bytes'] / 1024, 1) . ' KB' . ($s['purge_attachments'] ? '' : ' (files kept)') : 'retention off')
    . $row('Audit log rows', $num($p['audit_rows']), $s['audit_days'] ? 'of ' . number_format($retentionTotals['audit']) : 'retention off')
    . $row('Webhook deliveries', $num($p['webhook_deliveries']), $s['webhook_days'] ? 'of ' . number_format($retentionTotals['webhook']) : 'retention off')
    . $row('Read notifications', $num($p['notifications']), $s['notification_days'] ? 'of ' . number_format($retentionTotals['notif']) . ' read' : 'retention off')
    . $row('Orphaned upload files', $num(count($p['orphan_files'])), 'unreferenced for 7+ days · ' . number_format($p['orphan_bytes'] / 1024, 1) . ' KB')
);
?>
<?php endif ?>
</div>

<?= th_card('<div class="p-4 text-[12.5px] text-ink-500 leading-relaxed">'
    . '<div class="text-[11px] font-semibold uppercase tracking-[.09em] text-faint mb-1.5">Per-person data</div>'
    . 'Under <a href="' . site_url('app/admin/people') . '" class="font-semibold underline">People</a>, each person\'s edit dialog offers <b>Export data</b> (a JSON file with their profile, the tickets they requested with messages and attachment lists, KB votes and time entries) and <b>Anonymize</b> (name, email, contact details and sign-in are erased and the account deactivated; tickets are kept under &ldquo;Deleted user #id&rdquo;). Both are recorded in the audit log.'
    . '</div>', 'mt-3') ?>
