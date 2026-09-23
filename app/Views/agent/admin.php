<?= $this->extend('layouts/agent') ?>
<?= $this->section('content') ?>

<?php
$tabs = [
    ['agents', 'Agents & roles', 'users'], ['people', 'People', 'user'], ['groups', 'Groups', 'layers'],
    ['routing', 'Routing', 'branch'], ['sla', 'SLA policies', 'clock'], ['hours', 'Business hours', 'clock'],
    ['rules', 'Automations', 'zap'], ['fields', 'Ticket fields', 'note'], ['email', 'Email templates', 'mail'],
    ['mail', 'Email settings', 'send'], ['sso', 'Single sign-on', 'shield'], ['integrations', 'Integrations', 'link'],
    ['audit', 'Audit log', 'book'],
];
// Plug-in tabs: app/Views/agent/admin/<id>.tab.php returns ['label', 'icon', 'order' (int, tabs above are 10..130)].
$pluginTabs = [];
foreach (glob(APPPATH . 'Views/agent/admin/*.tab.php') ?: [] as $tabFile) {
    $meta = include $tabFile;
    $pluginTabs[] = [basename($tabFile, '.tab.php'), $meta['label'] ?? basename($tabFile, '.tab.php'), $meta['icon'] ?? 'link', (int) ($meta['order'] ?? 200)];
}
if ($pluginTabs) {
    $i = 0;
    $tabs = array_map(static function ($t) use (&$i) { $i += 10; return [$t[0], $t[1], $t[2], $i]; }, $tabs);
    $tabs = array_merge($tabs, $pluginTabs);
    usort($tabs, static fn ($a, $b) => $a[3] <=> $b[3]);
}
$groupsById = [];
foreach ($groups as $g) {
    $groupsById[(int) $g['id']] = $g;
}
$opLabels = ['contains' => 'contains', 'not_contains' => 'does not contain', 'equals' => 'is', 'not_equals' => 'is not', 'starts_with' => 'starts with', 'gte' => '≥'];
$fieldLabels = [
    'subject' => 'Subject', 'description' => 'Description', 'category' => 'Category', 'priority' => 'Priority',
    'status' => 'Status', 'type' => 'Type', 'source' => 'Source', 'group' => 'Group', 'tag' => 'Tags',
    'requester_email' => 'Requester email', 'hours_since_created' => 'Hours since created',
    'hours_since_updated' => 'Hours since last update', 'hours_since_resolved' => 'Hours since resolved', 'sla_pct' => '% of SLA used',
];
$inputCls = 'w-full h-9 px-2.5 rounded-lg border border-line bg-white text-[13px] placeholder:text-faint focus:border-brand';
$hoursById = [];
foreach ($hours as $h) {
    $hoursById[(int) $h['id']] = $h;
}
$orgsById = [];
foreach ($orgs ?? [] as $o) {
    $orgsById[(int) $o['id']] = $o;
}
// Organization <select> for the people forms; empty until the migration has run.
$orgSelect = static function (?int $current) use ($orgs) {
    $html = '<select name="org_id" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px]"><option value="">— None —</option>';
    foreach ($orgs ?? [] as $o) {
        $html .= '<option value="' . (int) $o['id'] . '"' . ((int) $o['id'] === (int) $current ? ' selected' : '') . '>' . esc($o['name']) . '</option>';
    }

    return $html . '</select>';
};
?>
<div class="p-5 max-w-[1400px] mx-auto fade-in">
  <div class="mb-5">
    <h1 class="font-display text-[22px] font-semibold text-ink">Admin</h1>
    <p class="text-[13px] text-muted mt-1">Configuration for the whole service desk</p>
  </div>
  <?php if (! \App\Libraries\Settings::encryptionAvailable()): ?>
  <div class="mb-4 rounded-xl border border-alert/40 bg-alert/5 px-4 py-3 text-[13px] text-ink flex items-start gap-2.5">
    <span class="text-alert mt-0.5"><?= th_icon('shield', 'w-4 h-4') ?></span>
    <div><b>Set encryption.key in .env to encrypt stored secrets.</b>
      <span class="text-muted">SMTP password, Entra client secret, PDQ API key and the inbound webhook secret are currently saved in plaintext. Run <code class="font-mono text-[12px]">php spark key:generate</code>, then re-save each secret.</span></div>
  </div>
  <?php elseif ($plaintextSecrets = \App\Libraries\Settings::plaintextSecrets()): ?>
  <div class="mb-4 rounded-xl border border-line bg-canvas px-4 py-3 text-[13px] text-muted">
    Encryption is on, but these were saved before the key existed and are still plaintext: <span class="font-mono text-[12px]"><?= esc(implode(', ', $plaintextSecrets)) ?></span>. Re-save them to encrypt.
  </div>
  <?php endif ?>

  <div class="grid lg:grid-cols-[210px_1fr] gap-4 items-start">
    <section class="bg-white border border-line rounded-xl shadow-card">
      <div class="p-2">
        <?php foreach ($tabs as $tabDef): [$id, $label, $ic] = $tabDef; $on = $tab === $id; ?>
        <a href="<?= site_url('app/admin/' . $id) ?>" class="w-full flex items-center gap-2.5 h-9 px-2.5 rounded-lg text-[12.5px] font-medium transition
          <?= $on ? 'bg-ink text-white' : 'text-muted hover:bg-canvas hover:text-ink' ?>">
          <span class="<?= $on ? 'text-brand-100' : 'text-faint' ?>"><?= th_icon($ic, 'w-4 h-4') ?></span><?= $label ?></a>
        <?php endforeach ?>
      </div>
    </section>

    <div>
      <?php if ($tab === 'agents'): ?>
      <?= th_card(
          th_card_head('Agents & roles', th_btn('Invite agent', 'data-modal="addAgent"', 'brand', 'plus'))
          . th_table_head([['Agent', 'flex-1'], ['Role', 'w-[130px]'], ['Group', 'w-[160px]'], ['Tickets', 'w-[70px] text-right'], ['Active', 'w-[60px] text-right']])
          . implode('', array_map(function ($a) use ($openByAgent, $groups) {
              $gname = '—';
              foreach ($groups as $g) {
                  if ((int) $g['id'] === (int) $a['group_id']) {
                      $gname = $g['name'];
                  }
              }

              return '<div class="flex flex-wrap md:flex-nowrap items-center gap-3 px-4 py-2.5 border-b border-line last:border-0">'
                  . '<span class="flex items-center gap-2.5 flex-1 min-w-0">' . th_avatar($a, 30)
                  . '<span class="min-w-0"><span class="block text-[13px] font-medium text-ink truncate">' . esc($a['name']) . '</span>'
                  . '<span class="block text-[11.5px] text-faint truncate">' . esc($a['email']) . '</span></span></span>'
                  . '<span class="w-[130px] text-[12.5px] ' . ($a['role'] === 'Administrator' ? 'text-brand font-medium' : 'text-muted') . '">' . esc($a['role']) . '</span>'
                  . '<span class="w-[160px] text-[12.5px] text-muted truncate">' . esc($gname) . '</span>'
                  . '<span class="w-[70px] text-right font-mono text-[12px] text-muted">' . ($openByAgent[(int) $a['id']] ?? 0) . '</span>'
                  . th_btn('Edit', 'data-modal="editAgent-' . $a['id'] . '"', 'ghost', 'edit')
                  . '<span class="w-[60px] flex justify-end">' . th_toggle((bool) $a['active'], site_url('app/admin/toggle/agent/' . $a['id'])) . '</span>'
                  . '</div>';
          }, $agents))
      ) ?>

      <?php elseif ($tab === 'people'): ?>
      <?= th_card(
          th_card_head('People (requesters)', th_btn('Add person', 'data-modal="addPerson"', 'brand', 'plus'))
          . th_table_head([['Person', 'flex-1'], ['Organization', 'w-[140px]'], ['Department', 'w-[120px]'], ['Site', 'w-[110px]'], ['Phone', 'w-[140px]'], ['Active', 'w-[130px] text-right']])
          . implode('', array_map(static function ($u) use ($orgsById) {
              return '<div class="flex flex-wrap md:flex-nowrap items-center gap-3 px-4 py-2.5 border-b border-line last:border-0">'
                  . '<span class="flex items-center gap-2.5 flex-1 min-w-0">' . th_avatar($u, 30)
                  . '<span class="min-w-0"><span class="block text-[13px] font-medium text-ink truncate">' . esc($u['name']) . '</span>'
                  . '<span class="block text-[11.5px] text-faint truncate">' . esc($u['email']) . '</span></span></span>'
                  . '<span class="w-[140px] text-[12.5px] text-muted truncate">' . esc($orgsById[(int) ($u['org_id'] ?? 0)]['name'] ?? '—') . '</span>'
                  . '<span class="w-[120px] text-[12.5px] text-muted truncate">' . esc($u['dept'] ?? '—') . '</span>'
                  . '<span class="w-[110px] text-[12.5px] text-muted truncate">' . esc($u['site'] ?? '—') . '</span>'
                  . '<span class="w-[140px] font-mono text-[11.5px] text-muted truncate">' . esc($u['phone'] ?? '—') . '</span>'
                  . '<span class="w-[130px] flex items-center justify-end gap-2">'
                  . th_btn('Edit', 'data-modal="editPerson-' . $u['id'] . '"', 'ghost', 'edit')
                  . th_toggle((bool) $u['active'], site_url('app/admin/toggle/agent/' . $u['id'])) . '</span>'
                  . '</div>';
          }, $requesters))
      ) ?>

      <?php elseif ($tab === 'groups'): ?>
      <?= th_card(
          th_card_head('Groups', th_btn('New group', 'data-modal="addGroup"', 'brand', 'plus'))
          . implode('', array_map(function ($g) use ($groupMembers, $hoursById, $openByGroup) {
              $members = $groupMembers[(int) $g['id']] ?? [];
              $avatars = implode('', array_map(static fn ($m) => th_avatar($m, 26, 'ring-2 ring-white'), $members));
              $open    = $openByGroup[(int) $g['id']] ?? 0;
              // A queue with nobody active still collects routed tickets, and its
              // SLA warnings have no one to reach. Say so where groups are managed.
              $unstaffed = ! $members
                  ? '<span class="inline-flex items-center gap-1 h-[20px] px-1.5 rounded-sm border text-[11px] font-semibold bg-alert-50 text-alert border-alert-100" title="No active agent belongs to this group">' . th_icon('warn', 'w-3 h-3') . 'Unstaffed</span>'
                  : '';

              return '<div class="flex flex-wrap items-center gap-3 px-4 py-3 border-b border-line last:border-0">'
                  . '<div class="min-w-0 flex-1"><div class="flex items-center gap-2 text-[13px] font-medium text-ink">' . esc($g['name']) . $unstaffed . '</div>'
                  . '<div class="text-[12px] text-muted">' . esc($g['description']) . '</div></div>'
                  . '<div class="flex -space-x-1.5">' . ($avatars ?: '<span class="text-[12px] text-faint italic">no active agents</span>') . '</div>'
                  . '<span class="text-[12px] text-muted w-[170px]">' . esc($hoursById[(int) $g['hours_id']]['name'] ?? '—') . '</span>'
                  . '<span class="font-mono text-[12px] w-[60px] text-right ' . ($unstaffed && $open ? 'text-alert font-semibold' : 'text-muted') . '">' . $open . ' open</span>'
                  . th_btn('Edit', 'data-modal="editGroup-' . $g['id'] . '"', 'ghost', 'edit')
                  . '</div>';
          }, $groups))
      ) ?>

      <?php elseif ($tab === 'routing'): ?>
      <?= th_card(
          th_card_head('Routing rules', th_btn('New rule', 'data-modal="addRoute"', 'brand', 'plus'))
          . '<div class="px-4 py-2.5 bg-canvas border-b border-line text-[12px] text-muted">Applied in order, first match wins — to portal, email, and catalog tickets that arrive without an explicit team. Agents can still pick a team by hand.</div>'
          . th_table_head([['#', 'w-[36px]'], ['When', 'flex-1'], ['Route to', 'w-[180px]'], ['Sets', 'w-[150px]'], ['On', 'w-[130px] text-right']])
          . (count($routes) === 0 ? th_empty('branch', 'No routing rules', 'Everything falls back to the default team below.')
              : implode('', array_map(static function ($r) use ($groupsById, $users, $groupMembers) {
                  $sets = [];
                  if ($r['priority']) {
                      $sets[] = 'priority ' . $r['priority'];
                  }
                  if ($r['agent_id']) {
                      $sets[] = 'assign ' . explode(' ', $users[(int) $r['agent_id']]['name'] ?? '?')[0];
                  }

                  return '<div class="flex flex-wrap md:flex-nowrap items-center gap-3 px-4 py-2.5 border-b border-line last:border-0">'
                      . '<span class="w-[36px] font-mono text-[11.5px] text-faint">' . (int) $r['position'] . '</span>'
                      . '<span class="flex-1 min-w-0 text-[13px] text-ink"><span class="font-mono text-[11px] text-faint uppercase">' . esc($r['match_type']) . '</span> '
                      . '<span class="font-medium">&ldquo;' . esc($r['match_value']) . '&rdquo;</span></span>'
                      // Routing into a queue with nobody active silently strands tickets.
                      . '<span class="w-[180px] text-[12.5px] truncate ' . (empty($groupMembers[(int) $r['group_id']]) ? 'text-alert font-medium' : 'text-ink-500') . '">'
                      . esc($groupsById[(int) $r['group_id']]['name'] ?? '?')
                      . (empty($groupMembers[(int) $r['group_id']]) ? ' <span title="This team has no active agent">' . th_icon('warn', 'w-3 h-3 inline -mt-0.5') . '</span>' : '')
                      . '</span>'
                      . '<span class="w-[150px] text-[12px] text-muted truncate">' . ($sets ? esc(implode(' · ', $sets)) : '—') . '</span>'
                      . '<span class="w-[130px] flex items-center justify-end gap-2">'
                      . th_btn('Edit', 'data-modal="editRoute-' . $r['id'] . '"', 'ghost', 'edit')
                      . th_toggle((bool) $r['active'], site_url('app/admin/toggle/route/' . $r['id'])) . '</span>'
                      . '</div>';
              }, $routes)))
      ) ?>
      <form method="post" action="<?= site_url('app/admin/routing/default') ?>" class="mt-3">
        <?= csrf_field() ?>
        <?= th_card('<div class="flex flex-wrap items-center gap-3 p-4">'
            . '<span class="w-8 h-8 rounded-lg bg-canvas border border-line grid place-items-center text-muted">' . th_icon('inbox', 'w-4 h-4') . '</span>'
            . '<div class="flex-1 min-w-[220px]"><div class="text-[13px] font-medium text-ink">Fallback team</div>'
            . '<div class="text-[12px] text-muted">Where a ticket lands when no rule matches.</div></div>'
            . th_select('default_group_id', $settings['default_group_id'] ?? '1', array_map(static fn ($g) => [$g['id'], $g['name']], $groups))
            . '<button type="submit" class="h-8 px-3 rounded-lg bg-brand hover:bg-brand-600 text-white text-[12.5px] font-semibold">Save</button>'
            . '</div>'
            . '<div class="flex flex-wrap items-center gap-3 p-4 border-t border-line">'
            . '<span class="w-8 h-8 rounded-lg bg-canvas border border-line grid place-items-center text-muted">' . th_icon('users', 'w-4 h-4') . '</span>'
            . '<div class="flex-1 min-w-[220px]"><div class="text-[13px] font-medium text-ink">Balance new tickets across the team</div>'
            . '<div class="text-[12px] text-muted">When a rule names a team but not a person, give the ticket to whoever has fewest open. Off means it stays unassigned.</div></div>'
            . '<label class="inline-flex items-center gap-2 text-[12.5px] text-ink-500">'
            . '<input type="checkbox" name="auto_assign" value="1" ' . (($settings['auto_assign'] ?? '0') === '1' ? 'checked' : '') . ' class="w-[15px] h-[15px] rounded-sm border-line">'
            . 'Enabled</label>'
            . '</div>') ?>
      </form>

      <?php elseif ($tab === 'sla'): ?>
      <?= th_card(
          th_card_head('SLA policies', th_btn('New policy', 'data-modal="addSla"', 'brand', 'plus'))
          . th_table_head([['Policy', 'flex-1'], ['First response', 'w-[120px]'], ['Resolution', 'w-[110px]'], ['Clock', 'w-[130px]'], ['On', 'w-[60px] text-right']])
          . implode('', array_map(static function ($s) {
              return '<div class="flex flex-wrap md:flex-nowrap items-center gap-3 px-4 py-2.5 border-b border-line last:border-0">'
                  . '<div class="flex-1 min-w-0"><div class="text-[13px] font-medium text-ink">' . esc($s['name']) . '</div>'
                  . '<div class="text-[11.5px] text-faint">' . esc($s['escalation']) . '</div></div>'
                  . '<span class="w-[120px] font-mono text-[12.5px] text-ink-500">' . esc($s['first_response']) . '</span>'
                  . '<span class="w-[110px] font-mono text-[12.5px] text-ink-500">' . esc($s['resolution']) . '</span>'
                  . '<span class="w-[130px] text-[12.5px] text-muted">' . esc($s['hours']) . '</span>'
                  . th_btn('Edit', 'data-modal="editSla-' . $s['id'] . '"', 'ghost', 'edit')
                  . '<span class="w-[60px] flex justify-end">' . th_toggle((bool) $s['active'], site_url('app/admin/toggle/sla/' . $s['id'])) . '</span>'
                  . '</div>';
          }, $slas))
      ) ?>

      <?php elseif ($tab === 'hours'): ?>
      <?= th_card(
          th_card_head('Business hours', th_btn('Add calendar', 'data-modal="addHours"', 'brand', 'plus'))
          . implode('', array_map(static function ($h) {
              return '<div class="flex flex-wrap items-center gap-3 px-4 py-3 border-b border-line last:border-0">'
                  . '<div class="flex-1 min-w-0"><div class="text-[13px] font-medium text-ink">' . esc($h['name']) . '</div>'
                  . '<div class="text-[12px] text-muted font-mono">' . esc($h['tz']) . '</div></div>'
                  . '<span class="text-[12.5px] text-muted w-[110px]">' . esc($h['days']) . '</span>'
                  . '<span class="font-mono text-[12.5px] text-ink-500 w-[130px]">' . esc($h['time_range']) . '</span>'
                  . '<span class="text-[12.5px] text-muted w-[160px]">' . esc($h['holidays']) . '</span>'
                  . th_btn('Edit', 'data-modal="editHours-' . $h['id'] . '"', 'ghost', 'edit')
                  . '</div>';
          }, $hours))
      ) ?>
      <form method="post" action="<?= site_url('app/admin/timezone') ?>" class="mt-3">
        <?= csrf_field() ?>
        <?= th_card('<div class="flex flex-wrap items-center gap-3 p-4">'
            . '<span class="w-8 h-8 rounded-lg bg-canvas border border-line grid place-items-center text-muted">' . th_icon('clock', 'w-4 h-4') . '</span>'
            . '<div class="flex-1 min-w-[220px]"><div class="text-[13px] font-medium text-ink">Display timezone</div>'
            . '<div class="text-[12px] text-muted">Dates and times across the workspace and portal. Storage stays UTC.</div></div>'
            . th_select('app_timezone', $settings['app_timezone'] ?? 'UTC', array_map(static fn ($z) => [$z, $z],
                ['UTC', 'America/Toronto', 'America/Vancouver', 'America/Winnipeg', 'America/Halifax', 'America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles', 'Europe/London', 'Europe/Amsterdam', 'Europe/Paris', 'Asia/Tokyo', 'Australia/Sydney']))
            . '<button type="submit" class="h-8 px-3 rounded-lg bg-brand hover:bg-brand-600 text-white text-[12.5px] font-semibold">Save</button>'
            . '</div>') ?>
      </form>
      <form method="post" action="<?= site_url('app/admin/locale') ?>" class="mt-3">
        <?= csrf_field() ?>
        <?= th_card('<div class="flex flex-wrap items-center gap-3 p-4">'
            . '<span class="w-8 h-8 rounded-lg bg-canvas border border-line grid place-items-center text-muted">' . th_icon('tz', 'w-4 h-4') . '</span>'
            . '<div class="flex-1 min-w-[220px]"><div class="text-[13px] font-medium text-ink">Default language</div>'
            . '<div class="text-[12px] text-muted">Used for visitors whose browser language is not supported. People can switch from their account menu.</div></div>'
            . th_select('app_locale', $settings['app_locale'] ?? 'en', [['en', 'English'], ['fr', 'Français']])
            . '<button type="submit" class="h-8 px-3 rounded-lg bg-brand hover:bg-brand-600 text-white text-[12.5px] font-semibold">Save</button>'
            . '</div>') ?>
      </form>

      <?php elseif ($tab === 'rules'): ?>
      <form method="post" action="<?= site_url('app/admin/rules/run') ?>" class="mb-3">
        <?= csrf_field() ?>
        <?= th_card('<div class="flex flex-wrap items-center gap-3 p-4">'
            . '<span class="w-8 h-8 rounded-lg bg-canvas border border-line grid place-items-center text-signal">' . th_icon('play', 'w-4 h-4') . '</span>'
            . '<div class="flex-1 min-w-[220px]"><div class="text-[13px] font-medium text-ink">Rule runner</div>'
            . '<div class="text-[12px] text-muted">Schedule <code class="font-mono text-[11.5px] bg-canvas border border-line rounded-sm px-1.5 py-0.5">php spark tickets:cron</code> every 5–15 minutes (Task Scheduler or cron). The button runs the same pass immediately.</div></div>'
            . th_btn('Run now', 'type="submit"', 'brand', 'zap')
            . '</div>') ?>
      </form>
      <?php
        $groupName = static fn ($id) => $groupsById[(int) $id]['name'] ?? '?';
        $agentName = static fn ($id) => $users[(int) $id]['name'] ?? '?';
        $condSummary = static function ($r) use ($opLabels, $fieldLabels) {
            $parts = array_map(
                static fn ($c) => ($fieldLabels[$c['field']] ?? $c['field']) . ' ' . ($opLabels[$c['op']] ?? $c['op']) . ' “' . $c['value'] . '”',
                json_decode($r['conditions'] ?? '[]', true) ?: []
            );

            return $parts ? implode(' · ', array_map('esc', $parts)) : 'always';
        };
        $templateName = static function ($id) use ($templates) {
            foreach ($templates as $t) {
                if ((int) $t['id'] === (int) $id) {
                    return $t['name'];
                }
            }

            return '?';
        };
        $actSummary = static function ($r) use ($groupName, $agentName, $templateName) {
            $parts = [];
            foreach (json_decode($r['actions'] ?? '[]', true) ?: [] as $a) {
                $parts[] = match ($a['type']) {
                    'set_status'      => 'set status ' . $a['value'],
                    'set_priority'    => 'set priority ' . $a['value'],
                    'move_group'      => 'move to ' . $groupName($a['value']),
                    'assign_agent'    => 'assign ' . $agentName($a['value']),
                    'add_tag'         => 'tag “' . $a['value'] . '”',
                    'escalate'        => 'escalate',
                    'add_note'        => 'add private note',
                    'email_requester' => 'email requester',
                    'email_agent'     => 'email assignee',
                    'email_template'  => 'send template “' . $templateName($a['value']) . '”',
                    default           => $a['type'],
                };
            }

            return implode(', ', array_map('esc', $parts));
        };
        echo th_card(
            th_card_head('Automations', th_btn('New rule', 'data-modal="addRule"', 'brand', 'plus'))
            . implode('', array_map(static function ($r) use ($condSummary, $actSummary) {
                return '<div class="flex flex-wrap items-start gap-3 px-4 py-3 border-b border-line last:border-0">'
                    . '<span class="w-7 h-7 rounded-lg bg-canvas border border-line grid place-items-center text-signal shrink-0 mt-0.5">' . th_icon('zap', 'w-3.5 h-3.5') . '</span>'
                    . '<div class="flex-1 min-w-0"><div class="text-[13px] font-medium text-ink">' . esc($r['name']) . '</div>'
                    . '<div class="text-[12px] text-muted mt-0.5 leading-relaxed">'
                    . '<span class="font-mono text-[11px] text-faint uppercase">when</span> ' . esc($r['when_event'])
                    . ' <span class="font-mono text-[11px] text-faint uppercase ml-1.5">if</span> ' . $condSummary($r)
                    . ' <span class="font-mono text-[11px] text-faint uppercase ml-1.5">then</span> <span class="text-ink-500 font-medium">' . $actSummary($r) . '</span></div></div>'
                    . '<span class="font-mono text-[11.5px] text-faint w-[90px] text-right">' . number_format((int) $r['runs']) . ' runs</span>'
                    . th_btn('Edit', 'data-modal="editRule-' . $r['id'] . '"', 'ghost', 'edit')
                    . th_toggle((bool) $r['active'], site_url('app/admin/toggle/rule/' . $r['id']))
                    . '</div>';
            }, $rules))
        );

        // Unified execution log — routing decisions and automation runs.
        $logRows = '';
        foreach ($autoLog as $row) {
            $chip = $row['kind'] === 'routing'
                ? '<span class="inline-flex items-center h-[18px] px-1.5 rounded-sm bg-violet-50 text-violet text-[10px] font-bold uppercase tracking-wide">Routing</span>'
                : '<span class="inline-flex items-center h-[18px] px-1.5 rounded-sm bg-signal-50 text-signal text-[10px] font-bold uppercase tracking-wide">Rule</span>';
            $logRows .= '<div class="flex flex-wrap md:flex-nowrap items-center gap-3 px-4 py-2 border-b border-line last:border-0">'
                . '<span class="w-[130px] font-mono text-[11.5px] text-muted shrink-0">' . th_date($row['created_at']) . '</span>'
                . '<span class="shrink-0 w-[64px]">' . $chip . '</span>'
                . '<a href="' . site_url('app/tickets/' . $row['ticket_code']) . '" class="font-mono text-[11.5px] text-brand hover:underline shrink-0 w-[76px]">' . esc($row['ticket_code']) . '</a>'
                . '<span class="text-[12.5px] text-ink font-medium truncate max-w-[260px]">' . esc($row['rule_name']) . '</span>'
                . '<span class="flex-1 min-w-0 text-[12.5px] text-muted truncate">→ ' . esc($row['summary']) . '</span>'
                . '<span class="text-[11px] text-faint shrink-0 hidden lg:block">' . esc($row['trigger_event']) . '</span>'
                . '</div>';
        }
        if (! $logRows) {
            $logRows = ($logFilters['log_kind'] ?? '') !== '' || ($logFilters['log_q'] ?? '') !== ''
                ? th_empty('zap', 'Nothing matches', 'Try a broader search or switch the type filter back to Everything.')
                : th_empty('zap', 'Nothing logged yet', 'Routing decisions and rule executions will appear here as tickets flow in.');
        }

        $logFilterBar = '<form method="get" action="' . site_url('app/admin/rules') . '" class="flex flex-wrap items-center gap-2 p-3 border-b border-line">'
            . '<div class="relative flex-1 min-w-[200px]">'
            . '<span class="absolute left-2.5 top-1/2 -translate-y-1/2 text-faint">' . th_icon('search', 'w-4 h-4') . '</span>'
            . '<input name="log_q" value="' . esc($logFilters['log_q'] ?? '', 'attr') . '" placeholder="Search ticket code, rule, or action"'
            . ' class="w-full h-8 pl-8 pr-3 rounded-lg border border-line bg-white text-[13px] placeholder:text-faint"></div>'
            . th_select('log_kind', $logFilters['log_kind'] ?? '', [['', 'Everything'], ['routing', 'Routing only'], ['automation', 'Rules only']], 'data-autosubmit')
            . th_select('log_sort', $logFilters['log_sort'] ?? 'desc', [['desc', 'Newest first'], ['asc', 'Oldest first']], 'data-autosubmit')
            . '</form>';

        echo '<div class="mt-3">' . th_card(
            th_card_head('Automation log', '<span class="text-muted"><span class="font-mono">' . $logTotal . '</span> event(s) — every routing decision and rule execution</span>')
            . $logFilterBar
            . $logRows
            . th_pager($logTotal, $logPage, $logPerPage, site_url('app/admin/rules'), array_filter($logFilters))
        ) . '</div>';
      ?>

      <?php elseif ($tab === 'fields'): ?>
      <?= th_card(
          th_card_head('Ticket fields', th_btn('Add field', 'data-modal="addField"', 'brand', 'plus'))
          . th_table_head([['Field', 'flex-1'], ['Type', 'w-[120px]'], ['Required', 'w-[90px]'], ['Agents', 'w-[80px]'], ['Portal', 'w-[80px]']])
          . implode('', array_map(static function ($f) {
              return '<div class="flex flex-wrap md:flex-nowrap items-center gap-3 px-4 py-2.5 border-b border-line last:border-0">'
                  . '<span class="flex-1 text-[13px] font-medium text-ink">' . esc($f['label']) . '</span>'
                  . '<span class="w-[120px] text-[12.5px] text-muted">' . esc($f['type']) . '</span>'
                  . '<span class="w-[90px] text-[12.5px] ' . ($f['required'] ? 'text-ink' : 'text-faint') . '">' . ($f['required'] ? 'Required' : 'Optional') . '</span>'
                  . '<span class="w-[80px] text-brand">' . ($f['agents'] ? th_icon('check', 'w-4 h-4') : '') . '</span>'
                  . '<span class="w-[80px] text-brand">' . ($f['portal'] ? th_icon('check', 'w-4 h-4') : '') . '</span>'
                  . th_btn('Edit', 'data-modal="editField-' . $f['id'] . '"', 'ghost', 'edit')
                  . '</div>';
          }, $fields))
      ) ?>

      <?php elseif ($tab === 'email'): ?>
      <?php if (! $mailConfigured): ?>
      <div class="mb-3 flex items-start gap-2.5 rounded-xl border border-signal-100 bg-signal-50 p-3.5 text-[13px] text-signal">
        <?= th_icon('warn', 'w-4 h-4 mt-0.5 shrink-0') ?>
        <span>Sending is off — these templates will not go anywhere until SMTP is configured under
        <a href="<?= site_url('app/admin/mail') ?>" class="font-semibold underline">Email settings</a>.</span>
      </div>
      <?php endif ?>
      <?= th_card(
          th_card_head('Email templates')
          . implode('', array_map(static function ($t) {
              return '<div class="flex flex-wrap items-center gap-3 px-4 py-3 border-b border-line last:border-0">'
                  . '<span class="w-7 h-7 rounded-lg bg-canvas border border-line grid place-items-center text-muted shrink-0">' . th_icon('mail', 'w-3.5 h-3.5') . '</span>'
                  . '<div class="flex-1 min-w-0"><div class="text-[13px] font-medium text-ink">' . esc($t['name']) . '</div>'
                  . '<div class="font-mono text-[11.5px] text-faint truncate">' . esc($t['subject']) . '</div></div>'
                  . '<span class="text-[12.5px] text-muted w-[150px]">' . esc($t['trigger_event']) . '</span>'
                  . '<span class="text-[12.5px] text-muted w-[90px]">To ' . esc($t['recipient']) . '</span>'
                  . th_btn('Edit', 'data-modal="editTemplate-' . $t['id'] . '"', 'ghost', 'edit')
                  . th_toggle((bool) $t['active'], site_url('app/admin/toggle/template/' . $t['id']))
                  . '</div>';
          }, $templates))
      ) ?>

      <?php elseif ($tab === 'mail'): ?>
      <form method="post" action="<?= site_url('app/admin/mail') ?>">
        <?= csrf_field() ?>
        <?= th_card(
            th_card_head('Outgoing email (SMTP)', '<span class="' . ($mailConfigured ? 'text-brand' : 'text-faint') . ' font-semibold">' . ($mailConfigured ? 'Sending is on' : 'Sending is off') . '</span>')
            . '<div class="p-4 grid sm:grid-cols-2 gap-3.5">'

            . '<label class="sm:col-span-2 flex items-center gap-2.5 rounded-lg border border-line bg-canvas p-3 cursor-pointer">'
            . '<input type="checkbox" name="mail_enabled" value="1" ' . (($settings['mail_enabled'] ?? '0') === '1' ? 'checked' : '') . ' class="w-[15px] h-[15px] rounded-sm border-line">'
            . '<span class="text-[13px] font-medium text-ink">Send notification emails</span>'
            . '<span class="text-[12px] text-muted">— ticket created, replies, resolution, assignment</span></label>'

            . '<div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">SMTP host</label>'
            . '<input name="mail_host" value="' . esc($settings['mail_host'] ?? '', 'attr') . '" placeholder="smtp.office365.com" class="' . $inputCls . '"></div>'
            . '<div class="grid grid-cols-2 gap-3.5">'
            . '<div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Port</label>'
            . '<input name="mail_port" value="' . esc($settings['mail_port'] ?? '587', 'attr') . '" class="' . $inputCls . '"></div>'
            . '<div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Encryption</label>'
            . '<select name="mail_encryption" class="' . $inputCls . '">'
            . '<option value="tls"' . (($settings['mail_encryption'] ?? 'tls') === 'tls' ? ' selected' : '') . '>STARTTLS</option>'
            . '<option value="ssl"' . (($settings['mail_encryption'] ?? '') === 'ssl' ? ' selected' : '') . '>SSL</option>'
            . '<option value="none"' . (($settings['mail_encryption'] ?? '') === 'none' ? ' selected' : '') . '>None</option>'
            . '</select></div></div>'

            . '<div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Username</label>'
            . '<input name="mail_username" value="' . esc($settings['mail_username'] ?? '', 'attr') . '" autocomplete="off" class="' . $inputCls . '"></div>'
            . '<div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Password</label>'
            . '<input name="mail_password" type="password" value="" placeholder="' . (($settings['mail_password'] ?? '') !== '' ? '•••••••• (saved — leave blank to keep)' : '') . '" autocomplete="new-password" class="' . $inputCls . '"></div>'

            . '<div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">From address</label>'
            . '<input name="mail_from_email" type="email" value="' . esc($settings['mail_from_email'] ?? '', 'attr') . '" class="' . $inputCls . '"></div>'
            . '<div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">From name</label>'
            . '<input name="mail_from_name" value="' . esc($settings['mail_from_name'] ?? '', 'attr') . '" class="' . $inputCls . '"></div>'

            . '</div>'
            . '<div class="flex items-center gap-2 px-4 py-3.5 border-t border-line bg-canvas rounded-b-xl">'
            . '<span class="text-[12px] text-muted">Failures are logged to writable/logs and never block ticket work.</span>'
            . '<div class="flex-1"></div>'
            . '<button type="submit" class="h-9 px-3.5 rounded-lg bg-brand hover:bg-brand-600 text-white text-[13px] font-semibold">Save settings</button>'
            . '</div>'
        ) ?>
      </form>
      <form method="post" action="<?= site_url('app/admin/mail/test') ?>" class="mt-3">
        <?= csrf_field() ?>
        <?= th_card('<div class="flex items-center gap-3 p-4">'
            . '<span class="w-8 h-8 rounded-lg bg-canvas border border-line grid place-items-center text-muted">' . th_icon('send', 'w-4 h-4') . '</span>'
            . '<div class="flex-1"><div class="text-[13px] font-medium text-ink">Send a test email</div>'
            . '<div class="text-[12px] text-muted">Delivers to your own address — ' . esc($me['email']) . '</div></div>'
            . th_btn('Send test', 'type="submit"', 'ghost', 'send')
            . '</div>') ?>
      </form>
      <?php
        $graphOn = ($settings['graph_inbound_enabled'] ?? '0') === '1';
        $ssoCredsPresent = ($settings['azure_tenant_id'] ?? '') !== '' && ($settings['azure_client_id'] ?? '') !== '' && ($settings['azure_client_secret'] ?? '') !== '';
      ?>
      <form method="post" action="<?= site_url('app/admin/graph') ?>" class="mt-3">
        <?= csrf_field() ?>
        <?= th_card(
            th_card_head('Microsoft 365 mailbox (Graph API)', '<span class="' . ($graphOn ? 'text-brand' : 'text-faint') . ' font-semibold">' . ($graphOn ? 'Enabled' : 'Disabled') . '</span>')
            . '<div class="p-4 space-y-3">'
            . '<label class="flex items-center gap-2.5 rounded-lg border border-line bg-canvas p-3 cursor-pointer">'
            . '<input type="checkbox" name="graph_inbound_enabled" value="1" ' . ($graphOn ? 'checked' : '') . ' class="w-[15px] h-[15px] rounded-sm border-line">'
            . '<span class="text-[13px] font-medium text-ink">Poll a shared mailbox and turn unread email into tickets</span></label>'
            . '<div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Mailbox address</label>'
            . '<input name="graph_mailbox" type="email" value="' . esc($settings['graph_mailbox'] ?? '', 'attr') . '" placeholder="servicedesk@yourcompany.com" class="' . $inputCls . '"></div>'
            . '<div class="rounded-lg border border-line bg-canvas p-3 text-[12.5px] text-ink-500 leading-relaxed">'
            . 'Uses the app registration from <a href="' . site_url('app/admin/sso') . '" class="font-semibold underline">Single sign-on</a>'
            . ($ssoCredsPresent ? ' <span class="text-brand font-medium">(credentials present)</span>' : ' <span class="text-alert font-medium">(tenant/client/secret not set yet)</span>') . '.'
            . '<ol class="list-decimal ml-4 mt-1.5 space-y-1">'
            . '<li>API permissions → add <b>Application</b> permission <code class="font-mono text-[11.5px] bg-white border border-line rounded-sm px-1.5 py-0.5">Mail.ReadWrite</code> → Grant admin consent.</li>'
            . '<li>Recommended: restrict the app to this mailbox with an Exchange <i>application access policy</i>.</li>'
            . '<li>Polling runs with <code class="font-mono text-[11.5px] bg-white border border-line rounded-sm px-1.5 py-0.5">php spark tickets:cron</code>; processed mail is marked read. Senders must match a TicketHub account; subjects with an INC-/SR- code append to that ticket.</li>'
            . '</ol></div>'
            . '<div class="flex items-center gap-2">'
            . '<button type="submit" class="h-9 px-3.5 rounded-lg bg-brand hover:bg-brand-600 text-white text-[13px] font-semibold">Save</button>'
            . '</div></div>'
        ) ?>
      </form>
      <form method="post" action="<?= site_url('app/admin/graph/fetch') ?>" class="mt-3">
        <?= csrf_field() ?>
        <?= th_card('<div class="flex items-center gap-3 p-4">'
            . '<span class="w-8 h-8 rounded-lg bg-canvas border border-line grid place-items-center text-muted">' . th_icon('refresh', 'w-4 h-4') . '</span>'
            . '<div class="flex-1"><div class="text-[13px] font-medium text-ink">Fetch now</div>'
            . '<div class="text-[12px] text-muted">Poll the mailbox once and report what happened.</div></div>'
            . th_btn('Fetch mail', 'type="submit"', 'ghost', 'refresh')
            . '</div>') ?>
      </form>

      <?php $inboundOn = ($settings['inbound_email_enabled'] ?? '0') === '1'; ?>
      <form method="post" action="<?= site_url('app/admin/inbound') ?>" class="mt-3">
        <?= csrf_field() ?>
        <?= th_card(
            th_card_head('Inbound email → tickets', '<span class="' . ($inboundOn ? 'text-brand' : 'text-faint') . ' font-semibold">' . ($inboundOn ? 'Enabled' : 'Disabled') . '</span>')
            . '<div class="p-4 space-y-3">'
            . '<label class="flex items-center gap-2.5 rounded-lg border border-line bg-canvas p-3 cursor-pointer">'
            . '<input type="checkbox" name="inbound_email_enabled" value="1" ' . ($inboundOn ? 'checked' : '') . ' class="w-[15px] h-[15px] rounded-sm border-line">'
            . '<span class="text-[13px] font-medium text-ink">Accept email through the inbound webhook</span></label>'
            . '<div class="rounded-lg border border-line bg-canvas p-3 text-[12.5px] text-ink-500 leading-relaxed">'
            . 'Point your mail provider\'s inbound parse (Mailgun routes, SendGrid inbound, Postmark) at:'
            . '<div class="mt-1.5"><code class="font-mono text-[11.5px] bg-white border border-line rounded-sm px-1.5 py-0.5 select-all">POST ' . site_url('api/inbound-email') . '</code></div>'
            . '<div class="mt-1.5">Header <code class="font-mono text-[11.5px] bg-white border border-line rounded-sm px-1.5 py-0.5">X-Inbound-Secret: ' . esc($settings['inbound_email_secret'] ?? '') . '</code></div>'
            . '<div class="mt-1.5">JSON body: <code class="font-mono text-[11.5px]">{"from","subject","text"}</code>. A subject containing an existing INC-/SR- code appends a reply (and reopens if needed); anything else opens a new incident for the matched sender.</div>'
            . '</div>'
            . '<div class="grid sm:grid-cols-2 gap-3">'
            . '<label class="block"><span class="block text-[12px] font-medium text-ink-500 mb-1">Unknown senders</span>'
            . th_select('inbound_unknown_policy', (string) ($settings['inbound_unknown_policy'] ?? 'create'), [['create', 'Create a requester account'], ['drop', 'Drop the message (logged)']]) . '</label>'
            . '<label class="block"><span class="block text-[12px] font-medium text-ink-500 mb-1">Reopen window (days)</span>'
            . '<input type="number" min="0" max="365" name="inbound_reopen_days" value="' . esc((string) ($settings['inbound_reopen_days'] ?? '5'), 'attr') . '" class="' . $inputCls . '">'
            . '<span class="block text-[11.5px] text-faint mt-1">Replies to tickets resolved longer ago than this open a new linked ticket.</span></label>'
            . '</div>'
            . '<div class="flex items-center gap-2">'
            . '<button type="submit" class="h-9 px-3.5 rounded-lg bg-brand hover:bg-brand-600 text-white text-[13px] font-semibold">Save</button>'
            . '<button type="submit" name="regenerate" value="1" class="h-9 px-3.5 rounded-lg border border-line text-[13px] font-medium text-ink-500 hover:bg-canvas">Save &amp; regenerate secret</button>'
            . '</div></div>'
        ) ?>
      </form>

      <?php elseif ($tab === 'integrations'): ?>
      <?php
        // Shown exactly once, straight after creation.
        $freshToken = session()->getFlashdata('new_api_token');
        $tokenRows = '';
        foreach ($apiTokens as $tk) {
            $revoked = (bool) $tk['revoked_at'];
            $expired = ! $revoked && ! empty($tk['expires_at']) && strtotime($tk['expires_at']) < time();
            $tokenRows .= '<div class="flex flex-wrap items-center gap-3 px-4 py-2.5 border-b border-line last:border-0">'
                . '<span class="flex-1 min-w-0"><span class="block text-[13px] font-medium ' . ($revoked || $expired ? 'text-faint line-through' : 'text-ink') . ' truncate">' . esc($tk['name']) . '</span>'
                . '<span class="block text-[11.5px] text-faint">acts as ' . esc($tk['who']) . ' · created ' . th_day($tk['created_at'])
                . (! empty($tk['expires_at']) ? ' · ' . ($expired ? 'expired ' : 'expires ') . th_day($tk['expires_at']) : ' · never expires') . '</span></span>'
                . '<span class="w-[150px] text-[12px] text-muted">' . ($tk['last_used_at'] ? 'used ' . th_rel($tk['last_used_at']) : 'never used') . '</span>'
                . ($revoked
                    ? '<span class="w-[86px] text-right text-[12px] text-faint">revoked</span>'
                    : '<form method="post" action="' . site_url('app/admin/tokens/' . $tk['id'] . '/revoke') . '" class="w-[86px] flex justify-end"'
                      . ' data-confirm="Revoke &ldquo;' . esc($tk['name'], 'attr') . '&rdquo;? Anything using it stops working immediately."'
                      . ' data-confirm-label="Revoke" data-confirm-title="Revoke this token?">' . csrf_field()
                      . th_btn('Revoke', 'type="submit"', 'danger') . '</form>')
                . '</div>';
        }
        if (! $tokenRows) {
            $tokenRows = th_empty('link', 'No API tokens', 'Create one to let another system raise and read tickets.');
        }

        echo th_card(
            th_card_head('API tokens', '<span class="text-muted">Bearer auth · acts as the chosen user</span>')
            . ($freshToken
                ? '<div class="px-4 py-3 bg-brand-50 border-b border-brand-100">'
                  . '<div class="text-[12px] font-semibold text-brand mb-1">Copy this now — it is not stored and cannot be shown again</div>'
                  . '<code class="block px-2.5 py-2 rounded-lg bg-white border border-brand-100 font-mono text-[12px] text-ink break-all">' . esc($freshToken) . '</code></div>'
                : '')
            . '<form method="post" action="' . site_url('app/admin/tokens') . '" class="flex flex-wrap items-center gap-2 px-4 py-3 border-b border-line">' . csrf_field()
            . '<input name="name" required placeholder="What will use this? e.g. Monitoring bridge" class="flex-1 min-w-[220px] h-8 px-2.5 rounded-lg border border-line text-[12.5px] placeholder:text-faint focus:border-brand">'
            . '<select name="user_id" required title="The token acts as this person" class="h-8 px-2 rounded-lg border border-line bg-white text-[12.5px] min-w-[180px]">'
            . '<option value="">Acts as…</option>'
            . implode('', array_map(static fn ($a) => (int) $a['active']
                ? '<option value="' . (int) $a['id'] . '"' . ((int) $a['id'] === (int) $me['id'] ? ' selected' : '') . '>' . esc($a['name']) . ' · ' . esc($a['role']) . '</option>'
                : '', $agents))
            . '</select>'
            . '<input name="expires_days" type="number" min="1" max="3650" step="1" placeholder="Expires in days (blank = never)" title="Days until the token stops working; leave blank for no expiry" class="w-[230px] h-8 px-2.5 rounded-lg border border-line text-[12.5px] placeholder:text-faint focus:border-brand">'
            . '<button type="submit" class="h-8 px-3 rounded-lg bg-brand hover:bg-brand-600 text-white text-[12.5px] font-semibold">Create token</button></form>'
            . '<div class="px-4 py-2.5 bg-canvas border-b border-line text-[12px] text-muted">'
            . 'Send as <code class="font-mono">Authorization: Bearer &lt;token&gt;</code>. '
            . 'Endpoints: <code class="font-mono">GET/POST /api/tickets</code>, <code class="font-mono">GET /api/tickets/{code}</code>, '
            . '<code class="font-mono">POST /api/tickets/{code}/reply</code>. '
            . 'A call sees exactly what the person it acts as would see. Limit: 120 requests per minute per token.</div>'
            . $tokenRows
        );
      ?>

      <?php
        $pdqOn = ($settings['pdq_enabled'] ?? '0') === '1';
        $pdqKeySet = ($settings['pdq_api_key'] ?? '') !== '';
        $pdqLast = $settings['pdq_last_sync'] ?? '';
      ?>
      <form method="post" action="<?= site_url('app/admin/pdq') ?>">
        <?= csrf_field() ?>
        <?= th_card(
            th_card_head('PDQ Connect — asset inventory', '<span class="' . ($pdqOn ? 'text-brand' : 'text-faint') . ' font-semibold">' . ($pdqOn ? 'Enabled' : 'Disabled') . '</span>')
            . '<div class="p-4 grid sm:grid-cols-2 gap-3.5">'

            . '<label class="sm:col-span-2 flex items-center gap-2.5 rounded-lg border border-line bg-canvas p-3 cursor-pointer">'
            . '<input type="checkbox" name="pdq_enabled" value="1" ' . ($pdqOn ? 'checked' : '') . ' class="w-[15px] h-[15px] rounded-sm border-line">'
            . '<span class="text-[13px] font-medium text-ink">Pull the PDQ Connect device inventory into Assets</span></label>'

            . '<div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">API key</label>'
            . '<input name="pdq_api_key" type="password" value="" placeholder="' . ($pdqKeySet ? '•••••••• (saved — leave blank to keep)' : 'Create one in PDQ Connect under Settings → API keys') . '" autocomplete="new-password" class="' . $inputCls . '"></div>'

            . '<div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">API base URL</label>'
            . '<input name="pdq_base_url" value="' . esc($settings['pdq_base_url'] ?? 'https://app.pdq.com/v1/api', 'attr') . '" class="' . $inputCls . ' font-mono text-[12px]">'
            . '<p class="text-[11.5px] text-faint mt-1">Leave the default unless PDQ changes their API host.</p></div>'

            . '<label class="sm:col-span-2 flex items-center gap-2.5 rounded-lg border border-line bg-canvas p-3 cursor-pointer">'
            . '<input type="checkbox" name="pdq_auto_sync" value="1" ' . (($settings['pdq_auto_sync'] ?? '0') === '1' ? 'checked' : '') . ' class="w-[15px] h-[15px] rounded-sm border-line">'
            . '<span class="text-[13px] font-medium text-ink">Auto-sync hourly</span>'
            . '<span class="text-[12px] text-muted">— runs inside <code class="font-mono text-[11.5px] bg-white border border-line rounded-sm px-1.5 py-0.5">php spark tickets:cron</code></span></label>'

            . '<div class="sm:col-span-2 rounded-lg border border-line bg-canvas p-3 text-[12.5px] text-ink-500 leading-relaxed">'
            . 'Devices are matched to existing assets by previous sync id, then serial number, then hostname — nothing is deleted, and assignments/sites you set by hand are kept. New devices arrive as assets tagged with a PDQ badge.'
            . ($pdqLast !== '' ? '<div class="mt-1.5 text-muted">Last sync: <span class="font-mono text-[11.5px]">' . th_date($pdqLast) . '</span></div>' : '')
            . '</div>'

            . '</div>'
            . '<div class="flex items-center gap-2 px-4 py-3.5 border-t border-line bg-canvas rounded-b-xl">'
            . '<div class="flex-1"></div>'
            . '<button type="submit" class="h-9 px-3.5 rounded-lg bg-brand hover:bg-brand-600 text-white text-[13px] font-semibold">Save settings</button>'
            . '</div>'
        ) ?>
      </form>
      <div class="mt-3 grid sm:grid-cols-2 gap-3">
        <form method="post" action="<?= site_url('app/admin/pdq/test') ?>">
          <?= csrf_field() ?>
          <?= th_card('<div class="flex items-center gap-3 p-4">'
              . '<span class="w-8 h-8 rounded-lg bg-canvas border border-line grid place-items-center text-muted">' . th_icon('zap', 'w-4 h-4') . '</span>'
              . '<div class="flex-1"><div class="text-[13px] font-medium text-ink">Test connection</div>'
              . '<div class="text-[12px] text-muted">Checks the key against the devices endpoint.</div></div>'
              . th_btn('Test', 'type="submit"', 'ghost', 'zap')
              . '</div>') ?>
        </form>
        <form method="post" action="<?= site_url('app/admin/pdq/sync') ?>">
          <?= csrf_field() ?>
          <?= th_card('<div class="flex items-center gap-3 p-4">'
              . '<span class="w-8 h-8 rounded-lg bg-canvas border border-line grid place-items-center text-muted">' . th_icon('refresh', 'w-4 h-4') . '</span>'
              . '<div class="flex-1"><div class="text-[13px] font-medium text-ink">Sync now</div>'
              . '<div class="text-[12px] text-muted">Pull all devices into the asset register.</div></div>'
              . th_btn('Sync', 'type="submit"', 'ghost', 'refresh')
              . '</div>') ?>
        </form>
      </div>

      <?php elseif ($tab === 'audit'): ?>
      <?= th_card(
          th_card_head('Audit log', '<span class="text-muted">Latest 100 events</span>')
          . th_table_head([['When', 'w-[150px]'], ['Who', 'w-[170px]'], ['Action', 'w-[190px]'], ['Detail', 'flex-1']])
          . (count($auditRows) === 0 ? th_empty('book', 'Nothing logged yet', 'Sign-ins, admin changes, and rule runs will show up here.')
              : implode('', array_map(static function ($row) use ($users) {
                  $who = $row['user_id'] ? ($users[(int) $row['user_id']]['name'] ?? '#' . $row['user_id']) : 'System';

                  return '<div class="flex flex-wrap md:flex-nowrap items-center gap-3 px-4 py-2 border-b border-line last:border-0">'
                      . '<span class="w-[150px] font-mono text-[11.5px] text-muted">' . th_date($row['created_at']) . '</span>'
                      . '<span class="w-[170px] text-[12.5px] text-ink truncate">' . esc($who) . '</span>'
                      . '<span class="w-[190px] font-mono text-[11.5px] text-ink-500">' . esc($row['action']) . '</span>'
                      . '<span class="flex-1 text-[12.5px] text-muted truncate">' . esc($row['detail']) . '</span>'
                      . '</div>';
              }, $auditRows)))
      ) ?>

      <?php elseif ($tab === 'sso'): ?>
      <?php $azureOn = ($settings['azure_enabled'] ?? '0') === '1'; ?>
      <form method="post" action="<?= site_url('app/admin/sso') ?>">
        <?= csrf_field() ?>
        <?= th_card(
            th_card_head('Microsoft Entra ID (Azure AD)', '<span class="' . ($azureOn ? 'text-brand' : 'text-faint') . ' font-semibold">' . ($azureOn ? 'Enabled' : 'Disabled') . '</span>')
            . '<div class="p-4 grid sm:grid-cols-2 gap-3.5">'

            . '<label class="sm:col-span-2 flex items-center gap-2.5 rounded-lg border border-line bg-canvas p-3 cursor-pointer">'
            . '<input type="checkbox" name="azure_enabled" value="1" ' . ($azureOn ? 'checked' : '') . ' class="w-[15px] h-[15px] rounded-sm border-line">'
            . '<span class="text-[13px] font-medium text-ink">Show &ldquo;Sign in with Microsoft&rdquo; on the login page</span></label>'

            . '<div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Directory (tenant) ID</label>'
            . '<input name="azure_tenant_id" value="' . esc($settings['azure_tenant_id'] ?? '', 'attr') . '" placeholder="00000000-0000-0000-0000-000000000000" class="' . $inputCls . ' font-mono text-[12px]">'
            . '<p class="text-[11.5px] text-faint mt-1">Required. Your directory (tenant) ID — a GUID from Entra → Overview. Every token is pinned to it; multi-tenant <code class="font-mono">common</code>/<code class="font-mono">organizations</code> are not accepted because they let any outside tenant claim your users\' email addresses.</p></div>'
            . '<div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Application (client) ID</label>'
            . '<input name="azure_client_id" value="' . esc($settings['azure_client_id'] ?? '', 'attr') . '" class="' . $inputCls . ' font-mono text-[12px]"></div>'

            . '<div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Client secret</label>'
            . '<input name="azure_client_secret" type="password" value="" placeholder="' . (($settings['azure_client_secret'] ?? '') !== '' ? '•••••••• (saved — leave blank to keep)' : 'Value from Certificates & secrets') . '" autocomplete="new-password" class="' . $inputCls . '"></div>'

            . '<label class="sm:col-span-2 flex items-center gap-2.5 rounded-lg border border-line bg-canvas p-3 cursor-pointer">'
            . '<input type="checkbox" name="azure_autoprovision" value="1" ' . (($settings['azure_autoprovision'] ?? '1') === '1' ? 'checked' : '') . ' class="w-[15px] h-[15px] rounded-sm border-line">'
            . '<span class="text-[13px] font-medium text-ink">Create an account on first sign-in</span>'
            . '<span class="text-[12px] text-muted">— new people join as Requesters unless a group below matches; promote them under Agents &amp; roles</span></label>'

            . '<div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Agent group (object id)</label>'
            . '<input name="sso_agent_group" value="' . esc($settings['sso_agent_group'] ?? '', 'attr') . '" placeholder="00000000-0000-0000-0000-000000000000" class="' . $inputCls . ' font-mono text-[12px]">'
            . '<p class="text-[11.5px] text-faint mt-1">Members sign in as Agents.</p></div>'
            . '<div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Administrator group (object id)</label>'
            . '<input name="sso_admin_group" value="' . esc($settings['sso_admin_group'] ?? '', 'attr') . '" placeholder="00000000-0000-0000-0000-000000000000" class="' . $inputCls . ' font-mono text-[12px]">'
            . '<p class="text-[11.5px] text-faint mt-1">Members sign in as Administrators. With either group set, everyone else becomes a Requester on their next Microsoft sign-in (Supervisors and the last Administrator are never demoted).</p></div>'

            . '<div class="sm:col-span-2 rounded-lg border border-line bg-canvas p-3">'
            . '<div class="text-[11px] font-semibold uppercase tracking-[.09em] text-faint mb-1.5">App registration checklist</div>'
            . '<ol class="text-[12.5px] text-ink-500 leading-relaxed list-decimal ml-4 space-y-1">'
            . '<li>Azure Portal → Entra ID → App registrations → New registration.</li>'
            . '<li>Add a <b>Web</b> redirect URI: <code class="font-mono text-[11.5px] bg-white border border-line rounded-sm px-1.5 py-0.5 select-all">' . site_url('auth/azure/callback') . '</code></li>'
            . '<li>Certificates &amp; secrets → new client secret → paste its <b>Value</b> above.</li>'
            . '<li>API permissions: <span class="font-mono text-[11.5px]">User.Read</span> (delegated) — granted by default.</li>'
            . '<li>For role mapping: Token configuration → Add groups claim → <b>Security groups</b>, emitted as <b>Group ID</b> in the ID token.</li>'
            . '</ol></div>'

            . '</div>'
            . '<div class="flex items-center gap-2 px-4 py-3.5 border-t border-line bg-canvas rounded-b-xl">'
            . '<span class="text-[12px] text-muted">Matching is by email; deactivated accounts stay blocked.</span>'
            . '<div class="flex-1"></div>'
            . '<button type="submit" class="h-9 px-3.5 rounded-lg bg-brand hover:bg-brand-600 text-white text-[13px] font-semibold">Save settings</button>'
            . '</div>'
        ) ?>
      </form>
      <?php elseif (! empty($pluginTab)): ?>
      <?php // View-local helpers are not part of the view data; hand them to the plug-in explicitly.
        $this->setData(['inputCls' => $inputCls, 'groupsById' => $groupsById, 'orgsById' => $orgsById, 'hoursById' => $hoursById]); ?>
      <?= $this->include('agent/admin/' . $pluginTab) ?>
      <?php endif ?>
    </div>
  </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('modals') ?>
<template id="tpl-addAgent">
  <form method="post" action="<?= site_url('app/admin/agents') ?>" data-modal-title="Invite an agent" data-submit="Send invite">
    <?= csrf_field() ?>
    <div class="grid sm:grid-cols-2 gap-3.5">
      <div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Full name<span class="text-alert"> *</span></label>
        <input name="name" required class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Work email<span class="text-alert"> *</span></label>
        <input name="email" type="email" required class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Job title</label>
        <input name="title" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Role</label>
        <select name="role" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px]"><option>Agent</option><option>Supervisor</option><option>Administrator</option></select></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Group</label>
        <select name="group_id" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px]">
          <?php foreach ($groups as $g): ?><option value="<?= $g['id'] ?>"><?= esc($g['name']) ?></option><?php endforeach ?>
        </select></div>
    </div>
  </form>
</template>

<template id="tpl-addGroup">
  <form method="post" action="<?= site_url('app/admin/groups') ?>" data-modal-title="New group" data-submit="Create group">
    <?= csrf_field() ?>
    <div class="grid gap-3.5">
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Group name<span class="text-alert"> *</span></label>
        <input name="name" required class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">What it covers</label>
        <input name="description" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Business hours</label>
        <select name="hours_id" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px]">
          <?php foreach ($hours as $h): ?><option value="<?= $h['id'] ?>"><?= esc($h['name']) ?></option><?php endforeach ?>
        </select></div>
    </div>
  </form>
</template>

<template id="tpl-addSla">
  <form method="post" action="<?= site_url('app/admin/slas') ?>" data-modal-title="New SLA policy" data-submit="Create policy">
    <?= csrf_field() ?>
    <div class="grid sm:grid-cols-2 gap-3.5">
      <div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Policy name<span class="text-alert"> *</span></label>
        <input name="name" required class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">First response</label>
        <input name="first_response" placeholder="e.g. 30 minutes" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Resolution</label>
        <input name="resolution" placeholder="e.g. 8 hours" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Clock</label>
        <select name="hours" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px]"><option>Business hours</option><option>24×7</option></select></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Escalation</label>
        <input name="escalation" placeholder="Who to notify" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
    </div>
  </form>
</template>

<?php
// Small helper for the "danger zone" delete form embedded inside edit modals.
$dangerDelete = static function (string $action, string $confirm, string $label = 'Delete') {
    return '<form method="post" action="' . $action . '" data-confirm="' . esc($confirm, 'attr') . '" data-confirm-label="' . $label . '" class="pt-1">'
        . csrf_field()
        . '<button type="submit" class="inline-flex items-center gap-1.5 h-8 px-2.5 rounded-lg border border-alert-100 bg-white text-[12.5px] font-medium text-alert hover:bg-alert-50">'
        . th_icon('trash', 'w-3.5 h-3.5') . $label . '</button></form>';
};
?>

<?php foreach ($agents as $a): ?>
<template id="tpl-editAgent-<?= $a['id'] ?>">
  <form method="post" action="<?= site_url('app/admin/agents/' . $a['id']) ?>" data-modal-title="Edit <?= esc($a['name'], 'attr') ?>" data-modal-sub="<?= esc($a['email'], 'attr') ?>" data-submit="Save">
    <?= csrf_field() ?>
    <div class="grid sm:grid-cols-2 gap-3.5">
      <div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Full name<span class="text-alert"> *</span></label>
        <input name="name" required value="<?= esc($a['name'], 'attr') ?>" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Job title</label>
        <input name="title" value="<?= esc($a['title'] ?? '', 'attr') ?>" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Role</label>
        <select name="role" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px]">
          <?php foreach (['Agent', 'Supervisor', 'Administrator'] as $r): ?><option <?= $a['role'] === $r ? 'selected' : '' ?>><?= $r ?></option><?php endforeach ?>
        </select></div>
      <div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Group</label>
        <select name="group_id" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px]">
          <?php foreach ($groups as $g): ?><option value="<?= $g['id'] ?>" <?= (int) $a['group_id'] === (int) $g['id'] ? 'selected' : '' ?>><?= esc($g['name']) ?></option><?php endforeach ?>
        </select></div>
    </div>
  </form>
</template>
<?php endforeach ?>

<template id="tpl-addPerson">
  <form method="post" action="<?= site_url('app/admin/people') ?>" data-modal-title="Add a person" data-modal-sub="They get a random one-time password (emailed if SMTP is set up, otherwise shown to you once) and must choose their own at first sign-in" data-submit="Add person">
    <?= csrf_field() ?>
    <div class="grid sm:grid-cols-2 gap-3.5">
      <div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Full name<span class="text-alert"> *</span></label>
        <input name="name" required class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
      <div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Work email<span class="text-alert"> *</span></label>
        <input name="email" type="email" required class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Job title</label>
        <input name="title" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Department</label>
        <input name="dept" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Site</label>
        <select name="site" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px]">
          <?php foreach (TH_SITES as $s): ?><option><?= $s ?></option><?php endforeach ?>
        </select></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Phone</label>
        <input name="phone" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
      <div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Organization</label>
        <?= $orgSelect(null) ?></div>
    </div>
  </form>
</template>

<?php foreach ($requesters as $u): ?>
<template id="tpl-editPerson-<?= $u['id'] ?>">
  <div data-modal-title="Edit <?= esc($u['name'], 'attr') ?>" data-modal-sub="<?= esc($u['email'], 'attr') ?>" data-submit="Save">
  <form method="post" action="<?= site_url('app/admin/people/' . $u['id']) ?>" data-primary>
    <?= csrf_field() ?>
    <div class="grid sm:grid-cols-2 gap-3.5">
      <div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Full name<span class="text-alert"> *</span></label>
        <input name="name" required value="<?= esc($u['name'], 'attr') ?>" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Job title</label>
        <input name="title" value="<?= esc($u['title'] ?? '', 'attr') ?>" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Department</label>
        <input name="dept" value="<?= esc($u['dept'] ?? '', 'attr') ?>" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Site</label>
        <select name="site" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px]">
          <?php foreach (TH_SITES as $s): ?><option <?= ($u['site'] ?? '') === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach ?>
        </select></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Phone</label>
        <input name="phone" value="<?= esc($u['phone'] ?? '', 'attr') ?>" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
      <div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Organization</label>
        <?= $orgSelect(isset($u['org_id']) ? (int) $u['org_id'] : null) ?></div>
    </div>
  </form>
  <div class="mt-4 pt-3 border-t border-line flex flex-wrap items-center gap-2">
    <span class="text-[11px] font-semibold uppercase tracking-[.09em] text-faint mr-1">Their data</span>
    <a href="<?= site_url('app/admin/people/' . $u['id'] . '/export') ?>" class="inline-flex items-center gap-1.5 h-8 px-2.5 rounded-lg border border-line bg-white text-[12.5px] font-medium text-ink-500 hover:bg-canvas"><?= th_icon('note', 'w-3.5 h-3.5') ?>Export data (JSON)</a>
    <form method="post" action="<?= site_url('app/admin/people/' . $u['id'] . '/anonymize') ?>" class="inline"
          data-confirm="Anonymize <?= esc($u['name'], 'attr') ?>? Their name, email, contact details and sign-in are erased and the account is deactivated. Tickets are kept under &ldquo;Deleted user <?= $u['id'] ?>&rdquo;. This cannot be undone."
          data-confirm-label="Anonymize" data-confirm-title="Anonymize this person?">
      <?= csrf_field() ?>
      <button type="submit" class="inline-flex items-center gap-1.5 h-8 px-2.5 rounded-lg border border-alert-100 bg-white text-[12.5px] font-medium text-alert hover:bg-alert-50"><?= th_icon('trash', 'w-3.5 h-3.5') ?>Anonymize</button>
    </form>
  </div>
  </div>
</template>
<?php endforeach ?>

<?php foreach ($groups as $g): ?>
<template id="tpl-editGroup-<?= $g['id'] ?>">
  <div data-modal-title="Edit <?= esc($g['name'], 'attr') ?>" data-submit="Save">
    <form method="post" action="<?= site_url('app/admin/groups/' . $g['id']) ?>" data-primary>
      <?= csrf_field() ?>
      <div class="grid gap-3.5">
        <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Group name<span class="text-alert"> *</span></label>
          <input name="name" required value="<?= esc($g['name'], 'attr') ?>" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
        <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">What it covers</label>
          <input name="description" value="<?= esc($g['description'], 'attr') ?>" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
        <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Business hours</label>
          <select name="hours_id" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px]">
            <?php foreach ($hours as $h): ?><option value="<?= $h['id'] ?>" <?= (int) $g['hours_id'] === (int) $h['id'] ? 'selected' : '' ?>><?= esc($h['name']) ?></option><?php endforeach ?>
          </select></div>
      </div>
    </form>
    <?= $dangerDelete(site_url('app/admin/groups/' . $g['id'] . '/delete'), 'Delete the group "' . $g['name'] . '"? Only possible when no tickets or agents reference it.') ?>
  </div>
</template>
<?php endforeach ?>

<?php foreach ($slas as $s): ?>
<template id="tpl-editSla-<?= $s['id'] ?>">
  <div data-modal-title="Edit <?= esc($s['name'], 'attr') ?>" data-submit="Save">
  <form method="post" action="<?= site_url('app/admin/slas/' . $s['id']) ?>" data-primary>
    <?= csrf_field() ?>
    <div class="grid sm:grid-cols-2 gap-3.5">
      <div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Policy name<span class="text-alert"> *</span></label>
        <input name="name" required value="<?= esc($s['name'], 'attr') ?>" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">First response</label>
        <input name="first_response" value="<?= esc($s['first_response'], 'attr') ?>" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Resolution</label>
        <input name="resolution" value="<?= esc($s['resolution'], 'attr') ?>" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Clock</label>
        <select name="hours" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px]">
          <option <?= $s['hours'] === 'Business hours' ? 'selected' : '' ?>>Business hours</option>
          <option <?= $s['hours'] === '24×7' ? 'selected' : '' ?>>24×7</option>
        </select></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Escalation</label>
        <input name="escalation" value="<?= esc($s['escalation'], 'attr') ?>" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
    </div>
  </form>
  <div class="mt-3"><?= $dangerDelete(site_url('app/admin/slas/' . $s['id'] . '/delete'), 'Delete the SLA policy "' . $s['name'] . '"?') ?></div>
  </div>
</template>
<?php endforeach ?>

<?php
/* ---------- automation builder renderers ---------- */
$autoSelectCls = 'h-9 px-2 rounded-lg border border-line bg-white text-[12.5px] text-ink-500';
$autoInputCls  = 'flex-1 min-w-0 h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand';

$condRow = static function (array $c = []) use ($fieldLabels, $opLabels, $autoSelectCls, $autoInputCls) {
    $html = '<div class="flex items-center gap-2" data-auto-row>'
        . '<select name="cond_field[]" class="' . $autoSelectCls . ' w-[170px] shrink-0">';
    foreach ($fieldLabels as $k => $label) {
        $html .= '<option value="' . $k . '"' . (($c['field'] ?? 'subject') === $k ? ' selected' : '') . '>' . $label . '</option>';
    }
    $html .= '</select><select name="cond_op[]" class="' . $autoSelectCls . ' w-[140px] shrink-0">';
    foreach ($opLabels as $k => $label) {
        $html .= '<option value="' . $k . '"' . (($c['op'] ?? 'contains') === $k ? ' selected' : '') . '>' . $label . '</option>';
    }
    $html .= '</select><input name="cond_value[]" value="' . esc($c['value'] ?? '', 'attr') . '" placeholder="value" class="' . $autoInputCls . '">'
        . '<button type="button" data-auto-remove class="w-7 h-7 grid place-items-center rounded-md text-faint hover:text-alert hover:bg-alert-50 shrink-0">' . th_icon('x', 'w-3.5 h-3.5') . '</button></div>';

    return $html;
};

$actValueControl = static function (string $type, string $value) use ($groups, $agents, $templates, $autoInputCls) {
    $sel = static function (array $pairs, string $value) {
        $h = '';
        foreach ($pairs as [$v, $l]) {
            $h .= '<option value="' . esc((string) $v, 'attr') . '"' . ((string) $v === $value ? ' selected' : '') . '>' . esc($l) . '</option>';
        }

        return $h;
    };
    $base = 'flex-1 min-w-0 h-9 px-2 rounded-lg border border-line bg-white text-[12.5px]';

    return match ($type) {
        'set_status'   => '<select name="act_value[]" class="' . $base . '">' . $sel(array_map(static fn ($s) => [$s, $s], array_keys(TH_STATUS)), $value) . '</select>',
        'set_priority' => '<select name="act_value[]" class="' . $base . '">' . $sel(array_map(static fn ($s) => [$s, $s], array_keys(TH_PRIORITY)), $value) . '</select>',
        'move_group'   => '<select name="act_value[]" class="' . $base . '">' . $sel(array_map(static fn ($g) => [$g['id'], $g['name']], $groups), $value) . '</select>',
        'assign_agent' => '<select name="act_value[]" class="' . $base . '">' . $sel(array_map(static fn ($a) => [$a['id'], $a['name']], array_values(array_filter($agents, static fn ($a) => (int) $a['active']))), $value) . '</select>',
        'escalate'     => '<input type="hidden" name="act_value[]" value=""><span class="text-[12px] text-faint self-center">no value needed</span>',
        'add_tag'      => '<input name="act_value[]" value="' . esc($value, 'attr') . '" placeholder="tag-name" class="' . $autoInputCls . '">',
        'webhook'      => '<input name="act_value[]" type="url" value="' . esc($value, 'attr') . '" placeholder="https://hooks.example.com/..." class="' . $autoInputCls . ' font-mono text-[12px]">',
        'email_template' => '<select name="act_value[]" class="' . $base . '">' . $sel(array_map(static fn ($t) => [$t['id'], $t['name'] . ' → ' . $t['recipient']], $templates), $value) . '</select>',
        default        => '<textarea name="act_value[]" rows="3" placeholder="Message — placeholders like {{ticket.id}}, {{requester.first}}, {{ticket.url}} work here" class="flex-1 min-w-0 px-2.5 py-2 rounded-lg border border-line text-[12.5px] font-mono leading-relaxed focus:border-brand">' . esc($value) . '</textarea>',
    };
};

$actRow = static function (array $a = []) use ($actValueControl, $autoSelectCls) {
    $types = [
        'set_status' => 'Set status', 'set_priority' => 'Set priority', 'move_group' => 'Move to team',
        'assign_agent' => 'Assign to agent', 'add_tag' => 'Add tag', 'escalate' => 'Escalate',
        'add_note' => 'Add private note', 'email_requester' => 'Email requester', 'email_agent' => 'Email assignee',
        'email_template' => 'Send email template', 'webhook' => 'Call a webhook',
    ];
    $type = $a['type'] ?? 'set_status';
    $html = '<div class="flex items-start gap-2" data-auto-row>'
        . '<select name="act_type[]" data-act-type class="' . $autoSelectCls . ' w-[170px] shrink-0">';
    foreach ($types as $k => $label) {
        $html .= '<option value="' . $k . '"' . ($type === $k ? ' selected' : '') . '>' . $label . '</option>';
    }
    $html .= '</select><span data-value-slot class="flex-1 min-w-0 flex">' . $actValueControl($type, (string) ($a['value'] ?? '')) . '</span>'
        . '<button type="button" data-auto-remove class="w-7 h-7 grid place-items-center rounded-md text-faint hover:text-alert hover:bg-alert-50 shrink-0 mt-1">' . th_icon('x', 'w-3.5 h-3.5') . '</button></div>';

    return $html;
};

$ruleForm = static function (?array $r, string $formAction) use ($condRow, $actRow) {
    $conditions = $r ? (json_decode($r['conditions'] ?? '[]', true) ?: []) : [];
    $actions    = $r ? (json_decode($r['actions'] ?? '[]', true) ?: []) : [['type' => 'set_status', 'value' => 'Open']];

    $html = '<form method="post" action="' . $formAction . '" data-primary>' . csrf_field()
        . '<div class="grid gap-3.5">'
        . '<div class="grid sm:grid-cols-2 gap-3.5">'
        . '<div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Rule name<span class="text-alert"> *</span></label>'
        . '<input name="name" required value="' . esc($r['name'] ?? '', 'attr') . '" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>'
        . '<div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Runs when</label>'
        . '<select name="when_event" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px]">';
    foreach (['Ticket is created', 'Ticket is updated', 'Time is reached'] as $w) {
        $html .= '<option' . (($r['when_event'] ?? 'Ticket is created') === $w ? ' selected' : '') . '>' . $w . '</option>';
    }
    $html .= '</select></div></div>'

        . '<div><div class="flex items-center justify-between mb-1.5">'
        . '<label class="block text-[12px] font-medium text-ink-500">Conditions <span class="text-faint font-normal">— all must match; none = every ticket</span></label>'
        . '<button type="button" data-auto-add="cond" class="text-[12px] text-brand font-medium hover:underline">+ Add condition</button></div>'
        . '<div class="space-y-2" data-auto-rows="cond">' . implode('', array_map($condRow, $conditions)) . '</div></div>'

        . '<div><div class="flex items-center justify-between mb-1.5">'
        . '<label class="block text-[12px] font-medium text-ink-500">Actions<span class="text-alert"> *</span></label>'
        . '<button type="button" data-auto-add="act" class="text-[12px] text-brand font-medium hover:underline">+ Add action</button></div>'
        . '<div class="space-y-2" data-auto-rows="act">' . implode('', array_map($actRow, $actions)) . '</div></div>'

        . '<p class="text-[11.5px] text-faint leading-relaxed">&ldquo;Time is reached&rdquo; rules run from the scheduler (or Run now) and fire once per ticket. '
        . 'Number fields (hours since…, % of SLA used) pair with the ≥ operator.</p>'
        . '</div></form>';

    return $html;
};
?>

<template id="tpl-autoCondRow"><?= $condRow() ?></template>
<template id="tpl-autoActRow"><?= $actRow() ?></template>

<template id="tpl-addRule">
  <div data-modal-title="New automation" data-modal-width="max-w-2xl" data-submit="Create rule">
    <?= $ruleForm(null, site_url('app/admin/rules')) ?>
  </div>
</template>

<?php foreach ($rules as $r): ?>
<template id="tpl-editRule-<?= $r['id'] ?>">
  <div data-modal-title="Edit <?= esc($r['name'], 'attr') ?>" data-modal-width="max-w-2xl" data-submit="Save">
    <?= $ruleForm($r, site_url('app/admin/rules/' . $r['id'])) ?>
    <div class="mt-3"><?= $dangerDelete(site_url('app/admin/rules/' . $r['id'] . '/delete'), 'Delete the automation "' . $r['name'] . '"?') ?></div>
  </div>
</template>
<?php endforeach ?>

<script>
window.THAUTO = {
  statuses: <?= json_encode(array_keys(TH_STATUS)) ?>,
  priorities: <?= json_encode(array_keys(TH_PRIORITY)) ?>,
  groups: <?= json_encode(array_map(static fn ($g) => [(string) $g['id'], $g['name']], $groups)) ?>,
  agents: <?= json_encode(array_map(static fn ($a) => [(string) $a['id'], $a['name']], array_values(array_filter($agents, static fn ($a) => (int) $a['active'])))) ?>,
  templates: <?= json_encode(array_map(static fn ($t) => [(string) $t['id'], $t['name'] . ' → ' . $t['recipient']], $templates)) ?>
};
</script>

<?php foreach ($fields as $f): ?>
<template id="tpl-editField-<?= $f['id'] ?>">
  <div data-modal-title="Edit <?= esc($f['label'], 'attr') ?>" data-submit="Save">
  <form method="post" action="<?= site_url('app/admin/fields/' . $f['id']) ?>" data-primary>
    <?= csrf_field() ?>
    <div class="grid sm:grid-cols-2 gap-3.5">
      <div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Field label<span class="text-alert"> *</span></label>
        <input name="label" required value="<?= esc($f['label'], 'attr') ?>" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Type</label>
        <select name="type" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px]">
          <?php foreach (['Text', 'Paragraph', 'Dropdown', 'Checkbox', 'Date', 'Lookup'] as $ft): ?><option <?= $f['type'] === $ft ? 'selected' : '' ?>><?= $ft ?></option><?php endforeach ?>
        </select></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Required</label>
        <select name="required" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px]">
          <option value="no" <?= ! $f['required'] ? 'selected' : '' ?>>No</option>
          <option value="yes" <?= $f['required'] ? 'selected' : '' ?>>Yes</option>
        </select></div>
      <div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Dropdown options</label>
        <input name="options" value="<?= esc(implode(', ', json_decode($f['options'] ?? '[]', true) ?: []), 'attr') ?>" placeholder="Comma separated" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
      <div class="sm:col-span-2 flex items-center gap-5">
        <label class="inline-flex items-center gap-2 text-[13px] text-ink-500"><input type="checkbox" name="agents" value="1" <?= $f['agents'] ? 'checked' : '' ?> class="w-[15px] h-[15px] rounded-sm border-line"> Agents</label>
        <label class="inline-flex items-center gap-2 text-[13px] text-ink-500"><input type="checkbox" name="portal" value="1" <?= $f['portal'] ? 'checked' : '' ?> class="w-[15px] h-[15px] rounded-sm border-line"> Portal</label>
      </div>
    </div>
  </form>
  <div class="mt-3"><?= $dangerDelete(site_url('app/admin/fields/' . $f['id'] . '/delete'), 'Delete the field "' . $f['label'] . '"?') ?></div>
  </div>
</template>
<?php endforeach ?>

<?php
$routeFields = static function (array $r, array $groups, array $agents) {
    $html = '<div class="grid sm:grid-cols-2 gap-3.5">'
        . '<div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Match type</label>'
        . '<select name="match_type" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px]">';
    foreach (['Category', 'Subject contains', 'Source'] as $mt) {
        $html .= '<option' . (($r['match_type'] ?? 'Category') === $mt ? ' selected' : '') . '>' . $mt . '</option>';
    }
    $html .= '</select></div>'
        . '<div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Match value<span class="text-alert"> *</span></label>'
        . '<input name="match_value" required value="' . esc($r['match_value'] ?? '', 'attr') . '" placeholder="e.g. Network, or vpn" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>'
        . '<div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Route to team<span class="text-alert"> *</span></label>'
        . '<select name="group_id" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px]">';
    foreach ($groups as $g) {
        $html .= '<option value="' . $g['id'] . '"' . ((int) ($r['group_id'] ?? 0) === (int) $g['id'] ? ' selected' : '') . '>' . esc($g['name']) . '</option>';
    }
    $html .= '</select></div>'
        . '<div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Position (lower runs first)</label>'
        . '<input name="position" type="number" min="0" value="' . (int) ($r['position'] ?? 10) . '" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] font-mono focus:border-brand"></div>'
        . '<div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Also assign to</label>'
        . '<select name="agent_id" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px]"><option value="">Nobody — leave in queue</option>';
    foreach ($agents as $a) {
        if ((int) $a['active']) {
            $html .= '<option value="' . $a['id'] . '"' . ((int) ($r['agent_id'] ?? 0) === (int) $a['id'] ? ' selected' : '') . '>' . esc($a['name']) . '</option>';
        }
    }
    $html .= '</select></div>'
        . '<div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Also set priority</label>'
        . '<select name="priority" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px]"><option value="">Keep as submitted</option>';
    foreach (['Urgent', 'High', 'Medium', 'Low'] as $pr) {
        $html .= '<option' . (($r['priority'] ?? '') === $pr ? ' selected' : '') . '>' . $pr . '</option>';
    }

    return $html . '</select></div></div>';
};
?>
<template id="tpl-addRoute">
  <form method="post" action="<?= site_url('app/admin/routing') ?>" data-modal-title="New routing rule" data-modal-width="max-w-xl" data-submit="Add rule">
    <?= csrf_field() ?>
    <?= $routeFields([], $groups, $agents) ?>
  </form>
</template>

<?php foreach ($routes as $r): ?>
<template id="tpl-editRoute-<?= $r['id'] ?>">
  <div data-modal-title="Edit routing rule" data-modal-width="max-w-xl" data-submit="Save">
    <form method="post" action="<?= site_url('app/admin/routing/' . $r['id']) ?>" data-primary>
      <?= csrf_field() ?>
      <?= $routeFields($r, $groups, $agents) ?>
    </form>
    <div class="mt-3"><?= $dangerDelete(site_url('app/admin/routing/' . $r['id'] . '/delete'), 'Delete this routing rule?') ?></div>
  </div>
</template>
<?php endforeach ?>

<template id="tpl-addHours">
  <form method="post" action="<?= site_url('app/admin/hours') ?>" data-modal-title="Add calendar" data-submit="Add calendar">
    <?= csrf_field() ?>
    <div class="grid sm:grid-cols-2 gap-3.5">
      <div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Name<span class="text-alert"> *</span></label>
        <input name="name" required class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Timezone</label>
        <input name="tz" placeholder="America/Toronto" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] font-mono focus:border-brand"></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Days</label>
        <input name="days" placeholder="Mon-Fri" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Hours</label>
        <input name="time_range" placeholder="08:00 - 18:00" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] font-mono focus:border-brand"></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Holidays</label>
        <input name="holidays" placeholder="Canadian statutory" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
      <div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Holiday dates</label>
        <textarea name="holiday_dates" rows="3" placeholder="2026-12-25, 2026-12-26, 2027-01-01"
          class="w-full px-2.5 py-2 rounded-lg border border-line text-[12.5px] font-mono leading-relaxed focus:border-brand"></textarea>
        <p class="text-[11.5px] text-faint mt-1">YYYY-MM-DD, comma or line separated. These are the days the SLA clock skips &mdash; the label above is just a description.</p></div>
    </div>
  </form>
</template>

<?php foreach ($hours as $h): ?>
<template id="tpl-editHours-<?= $h['id'] ?>">
  <form method="post" action="<?= site_url('app/admin/hours/' . $h['id']) ?>" data-modal-title="Edit <?= esc($h['name'], 'attr') ?>" data-submit="Save">
    <?= csrf_field() ?>
    <div class="grid sm:grid-cols-2 gap-3.5">
      <div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Name<span class="text-alert"> *</span></label>
        <input name="name" required value="<?= esc($h['name'], 'attr') ?>" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Timezone</label>
        <input name="tz" value="<?= esc($h['tz'], 'attr') ?>" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] font-mono focus:border-brand"></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Days</label>
        <input name="days" value="<?= esc($h['days'], 'attr') ?>" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Hours</label>
        <input name="time_range" value="<?= esc($h['time_range'], 'attr') ?>" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] font-mono focus:border-brand"></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Holidays</label>
        <input name="holidays" value="<?= esc($h['holidays'], 'attr') ?>" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
      <div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Holiday dates</label>
        <textarea name="holiday_dates" rows="3" placeholder="2026-12-25, 2026-12-26, 2027-01-01"
          class="w-full px-2.5 py-2 rounded-lg border border-line text-[12.5px] font-mono leading-relaxed focus:border-brand"><?= esc(implode(', ', json_decode($h['holiday_dates'] ?? '[]', true) ?: [])) ?></textarea>
        <p class="text-[11.5px] text-faint mt-1">YYYY-MM-DD, comma or line separated. These are the days the SLA clock skips &mdash; the label above is just a description.</p></div>
    </div>
  </form>
</template>
<?php endforeach ?>

<?php foreach ($templates as $t): ?>
<template id="tpl-editTemplate-<?= $t['id'] ?>">
  <form method="post" action="<?= site_url('app/admin/templates/' . $t['id']) ?>" data-modal-title="<?= esc($t['name'], 'attr') ?>"
        data-modal-sub="Sent to the <?= esc(strtolower($t['recipient']), 'attr') ?> when: <?= esc($t['trigger_event'], 'attr') ?>"
        data-modal-width="max-w-xl" data-submit="Save template">
    <?= csrf_field() ?>
    <div class="grid gap-3.5">
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Subject<span class="text-alert"> *</span></label>
        <input name="subject" required value="<?= esc($t['subject'], 'attr') ?>" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] font-mono focus:border-brand"></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Body</label>
        <textarea name="body" rows="9" class="w-full px-2.5 py-2 rounded-lg border border-line text-[13px] font-mono leading-relaxed focus:border-brand"><?= esc($t['body'] ?? '') ?></textarea></div>
      <p class="text-[11.5px] text-faint leading-relaxed">Placeholders:
        <code class="font-mono">{{ticket.id}}</code> <code class="font-mono">{{ticket.subject}}</code> <code class="font-mono">{{ticket.priority}}</code>
        <code class="font-mono">{{ticket.due}}</code> <code class="font-mono">{{ticket.url}}</code> <code class="font-mono">{{ticket.agent_url}}</code>
        <code class="font-mono">{{requester.name}}</code> <code class="font-mono">{{requester.first}}</code>
        <code class="font-mono">{{agent.name}}</code> <code class="font-mono">{{agent.first}}</code> <code class="font-mono">{{message}}</code></p>
    </div>
  </form>
</template>
<?php endforeach ?>

<template id="tpl-addField">
  <form method="post" action="<?= site_url('app/admin/fields') ?>" data-modal-title="New ticket field" data-modal-sub="Shows on the ticket forms for the audiences you tick" data-submit="Add field">
    <?= csrf_field() ?>
    <div class="grid sm:grid-cols-2 gap-3.5">
      <div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Field label<span class="text-alert"> *</span></label>
        <input name="label" required class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Type</label>
        <select name="type" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px]">
          <option>Text</option><option>Paragraph</option><option>Dropdown</option><option>Checkbox</option><option>Date</option><option>Lookup</option>
        </select></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Required</label>
        <select name="required" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px]"><option value="no">No</option><option value="yes">Yes</option></select></div>
      <div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Dropdown options</label>
        <input name="options" placeholder="Comma separated — e.g. Wi-Fi, VPN, Email" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand">
        <p class="text-[11.5px] text-faint mt-1">Only used for Dropdown/Lookup types.</p></div>
      <div class="sm:col-span-2 flex items-center gap-5">
        <label class="inline-flex items-center gap-2 text-[13px] text-ink-500"><input type="checkbox" name="agents" value="1" checked class="w-[15px] h-[15px] rounded-sm border-line"> Agent form</label>
        <label class="inline-flex items-center gap-2 text-[13px] text-ink-500"><input type="checkbox" name="portal" value="1" class="w-[15px] h-[15px] rounded-sm border-line"> Portal form</label>
      </div>
    </div>
  </form>
</template>
<?= $this->endSection() ?>
