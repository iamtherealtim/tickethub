<?php
/** @var array $endpoints @var array $deliveries @var bool $webhooksReady @var ?string $freshSecret @var string $inputCls */
use App\Libraries\Webhooks;

$epFields = static function (array $ep = []) use ($inputCls) {
    $subscribed = json_decode($ep['events'] ?? '[]', true) ?: [];
    $all = in_array('*', $subscribed, true);
    $html = '<div class="grid gap-3.5">'
        . '<div class="grid sm:grid-cols-2 gap-3.5">'
        . '<div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Name<span class="text-alert"> *</span></label>'
        . '<input name="name" required value="' . esc($ep['name'] ?? '', 'attr') . '" placeholder="e.g. Slack bridge" class="' . $inputCls . '"></div>'
        . '<div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">URL<span class="text-alert"> *</span></label>'
        . '<input name="url" type="url" required value="' . esc($ep['url'] ?? '', 'attr') . '" placeholder="https://example.com/hooks/tickethub" class="' . $inputCls . ' font-mono text-[12px]"></div>'
        . '</div>'
        . '<div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Events</label>'
        . '<div class="grid sm:grid-cols-2 gap-x-4 gap-y-1.5 rounded-lg border border-line bg-canvas p-3">'
        . '<label class="sm:col-span-2 inline-flex items-center gap-2 text-[12.5px] font-semibold text-ink"><input type="checkbox" name="events[]" value="*" ' . ($all ? 'checked' : '') . ' class="w-[15px] h-[15px] rounded border-line"> All events</label>';
    foreach (Webhooks::EVENTS as $k => $label) {
        $html .= '<label class="inline-flex items-center gap-2 text-[12.5px] text-ink-500"><input type="checkbox" name="events[]" value="' . $k . '" ' . (in_array($k, $subscribed, true) ? 'checked' : '') . ' class="w-[15px] h-[15px] rounded border-line"> <span class="font-mono text-[11.5px] text-faint">' . $k . '</span> ' . esc($label) . '</label>';
    }
    $html .= '</div></div>';
    if ($ep) {
        $html .= '<label class="inline-flex items-center gap-2 text-[12.5px] text-ink-500"><input type="checkbox" name="rotate_secret" value="1" class="w-[15px] h-[15px] rounded border-line"> Rotate the signing secret (shown once after saving)</label>';
    }

    return $html . '</div>';
};
?>
<?php if (! $webhooksReady): ?>
<?= th_card('<div class="p-4 text-[13px] text-muted">Run <code class="font-mono">php spark migrate</code> to create the webhook tables.</div>') ?>
<?php else: ?>
<?php
$rows = '';
foreach ($endpoints as $ep) {
    $events = json_decode($ep['events'] ?? '[]', true) ?: [];
    $streak = (int) $ep['failures'];
    $rows .= '<div class="flex flex-wrap items-center gap-3 px-4 py-3 border-b border-line last:border-0">'
        . '<div class="flex-1 min-w-0"><div class="text-[13px] font-medium ' . ((int) $ep['active'] ? 'text-ink' : 'text-faint') . '">' . esc($ep['name'])
        . ($streak >= Webhooks::DISABLE_AFTER ? ' <span class="inline-flex items-center gap-1 h-[20px] px-1.5 rounded border text-[11px] font-semibold bg-alert-50 text-alert border-alert-100">auto-disabled</span>' : '') . '</div>'
        . '<div class="font-mono text-[11.5px] text-faint truncate">' . esc($ep['url']) . '</div>'
        . '<div class="text-[11.5px] text-muted mt-0.5">' . (in_array('*', $events, true) ? 'All events' : esc(implode(', ', $events))) . '</div></div>'
        . '<span class="w-[150px] text-[12px] text-muted">' . ($ep['last_delivered_at'] ? 'delivered ' . th_rel($ep['last_delivered_at']) : 'never delivered')
        . ($ep['last_status'] !== null ? ' · <span class="font-mono ' . ((int) $ep['last_status'] >= 200 && (int) $ep['last_status'] < 300 ? 'text-brand' : 'text-alert') . '">' . (int) $ep['last_status'] . '</span>' : '')
        . ($streak ? ' · <span class="text-alert">' . $streak . ' failing</span>' : '') . '</span>'
        . '<form method="post" action="' . site_url('app/admin/webhooks/' . $ep['id'] . '/test') . '" class="inline">' . csrf_field() . th_btn('Send test', 'type="submit"', 'ghost', 'zap') . '</form>'
        . th_btn('Edit', 'data-modal="editWebhook-' . $ep['id'] . '"', 'ghost', 'edit')
        . th_toggle((bool) $ep['active'], site_url('app/admin/webhooks/' . $ep['id'] . '/toggle'))
        . '</div>';
}
if (! $rows) {
    $rows = th_empty('send', 'No webhooks yet', 'Push ticket events to Slack, Teams, a SIEM or your own service, signed with a per-endpoint secret.');
}
echo th_card(
    th_card_head('Outbound webhooks', th_btn('New webhook', 'data-modal="addWebhook"', 'brand', 'plus'))
    . ($freshSecret
        ? '<div class="px-4 py-3 bg-brand-50 border-b border-brand-100">'
          . '<div class="text-[12px] font-semibold text-brand mb-1">Signing secret — copy it now, it is not shown again</div>'
          . '<code class="block px-2.5 py-2 rounded-lg bg-white border border-brand-100 font-mono text-[12px] text-ink break-all">' . esc($freshSecret) . '</code>'
          . '<div class="text-[11.5px] text-muted mt-1.5">Verify each request with <code class="font-mono">X-TicketHub-Signature: sha256=HMAC_SHA256(secret, raw body)</code>.</div></div>'
        : '')
    . '<div class="px-4 py-2.5 bg-canvas border-b border-line text-[12px] text-muted">JSON <code class="font-mono">{event, occurred_at, data}</code> is POSTed with a 5 s timeout. Failures retry after 1m, 5m, 30m, 2h and 12h via <code class="font-mono">php spark tickethub:webhooks</code>; an endpoint that fails ' . Webhooks::DISABLE_AFTER . ' times in a row is switched off.</div>'
    . $rows
);

$dRows = '';
foreach ($deliveries as $d) {
    $ok = $d['delivered_at'] !== null;
    $pending = ! $ok && $d['next_attempt_at'] !== null;
    $chip = $ok
        ? '<span class="inline-flex items-center h-[18px] px-1.5 rounded bg-brand-50 text-brand text-[10px] font-bold uppercase tracking-wide">Delivered</span>'
        : ($pending
            ? '<span class="inline-flex items-center h-[18px] px-1.5 rounded bg-signal-50 text-signal text-[10px] font-bold uppercase tracking-wide">Retrying</span>'
            : '<span class="inline-flex items-center h-[18px] px-1.5 rounded bg-alert-50 text-alert text-[10px] font-bold uppercase tracking-wide">Failed</span>');
    $dRows .= '<div class="flex flex-wrap md:flex-nowrap items-center gap-3 px-4 py-2 border-b border-line last:border-0">'
        . '<span class="w-[130px] font-mono text-[11.5px] text-muted shrink-0">' . th_date($d['created_at']) . '</span>'
        . '<span class="shrink-0 w-[76px]">' . $chip . '</span>'
        . '<span class="font-mono text-[11.5px] text-ink shrink-0 w-[150px] truncate">' . esc($d['event']) . '</span>'
        . '<span class="text-[12.5px] text-ink-500 truncate max-w-[180px]">' . esc($d['endpoint_name']) . '</span>'
        . '<span class="font-mono text-[11.5px] shrink-0 w-[44px] ' . ($ok ? 'text-brand' : 'text-alert') . '">' . ($d['status'] !== null ? (int) $d['status'] : '—') . '</span>'
        . '<span class="flex-1 min-w-0 text-[11.5px] text-muted truncate" title="' . esc((string) $d['response_excerpt'], 'attr') . '">' . esc(mb_substr((string) $d['response_excerpt'], 0, 90)) . '</span>'
        . '<span class="text-[11px] text-faint shrink-0">' . (int) $d['attempts'] . ' attempt' . ((int) $d['attempts'] === 1 ? '' : 's') . ($pending ? ' · next ' . th_rel($d['next_attempt_at']) : '') . '</span>'
        . ($ok ? '' : '<form method="post" action="' . site_url('app/admin/webhooks/deliveries/' . $d['id'] . '/retry') . '" class="inline">' . csrf_field() . th_btn('Retry', 'type="submit"', 'ghost', 'refresh') . '</form>')
        . '</div>';
}
if (! $dRows) {
    $dRows = th_empty('send', 'Nothing delivered yet', 'Deliveries appear here as tickets change — or use "Send test".');
}
echo '<div class="mt-3">' . th_card(th_card_head('Recent deliveries', '<span class="text-muted">Latest 40</span>') . $dRows) . '</div>';
?>

<template id="tpl-addWebhook">
  <form method="post" action="<?= site_url('app/admin/webhooks') ?>" data-modal-title="New webhook" data-modal-width="max-w-[50.4rem]" data-submit="Create webhook">
    <?= csrf_field() ?>
    <?= $epFields() ?>
  </form>
</template>

<?php foreach ($endpoints as $ep): ?>
<template id="tpl-editWebhook-<?= $ep['id'] ?>">
  <div data-modal-title="Edit <?= esc($ep['name'], 'attr') ?>" data-modal-width="max-w-[50.4rem]" data-submit="Save">
    <form method="post" action="<?= site_url('app/admin/webhooks/' . $ep['id']) ?>" data-primary>
      <?= csrf_field() ?>
      <?= $epFields($ep) ?>
    </form>
    <form method="post" action="<?= site_url('app/admin/webhooks/' . $ep['id'] . '/delete') ?>" class="mt-3 pt-1"
          data-confirm="Delete &ldquo;<?= esc($ep['name'], 'attr') ?>&rdquo; and its delivery log?" data-confirm-label="Delete" data-confirm-title="Delete this webhook?">
      <?= csrf_field() ?>
      <button type="submit" class="inline-flex items-center gap-1.5 h-8 px-2.5 rounded-lg border border-alert-100 bg-white text-[12.5px] font-medium text-alert hover:bg-alert-50"><?= th_icon('trash', 'w-3.5 h-3.5') ?>Delete</button>
    </form>
  </div>
</template>
<?php endforeach ?>
<?php endif ?>
