<?= $this->extend('layouts/agent') ?>
<?= $this->section('content') ?>

<?php
$requester = $users[(int) $t['requester_id']] ?? null;
$s   = th_sla($t);
$grp = null;
foreach ($groups as $g) {
    if ((int) $g['id'] === (int) $t['group_id']) {
        $grp = $g;
    }
}
$tags = json_decode($t['tags'] ?? '[]', true) ?: [];
$open = th_is_open($t);
$base = site_url('app/tickets/' . $t['code']);

$propForm = static function (string $field, string $value, array $opts) use ($base) {
    $html = '<form method="post" action="' . $base . '/update">' . csrf_field()
        . '<input type="hidden" name="field" value="' . $field . '">'
        . '<select name="value" data-autosubmit class="w-full h-8 rounded-lg border border-line bg-white text-[12.5px] text-ink pl-2.5">';
    foreach ($opts as $o) {
        [$v, $l] = is_array($o) ? $o : [$o, $o];
        $html .= '<option value="' . esc($v, 'attr') . '"' . ((string) $v === (string) $value ? ' selected' : '') . '>' . esc($l) . '</option>';
    }

    return $html . '</select></form>';
};
?>
<div class="fade-in">
  <div class="bg-white border-b border-line px-5 py-3.5 sticky top-0 z-20">
    <div class="max-w-[1400px] mx-auto">
      <div class="flex items-center gap-2 text-[12px] text-muted mb-2">
        <a href="<?= site_url('app/tickets') ?>" class="inline-flex items-center gap-1 hover:text-ink"><?= th_icon('back', 'w-3.5 h-3.5') ?> Tickets</a>
        <span class="text-line">/</span><span class="font-mono"><?= esc($t['code']) ?></span>
      </div>
      <div class="flex flex-wrap items-center gap-3">
        <i class="led <?= TH_PRIORITY[$t['priority']]['dot'] ?>"></i>
        <h1 class="font-display text-[19px] font-semibold text-ink flex-1 min-w-[240px] flex items-center gap-2"><?= esc($t['subject']) ?>
          <button data-modal="editSubject" class="text-faint hover:text-ink shrink-0" title="Edit subject"><?= th_icon('edit', 'w-4 h-4') ?></button></h1>
        <?= th_status_chip($t['status']) ?>
        <div class="flex items-center gap-2">
          <?php if ($open): ?>
          <form method="post" action="<?= $base ?>/resolve"><?= csrf_field() ?><?= th_btn('Resolve', 'type="submit"', 'brand', 'check') ?></form>
          <?php else: ?>
          <form method="post" action="<?= $base ?>/reopen"><?= csrf_field() ?><?= th_btn('Reopen', 'type="submit"', 'ghost', 'refresh') ?></form>
          <?php endif ?>
          <form method="post" action="<?= $base ?>/escalate"><?= csrf_field() ?><?= th_btn('Escalate', 'type="submit"', 'ghost', 'warn') ?></form>
          <?= th_btn('Merge', 'data-fetch-modal="' . site_url('app/tickets/' . $t['code'] . '/merge-search') . '"', 'ghost', 'link') ?>
          <form method="post" action="<?= $base ?>/delete" data-confirm="<?= esc($t['code'] . ' and its ' . count($messages) . ' messages will be removed. Requesters are not notified.', 'attr') ?>" data-confirm-title="Delete this ticket?" data-confirm-label="Delete">
            <?= csrf_field() ?><?= th_btn('Delete', 'type="submit"', 'danger', 'trash') ?>
          </form>
        </div>
      </div>
      <div class="flex flex-wrap items-center gap-x-4 gap-y-1 mt-2 text-[12px] text-muted">
        <span>Opened <?= th_rel($t['created_at']) ?> by <?= esc($requester['name'] ?? '—') ?></span>
        <span>·</span><span>via <?= esc($t['source']) ?></span>
        <span>·</span><span><?= esc($grp['name'] ?? '—') ?></span>
        <span>·</span><span class="font-mono" style="color:<?= th_sla_color($t) ?>">SLA <?= th_sla_label($t) ?></span>
      </div>
    </div>
  </div>

  <div class="max-w-[1400px] mx-auto p-5 grid xl:grid-cols-[1fr_340px] gap-4 items-start">
    <div class="space-y-4 min-w-0">
      <?php if ($approval): ?>
      <!-- The request is gated: nothing should be worked until this is decided. -->
      <div class="bg-signal-50 border border-signal-100 rounded-xl p-4">
        <div class="flex flex-wrap items-start gap-3">
          <span class="w-8 h-8 rounded-lg bg-white border border-signal-100 grid place-items-center text-signal shrink-0"><?= th_icon('lock', 'w-4 h-4') ?></span>
          <div class="min-w-0 flex-1">
            <h2 class="font-display text-[14px] font-semibold text-ink">Awaiting <?= esc($approval['required_of']) ?> approval</h2>
            <p class="text-[12.5px] text-muted mt-0.5">
              Raised <?= th_rel($approval['created_at']) ?>. The resolution clock starts when this is approved.
            </p>
          </div>
          <?php if ($canDecide): ?>
          <div class="flex items-center gap-2">
            <form method="post" action="<?= $base ?>/approve" class="flex items-center gap-2"
                  data-confirm="The ticket opens, its SLA clock starts now, and <?= esc($requester['name'] ?? 'the requester', 'attr') ?> is emailed that the request was approved (the assignee is told in-app). Your note, if any, goes in the email."
                  data-confirm-label="Approve" data-confirm-title="Approve this request?">
              <?= csrf_field() ?>
              <input name="note" placeholder="Optional note" class="h-8 w-[150px] px-2.5 rounded-lg border border-line bg-white text-[12.5px] placeholder:text-faint">
              <button type="submit" class="inline-flex items-center gap-1.5 h-8 px-3 rounded-lg bg-brand hover:bg-brand-600 text-white text-[12.5px] font-semibold"><?= th_icon('check', 'w-3.5 h-3.5') ?>Approve</button>
            </form>
            <form method="post" action="<?= $base ?>/reject"
                  data-confirm="The ticket is closed and <?= esc($requester['name'] ?? 'the requester', 'attr') ?> is emailed that the request was not approved. Add a reason in the note field first if you want it included."
                  data-confirm-label="Reject" data-confirm-title="Reject this request?">
              <?= csrf_field() ?>
              <button type="submit" class="inline-flex items-center gap-1.5 h-8 px-3 rounded-lg border border-alert-100 bg-white text-alert text-[12.5px] font-medium hover:bg-alert-50"><?= th_icon('x', 'w-3.5 h-3.5') ?>Reject</button>
            </form>
          </div>
          <?php else: ?>
          <span class="text-[12px] text-muted">Supervisors and administrators can decide this.</span>
          <?php endif ?>
        </div>
      </div>
      <?php endif ?>

      <?php foreach ($approvalHistory as $ap): if ($ap['status'] === 'Pending') { continue; } ?>
      <div class="flex items-center gap-2.5 px-3.5 py-2.5 rounded-xl border <?= $ap['status'] === 'Approved' ? 'border-brand-100 bg-brand-50' : 'border-alert-100 bg-alert-50' ?>">
        <span class="<?= $ap['status'] === 'Approved' ? 'text-brand' : 'text-alert' ?>"><?= th_icon($ap['status'] === 'Approved' ? 'check' : 'x', 'w-4 h-4') ?></span>
        <span class="text-[12.5px] text-ink-500">
          <?= esc($ap['required_of']) ?> approval <?= strtolower($ap['status']) ?>
          by <?= esc($users[(int) $ap['decided_by']]['name'] ?? '—') ?> · <?= th_rel($ap['decided_at']) ?>
          <?= $ap['note'] ? '— ' . esc($ap['note']) : '' ?>
        </span>
      </div>
      <?php endforeach ?>

      <!-- conversation -->
      <div class="space-y-3">
        <?php foreach ($messages as $m):
          $by = $users[(int) $m['user_id']] ?? null;
          $isAgent = $by && in_array($by['role'], ['Administrator', 'Supervisor', 'Agent'], true);
          if ($m['kind'] === 'system'): ?>
          <div class="flex items-center gap-3 py-1.5"><span class="h-px flex-1 bg-line"></span>
            <span class="text-[11.5px] text-faint"><?= esc($m['body']) ?> · <?= th_rel($m['created_at']) ?></span><span class="h-px flex-1 bg-line"></span></div>
        <?php else: $note = $m['kind'] === 'note'; ?>
          <div class="flex gap-3">
            <div class="pt-0.5"><?= th_avatar($by, 32) ?></div>
            <div class="min-w-0 flex-1 rounded-xl border p-3.5 <?= $note ? 'bg-signal-50 border-signal-100' : 'bg-white border-line' ?>">
              <div class="flex items-center gap-2 mb-1.5">
                <span class="text-[13px] font-semibold text-ink"><?= esc($by['name'] ?? 'Unknown') ?></span>
                <span class="text-[11px] text-faint"><?= $isAgent ? esc($by['title'] ?? 'Agent') : 'Requester' ?></span>
                <?php if ($note): ?><span class="inline-flex items-center gap-1 h-[18px] px-1.5 rounded bg-signal-100 text-signal text-[10px] font-bold uppercase tracking-wide"><?= th_icon('lock', 'w-3 h-3') ?>Private note</span>
                <?php elseif ($m['kind'] === 'description'): ?><span class="text-[10px] font-bold uppercase tracking-wide text-faint">Original request</span><?php endif ?>
                <span class="ml-auto text-[11.5px] text-faint" title="<?= th_date($m['created_at']) ?>"><?= th_rel($m['created_at']) ?></span>
              </div>
              <div class="text-[13.5px] leading-relaxed text-ink-500 whitespace-pre-line"><?= esc($m['body']) ?></div>
              <?= th_att_chips($m['attachments']) ?>
            </div>
          </div>
        <?php endif; endforeach ?>
      </div>

      <!-- composer -->
      <section class="bg-white border border-line rounded-xl shadow-card">
        <form id="composerForm" method="post" action="<?= $base ?>/message" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <input type="hidden" name="kind" value="reply">
          <div class="flex items-center gap-1 p-2 border-b border-line">
            <?php /* Segmented control: the inactive tab is a ghost button (same tokens as th_btn),
                     the active one is filled. app.js swaps the whole set so hover never fights the fill. */ ?>
            <button type="button" data-composer-tab="reply" class="h-8 px-3 rounded-lg border text-[12.5px] font-medium transition bg-ink text-white border-ink">Reply to requester</button>
            <button type="button" data-composer-tab="note" class="h-8 px-3 rounded-lg border text-[12.5px] font-medium transition bg-white text-ink-500 border-line hover:bg-canvas">Private note</button>
            <div class="ml-auto flex items-center gap-2">
              <select data-canned class="h-8 rounded-lg border border-line bg-white text-[12px] text-muted pl-2.5">
                <option value="">Canned response…</option>
                <?php foreach ($cannedList as $c): ?>
                <option value="<?= $c['id'] ?>" data-body="<?= esc($c['body'], 'attr') ?>"><?= esc($c['title']) ?></option>
                <?php endforeach ?>
              </select>
            </div>
          </div>
          <div class="p-3">
            <textarea id="composer" name="body" rows="5"
              data-ph-reply="Write to <?= esc(explode(' ', $requester['name'] ?? 'the requester')[0], 'attr') ?>…"
              data-ph-note="Visible to agents only — record what you found, tried, or ruled out."
              placeholder="Write to <?= esc(explode(' ', $requester['name'] ?? 'the requester')[0], 'attr') ?>…"
              class="w-full text-[13.5px] leading-relaxed resize-y border-0 focus:ring-0 outline-none placeholder:text-faint bg-transparent"></textarea>
            <div class="flex flex-wrap items-center gap-2 pt-2 border-t border-line">
              <label class="inline-flex items-center gap-1.5 h-8 px-2.5 rounded-lg border border-line text-[12.5px] text-muted hover:bg-canvas cursor-pointer">
                <?= th_icon('clip', 'w-3.5 h-3.5') ?>Attach<input type="file" name="files[]" multiple class="hidden" onchange="this.parentNode.querySelector('span').textContent = this.files.length + ' file(s)'"><span></span></label>
              <label class="inline-flex items-center gap-1.5 text-[12.5px] text-muted ml-1"><input type="checkbox" name="resolve_on_send" value="1" class="w-[15px] h-[15px] rounded border-line"> Resolve on send</label>
              <div class="flex-1"></div>
              <span class="text-[11.5px] text-faint hidden sm:block">Ctrl ↵ to send</span>
              <button type="submit" id="composerSend" class="inline-flex items-center gap-1.5 h-8 px-3 rounded-lg text-white text-[12.5px] font-semibold bg-brand hover:bg-brand-600">
                <?= th_icon('send', 'w-3.5 h-3.5') ?><span id="composerSendLabel">Send reply</span></button>
            </div>
          </div>
        </form>
      </section>
    </div>

    <div class="space-y-4">
      <?= th_card(th_card_head('Properties')
          . th_prop_row('Status', $propForm('status', $t['status'], array_keys(TH_STATUS)))
          . th_prop_row('Priority', $propForm('priority', $t['priority'], array_keys(TH_PRIORITY)))
          . th_prop_row('Type', $propForm('type', $t['type'], ['Incident', 'Service request']))
          . th_prop_row('Group', $propForm('group', (string) $t['group_id'], array_map(static fn ($g) => [$g['id'], $g['name']], $groups)))
          . th_prop_row('Assignee', $propForm('agent', (string) ($t['agent_id'] ?? ''), array_merge([['', 'Unassigned']], array_map(static fn ($a) => [$a['id'], $a['name']], $assignableAgents))))
          . th_prop_row('Category', $propForm('category', $t['category'], TH_CATEGORIES))
          . th_prop_row('Problem', $propForm('problem', (string) ($t['problem_id'] ?? ''), array_merge([['', 'None']], array_map(static fn ($p) => [$p['id'], $p['code'] . ' — ' . mb_substr($p['title'], 0, 34)], $problems))))
          . (! empty($hasChanges)
              ? th_prop_row('Change', $propForm('change', (string) ($t['change_id'] ?? ''), array_merge([['', 'None']], array_map(static fn ($c) => [$c['id'], $c['code'] . ' — ' . mb_substr($c['title'], 0, 34)], $changes ?? []))))
              : '')) ?>

      <?php
        // Custom fields: editable in place. Fields not shown to agents but holding
        // a value (portal-only ones) are listed read-only underneath.
        $agentFields = array_values(array_filter($ticketFields ?? [], static fn ($f) => (int) $f['agents'] === 1));
        $shownIds    = array_map(static fn ($f) => (int) $f['id'], $agentFields);
        $readOnly    = '';
        foreach ($ticketFields ?? [] as $f) {
            if (! in_array((int) $f['id'], $shownIds, true) && isset($fieldValueMap[(int) $f['id']])) {
                $readOnly .= '<div class="flex gap-2 text-[12.5px]"><span class="text-muted w-[110px] shrink-0">' . esc($f['label']) . '</span>'
                    . '<span class="text-ink-500 min-w-0 break-words">' . esc($fieldValueMap[(int) $f['id']]) . '</span></div>';
            }
        }
        if ($agentFields || $readOnly) {
            $inputs = '';
            foreach ($agentFields as $f) {
                $inputs .= th_custom_field($f, $fieldValueMap[(int) $f['id']] ?? null);
            }
            echo th_card(th_card_head('Details')
                . '<div class="p-3.5 space-y-3">'
                . ($agentFields
                    ? '<form method="post" action="' . $base . '/fields" class="space-y-3">' . csrf_field() . $inputs
                        . '<div class="flex justify-end"><button type="submit" class="h-8 px-3 rounded-lg bg-ink hover:bg-ink-700 text-white text-[12.5px] font-semibold">Save details</button></div></form>'
                    : '')
                . $readOnly . '</div>');
        }

        // SLA panel
        $frDue = strtotime($t['fr_due']);
        $created = strtotime($t['created_at']);
        $responded = $t['responded_at'] ? strtotime($t['responded_at']) : null;
        if ($responded) {
            $fr = $responded <= $frDue ? 'met' : 'missed';
            $frLabel = $fr === 'met' ? 'Met ' . th_dur($frDue - $responded) . ' early' : 'Missed by ' . th_dur($responded - $frDue);
            $frPct = 100;
        } else {
            $fr = time() > $frDue ? 'breached' : 'ok';
            $frLabel = $fr === 'breached' ? 'Over by ' . th_dur(time() - $frDue) : 'Due in ' . th_dur($frDue - time());
            $frPct = min(100, ((time() - $created) / max(1, $frDue - $created)) * 100);
        }
        echo th_card(th_card_head('Service level')
            . '<div class="p-3.5 space-y-3.5">'
            . '<div><div class="flex items-baseline justify-between"><span class="text-[12px] text-muted">First response</span>'
            . '<span class="font-mono text-[12px] tick" style="color:' . TH_SLA_COLOR[$fr] . '">' . $frLabel . '</span></div>'
            . '<div class="burn mt-1.5 rounded-full"><i style="width:' . $frPct . '%;background:' . TH_SLA_COLOR[$fr] . '"></i></div></div>'
            . '<div><div class="flex items-baseline justify-between"><span class="text-[12px] text-muted">Resolution</span>'
            . '<span class="font-mono text-[12px] tick font-medium" style="color:' . th_sla_color($t) . '">' . th_sla_label($t) . '</span></div>'
            . '<div class="burn mt-1.5 rounded-full"><i style="width:' . $s['pct'] . '%;background:' . th_sla_color($t) . '"></i></div>'
            . '<div class="text-[11px] text-faint mt-1.5">Target ' . th_date($t['res_due']) . ' · ' . esc($slaName) . '</div></div>'
            . '</div>');
      ?>

      <?= th_card(th_card_head('Requester')
          . '<div class="p-3.5">'
          . '<div class="flex items-center gap-2.5">' . th_avatar($requester, 36)
          . '<div class="min-w-0"><div class="text-[13.5px] font-semibold text-ink truncate">' . esc($requester['name'] ?? '—') . '</div>'
          . '<div class="text-[12px] text-muted truncate">' . esc(($requester['title'] ?? '') . ' · ' . ($requester['dept'] ?? '')) . '</div></div></div>'
          . '<dl class="mt-3 space-y-1.5 text-[12px]">'
          . '<div class="flex gap-2"><dt class="text-faint w-[52px]">Email</dt><dd class="text-ink-500 truncate">' . esc($requester['email'] ?? '—') . '</dd></div>'
          . '<div class="flex gap-2"><dt class="text-faint w-[52px]">Phone</dt><dd class="text-ink-500 font-mono">' . esc($requester['phone'] ?? '—') . '</dd></div>'
          . '<div class="flex gap-2"><dt class="text-faint w-[52px]">Site</dt><dd class="text-ink-500">' . esc($requester['site'] ?? '—') . '</dd></div></dl>'
          . '<button data-fetch-modal="' . site_url('app/users/' . $t['requester_id'] . '/history') . '" class="mt-3 w-full h-8 rounded-lg border border-line text-[12.5px] text-ink-500 hover:bg-canvas">'
          . $requesterCount . ' tickets from this person</button></div>') ?>

      <?php
        $tasksHtml = '';
        foreach ($tasks as $task) {
            $owner = $task['owner_id'] ? ($users[(int) $task['owner_id']] ?? null) : null;
            $tasksHtml .= '<div class="flex items-start gap-2.5 group">'
                . '<form method="post" action="' . $base . '/tasks/' . $task['id'] . '/toggle" class="flex items-start gap-2.5 min-w-0 flex-1">' . csrf_field()
                . '<input type="checkbox" data-autosubmit ' . ($task['done'] ? 'checked' : '') . ' class="w-[15px] h-[15px] rounded border-line mt-0.5 cursor-pointer">'
                . '<span class="text-[12.5px] leading-snug ' . ($task['done'] ? 'line-through text-faint' : 'text-ink-500') . '">' . esc($task['title']) . '</span>'
                . '</form>'
                . ($owner ? th_avatar($owner, 20, 'shrink-0') : '')
                . '<form method="post" action="' . $base . '/tasks/' . $task['id'] . '/delete" class="shrink-0">' . csrf_field()
                . '<button type="submit" class="w-6 h-6 grid place-items-center rounded-md text-faint opacity-0 group-hover:opacity-100 hover:text-alert hover:bg-alert-50" title="Remove task">' . th_icon('x', 'w-3 h-3') . '</button></form>'
                . '</div>';
        }
        if (! $tasksHtml) {
            $tasksHtml = '<p class="text-[12.5px] text-muted">No tasks yet. Break the work down if more than one person is involved.</p>';
        }
        echo th_card(th_card_head('Tasks', th_btn('Add', 'data-modal="addTask"', 'ghost', 'plus')) . '<div class="p-3.5 space-y-2">' . $tasksHtml . '</div>');

        $assetsHtml = '';
        foreach ($linkedAssets as $a) {
            $assetsHtml .= '<button data-fetch-modal="' . site_url('app/assets/' . $a['id'] . '/modal') . '" class="w-full flex items-center gap-2.5 p-2 rounded-lg border border-line hover:bg-canvas text-left">'
                . '<span class="text-faint">' . th_icon(TH_ASSET_ICON[$a['type']] ?? 'server', 'w-4 h-4') . '</span>'
                . '<span class="min-w-0"><span class="block text-[12.5px] font-medium text-ink truncate">' . esc($a['name']) . '</span>'
                . '<span class="block font-mono text-[11px] text-faint">' . esc($a['tag'] . ' · ' . $a['model']) . '</span></span></button>';
        }
        if (! $assetsHtml) {
            $assetsHtml = '<p class="text-[12.5px] text-muted">No assets linked.</p>';
        }
        echo th_card(th_card_head('Linked assets', th_btn('Link', 'data-modal="linkAsset"', 'ghost', 'link')) . '<div class="p-3.5 space-y-2">' . $assetsHtml . '</div>');

        $linkHtml = '';
        foreach ($links as $l) {
            $linkHtml .= '<div class="flex items-center gap-2">'
                . '<i class="led ' . (TH_PRIORITY[$l['priority']]['dot'] ?? 'bg-[#98A1B0]') . '"></i>'
                . '<a href="' . site_url('app/tickets/' . $l['code']) . '" class="min-w-0 flex-1 hover:text-brand">'
                . '<span class="block text-[11px] text-faint">' . esc($l['label']) . '</span>'
                . '<span class="flex items-center gap-1.5"><span class="font-mono text-[11px] text-faint">' . esc($l['code']) . '</span>'
                . '<span class="text-[12.5px] text-ink truncate">' . esc($l['subject']) . '</span></span></a>'
                . '<form method="post" action="' . $base . '/links/' . $l['id'] . '/remove">' . csrf_field()
                . '<button type="submit" class="w-6 h-6 grid place-items-center rounded-md text-faint hover:text-alert hover:bg-alert-50" title="Unlink">'
                . th_icon('x', 'w-3 h-3') . '</button></form>'
                . '</div>';
        }
        if (! $linkHtml) {
            $linkHtml = '<p class="text-[12.5px] text-muted">Nothing linked. Use this for duplicates and dependencies you do not want merged away.</p>';
        }
        echo th_card(th_card_head('Linked tickets' . ($links ? ' · ' . count($links) : ''), th_btn('Link', 'data-modal="linkTicket"', 'ghost', 'link'))
            . '<div class="p-3.5 space-y-2.5">' . $linkHtml . '</div>');

        $timeHtml = '';
        foreach ($timeEntries as $e) {
            $mine = (int) $e['user_id'] === (int) $me['id'];
            $timeHtml .= '<div class="flex items-start gap-2">'
                . '<span class="font-mono text-[12px] text-ink w-[58px] shrink-0">' . th_minutes((int) $e['minutes']) . '</span>'
                . '<span class="min-w-0 flex-1"><span class="block text-[12px] text-ink-500 truncate">' . esc($e['who']) . '</span>'
                . '<span class="block text-[11px] text-faint">' . th_day($e['spent_on'])
                . ($e['note'] ? ' · ' . esc($e['note']) : '') . '</span></span>'
                . ($mine || $isAdmin
                    ? '<form method="post" action="' . $base . '/time/' . $e['id'] . '/delete">' . csrf_field()
                        . '<button type="submit" class="w-6 h-6 grid place-items-center rounded-md text-faint hover:text-alert hover:bg-alert-50" title="Remove">'
                        . th_icon('x', 'w-3 h-3') . '</button></form>'
                    : '')
                . '</div>';
        }
        if (! $timeHtml) {
            $timeHtml = '<p class="text-[12.5px] text-muted">No time logged yet.</p>';
        }
        echo th_card(
            th_card_head('Time logged', '<span class="font-mono text-[12.5px] ' . ($timeTotal ? 'text-ink' : 'text-faint') . '">' . th_minutes((int) $timeTotal) . '</span>')
            . '<div class="p-3.5 space-y-2.5">'
            . '<form method="post" action="' . $base . '/time" class="flex gap-1.5">' . csrf_field()
            . '<input name="spent" placeholder="45m, 1h30" required class="w-[74px] h-8 px-2 rounded-lg border border-line text-[12.5px] placeholder:text-faint focus:border-brand">'
            . '<input name="note" placeholder="What on?" class="flex-1 min-w-0 h-8 px-2 rounded-lg border border-line text-[12.5px] placeholder:text-faint focus:border-brand">'
            . '<input type="hidden" name="spent_on" value="' . date('Y-m-d') . '">'
            . '<button type="submit" class="h-8 px-2.5 rounded-lg bg-ink hover:bg-ink-700 text-white text-[12.5px] font-semibold shrink-0">Log</button></form>'
            . $timeHtml . '</div>'
        );

        $watchHtml = '';
        foreach ($watchers as $w) {
            $watchHtml .= '<div class="flex items-center gap-2.5">'
                . th_avatar($w, 24)
                . '<span class="min-w-0 flex-1"><span class="block text-[12.5px] text-ink truncate">' . esc($w['name']) . '</span>'
                . '<span class="block text-[11px] text-faint truncate">' . esc($w['email']) . '</span></span>'
                . '<form method="post" action="' . $base . '/watchers/' . $w['id'] . '/remove">' . csrf_field()
                . '<button type="submit" class="w-7 h-7 grid place-items-center rounded-md text-faint hover:text-alert hover:bg-alert-50" title="Stop copying ' . esc($w['name'], 'attr') . '">' . th_icon('x', 'w-3.5 h-3.5') . '</button></form>'
                . '</div>';
        }
        if (! $watchHtml) {
            $watchHtml = '<p class="text-[12.5px] text-muted">Nobody else is copied. Add a manager or a colleague who needs to follow this.</p>';
        }
        echo th_card(th_card_head('Watchers' . ($watchers ? ' · ' . count($watchers) : ''), th_btn('Add', 'data-modal="addWatcher"', 'ghost', 'plus'))
            . '<div class="p-3.5 space-y-2.5">' . $watchHtml . '</div>');

        $tagsHtml = '';
        foreach ($tags as $tag) {
            // Pill with an inline remove: posts the tag back to the remove endpoint.
            $tagsHtml .= '<form method="post" action="' . $base . '/tags/remove" class="inline-flex items-center h-[20px] rounded bg-[#EFF1F5] text-[11px] text-muted font-mono pl-1.5">' . csrf_field()
                . '<input type="hidden" name="tag" value="' . esc($tag, 'attr') . '">' . esc($tag)
                . '<button type="submit" class="w-5 h-full grid place-items-center rounded-r text-faint hover:text-alert hover:bg-alert-50" title="Remove tag ' . esc($tag, 'attr') . '">' . th_icon('x', 'w-2.5 h-2.5') . '</button></form>';
        }
        $tagsHtml = $tagsHtml ?: '<span class="text-[12.5px] text-muted">No tags.</span>';
        echo th_card(th_card_head('Tags', th_btn('Add', 'data-modal="addTag"', 'ghost', 'plus')) . '<div class="p-3.5 flex flex-wrap gap-1.5">' . $tagsHtml . '</div>');
      ?>
    </div>
  </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('modals') ?>
<template id="tpl-editSubject">
  <form method="post" action="<?= $base ?>/update" data-modal-title="Edit subject" data-submit="Save">
    <?= csrf_field() ?>
    <input type="hidden" name="field" value="subject">
    <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Subject<span class="text-alert"> *</span></label>
      <input name="value" required value="<?= esc($t['subject'], 'attr') ?>" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
  </form>
</template>

<template id="tpl-addTask">
  <form method="post" action="<?= $base ?>/tasks" data-modal-title="Add task" data-submit="Add task">
    <?= csrf_field() ?>
    <div class="grid gap-3.5">
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">What needs doing?<span class="text-alert"> *</span></label>
        <input name="title" required placeholder="e.g. Order replacement fuser" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Owner</label>
        <select name="owner_id" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px]">
          <option value="">Nobody yet</option>
          <?php foreach ($assignableAgents as $a): ?><option value="<?= $a['id'] ?>"><?= esc($a['name']) ?></option><?php endforeach ?>
        </select></div>
    </div>
  </form>
</template>

<template id="tpl-linkTicket">
  <form method="post" action="<?= $base ?>/links" data-modal-title="Link a ticket" data-modal-sub="For duplicates and dependencies you do not want merged" data-submit="Link">
    <?= csrf_field() ?>
    <div class="grid sm:grid-cols-2 gap-3.5">
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Relationship</label>
        <select name="kind" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px]">
          <option value="related">is related to</option>
          <option value="duplicate">is a duplicate of</option>
          <option value="blocks">blocks</option>
        </select></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Ticket code<span class="text-alert"> *</span></label>
        <input name="code" required placeholder="INC-2093" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] font-mono focus:border-brand"></div>
    </div>
    <p class="text-[11.5px] text-faint mt-2">Both tickets keep their own conversation &mdash; unlike merging, nothing is absorbed.</p>
  </form>
</template>

<template id="tpl-addWatcher">
  <form method="post" action="<?= $base ?>/watchers" data-modal-title="Copy someone in" data-modal-sub="Watchers get every notification this ticket sends" data-submit="Add watcher">
    <?= csrf_field() ?>
    <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Person<span class="text-alert"> *</span></label>
      <select name="user_id" required class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px]">
        <option value="">Choose someone…</option>
        <?php
          // Everyone active except the requester, who is already notified.
          $watching = array_column($watchers, 'id');
          foreach ($users as $u):
            if (! (int) $u['active'] || (int) $u['id'] === (int) $t['requester_id'] || in_array($u['id'], $watching)) {
                continue;
            }
        ?>
        <option value="<?= $u['id'] ?>"><?= esc($u['name']) ?><?= $u['dept'] ? ' — ' . esc($u['dept']) : '' ?></option>
        <?php endforeach ?>
      </select>
      <p class="text-[11.5px] text-faint mt-1">They receive replies and resolution notices. They are not given access to anything else.</p></div>
  </form>
</template>

<template id="tpl-addTag">
  <form method="post" action="<?= $base ?>/tags" data-modal-title="Add tag" data-submit="Add tag">
    <?= csrf_field() ?>
    <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Tag<span class="text-alert"> *</span></label>
      <input name="tag" required placeholder="lowercase, no spaces" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
  </form>
</template>

<template id="tpl-linkAsset">
  <form method="post" action="<?= $base ?>/assets" data-modal-title="Link an asset" data-submit="Link asset">
    <?= csrf_field() ?>
    <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Asset</label>
      <select name="asset_id" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px]">
        <?php foreach ($allAssets as $a): ?><option value="<?= $a['id'] ?>"><?= esc($a['name']) ?> — <?= esc($a['tag']) ?></option><?php endforeach ?>
      </select></div>
  </form>
</template>

<?= $this->endSection() ?>
