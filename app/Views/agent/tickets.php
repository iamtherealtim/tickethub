<?= $this->extend('layouts/agent') ?>
<?= $this->section('content') ?>

<?php
$viewLabels = [
    'all' => 'All open', 'mine' => 'Assigned to me', 'group' => 'My group', 'unassigned' => 'Unassigned',
    'overdue' => 'Past due', 'escalated' => 'Escalated', 'closed' => 'Resolved & closed', 'every' => 'Everything',
];
$f = $filters;
$qs = static function (array $overrides) use ($f, $view) {
    $params = array_filter(['view' => $view] + $overrides + $f, static fn ($v) => $v !== '' && $v !== null);

    return site_url('app/tickets') . '?' . http_build_query(array_merge($params, $overrides));
};
?>
<div class="p-5 max-w-[1400px] mx-auto fade-in">
  <div class="flex flex-wrap items-end justify-between gap-3 mb-5">
    <div>
      <h1 class="font-display text-[22px] font-semibold text-ink">Tickets</h1>
      <p class="text-[13px] text-muted mt-1">Incidents and service requests across every group</p>
    </div>
    <div class="flex items-center gap-2">
      <a href="<?= site_url('app/tickets/export') . '?' . http_build_query(array_filter(['view' => $view] + $f)) ?>" class="inline-flex items-center gap-1.5 h-8 px-2.5 rounded-lg border border-line bg-white text-[12.5px] font-medium text-ink-500 hover:bg-canvas"><?= th_icon('ext', 'w-3.5 h-3.5') ?>Export CSV</a>
      <?= th_btn('New ticket', 'data-modal="newTicket"', 'brand', 'plus') ?>
    </div>
  </div>

  <section class="bg-white border border-line rounded-xl shadow-card">
    <!-- saved views -->
    <div class="flex items-center gap-1 p-2 border-b border-line overflow-x-auto">
      <?php foreach ($viewLabels as $v => $label): $on = $view === $v; ?>
      <a href="<?= site_url('app/tickets') . ($v === 'all' ? '' : '?view=' . $v) ?>" class="h-8 px-2.5 rounded-lg text-[12.5px] font-medium whitespace-nowrap transition inline-flex items-center
        <?= $on ? 'bg-ink text-white' : 'text-muted hover:bg-canvas hover:text-ink' ?>"><?= $label ?>
        <span class="ml-1 font-mono text-[11px] <?= $on ? 'text-white/60' : 'text-faint' ?>"><?= $counts[$v] ?></span></a>
      <?php endforeach ?>

      <?php // Agent-defined views sit alongside the built-ins, visually separated. ?>
      <?php if ($savedViews): ?><span class="w-px h-5 bg-line mx-1 shrink-0"></span><?php endif ?>
      <?php foreach ($savedViews as $sv): $active = ($_SERVER['QUERY_STRING'] ?? '') === $sv['query']; ?>
      <span class="group relative inline-flex items-center shrink-0">
        <a href="<?= site_url('app/tickets') . '?' . esc($sv['query'], 'attr') ?>"
           title="<?= $sv['shared'] ? 'Shared by ' . esc($sv['owner'], 'attr') : 'Your view' ?>"
           class="h-8 pl-2.5 pr-6 rounded-lg text-[12.5px] font-medium whitespace-nowrap transition inline-flex items-center gap-1
                  <?= $active ? 'bg-ink text-white' : 'text-muted hover:bg-canvas hover:text-ink' ?>">
          <?php if ($sv['shared']): ?><span class="text-[10px] opacity-70"><?= th_icon('users', 'w-3 h-3') ?></span><?php endif ?>
          <?= esc($sv['name']) ?></a>
        <?php if ((int) $sv['user_id'] === (int) $me['id']): ?>
        <form method="post" action="<?= site_url('app/tickets/views/' . $sv['id'] . '/delete') ?>" class="absolute right-1">
          <?= csrf_field() ?>
          <button type="submit" title="Remove view" class="w-4 h-4 grid place-items-center rounded-sm text-faint opacity-0 group-hover:opacity-100 hover:text-alert transition"><?= th_icon('x', 'w-3 h-3') ?></button>
        </form>
        <?php endif ?>
      </span>
      <?php endforeach ?>

      <button type="button" data-modal="saveView" class="h-8 px-2.5 rounded-lg text-[12.5px] font-medium whitespace-nowrap text-muted hover:bg-canvas hover:text-ink inline-flex items-center gap-1 shrink-0 ml-auto">
        <?= th_icon('plus', 'w-3.5 h-3.5') ?>Save view</button>
    </div>

    <!-- filters -->
    <form method="get" action="<?= site_url('app/tickets') ?>" class="flex flex-wrap items-center gap-2 p-3 border-b border-line">
      <input type="hidden" name="view" value="<?= esc($view, 'attr') ?>">
      <div class="relative flex-1 min-w-[200px]">
        <span class="absolute left-2.5 top-1/2 -translate-y-1/2 text-faint"><?= th_icon('search', 'w-4 h-4') ?></span>
        <input name="q" value="<?= esc($f['q'], 'attr') ?>" placeholder="Search subject, requester, tag or ID"
          class="w-full h-8 pl-8 pr-3 rounded-lg border border-line bg-white text-[13px] placeholder:text-faint">
      </div>
      <?= th_select('status', $f['status'], array_merge([['', 'Any status']], array_map(static fn ($s) => [$s, $s], array_keys(TH_STATUS))), 'data-autosubmit') ?>
      <?= th_select('priority', $f['priority'], array_merge([['', 'Any priority']], array_map(static fn ($s) => [$s, $s], array_keys(TH_PRIORITY))), 'data-autosubmit') ?>
      <?= th_select('type', $f['type'], [['', 'Any type'], ['Incident', 'Incident'], ['Service request', 'Service request']], 'data-autosubmit') ?>
      <?= th_select('category', $f['category'] ?? '', array_merge([['', 'Any category']], array_map(static fn ($c) => [$c, $c], TH_CATEGORIES)), 'data-autosubmit') ?>
      <?= th_select('source', $f['source'] ?? '', array_merge([['', 'Any source']], array_map(static fn ($s) => [$s, $s], TH_SOURCES)), 'data-autosubmit') ?>
      <?= th_select('group', $f['group'], array_merge([['', 'Any group']], array_map(static fn ($g) => [$g['id'], $g['name']], $groups)), 'data-autosubmit') ?>
      <?= th_select('agent', $f['agent'], array_merge([['', 'Any assignee'], ['none', 'Unassigned']], array_map(static fn ($a) => [$a['id'], $a['name']], $isAdmin ? $agents : $assignableAgents)), 'data-autosubmit') ?>
      <?php // Organization: only once the organizations module's migration has run (users.org_id). ?>
      <?php if (! empty($orgs)): ?>
      <?= th_select('org', $f['org'] ?? '', array_merge([['', 'Any organization']], array_map(static fn ($o) => [$o['id'], $o['name']], $orgs)), 'data-autosubmit') ?>
      <?php endif ?>
      <?= th_select('sort', $f['sort'], [['sla', 'Sort: SLA due'], ['updated', 'Sort: last updated'], ['created', 'Sort: newest'], ['priority', 'Sort: priority']], 'data-autosubmit') ?>
    </form>

    <!-- bulk bar -->
    <form id="bulkForm" method="post" action="<?= site_url('app/tickets/bulk') ?>" class="hidden">
      <?= csrf_field() ?>
      <input type="hidden" id="bulkAction" name="action" value="">
      <input type="hidden" id="bulkValue" name="value" value="">
    </form>
    <div id="bulkBar" class="hidden flex-wrap items-center gap-2 px-4 h-12 bg-ink text-white border-b border-ink-600">
      <span id="bulkCount" class="text-[12.5px] font-medium">0 selected</span>
      <span class="w-px h-5 bg-white/15 mx-1"></span>
      <select data-bulk="assign" class="h-7 rounded-md bg-ink-600 border border-white/10 text-[12px] text-white pl-2">
        <option value="">Assign to…</option>
        <?php foreach ($assignableAgents as $a): ?><option value="<?= $a['id'] ?>"><?= esc($a['name']) ?></option><?php endforeach ?>
      </select>
      <select data-bulk="group" class="h-7 rounded-md bg-ink-600 border border-white/10 text-[12px] text-white pl-2">
        <option value="">Move to group…</option>
        <?php foreach ($groups as $g): if ($isAdmin || (int) $g['id'] === (int) ($me['group_id'] ?? 0)): ?><option value="<?= $g['id'] ?>"><?= esc($g['name']) ?></option><?php endif; endforeach ?>
      </select>
      <select data-bulk="status" class="h-7 rounded-md bg-ink-600 border border-white/10 text-[12px] text-white pl-2">
        <option value="">Set status…</option>
        <?php foreach (array_keys(TH_STATUS) as $s): ?><option><?= $s ?></option><?php endforeach ?>
      </select>
      <select data-bulk="priority" class="h-7 rounded-md bg-ink-600 border border-white/10 text-[12px] text-white pl-2">
        <option value="">Set priority…</option>
        <?php foreach (array_keys(TH_PRIORITY) as $s): ?><option><?= $s ?></option><?php endforeach ?>
      </select>
      <button data-bulk-submit="merge" class="h-7 px-2.5 rounded-md bg-ink-600 border border-white/10 text-[12px] hover:bg-ink-500">Merge</button>
      <button data-bulk-submit="delete" class="h-7 px-2.5 rounded-md text-[12px] text-alert-400 hover:bg-white/10">Delete</button>
      <button data-clear-selection class="ml-auto text-[12px] text-white/60 hover:text-white">Clear</button>
    </div>

    <!-- header row -->
    <div class="flex items-center gap-3 px-4 h-9 bg-canvas border-b border-line text-[11px] font-semibold uppercase tracking-[.09em] text-faint">
      <input type="checkbox" data-check-all class="w-[15px] h-[15px] rounded-sm border-line" aria-label="Select all">
      <span class="flex-1">Ticket · <span class="font-mono normal-case tracking-normal"><?= $total ?></span> results</span>
      <span class="hidden xl:block w-[150px]">Requester</span>
      <span class="hidden sm:block w-[86px]">Status</span>
      <span class="hidden lg:block w-[130px]">Assignee</span>
      <span class="w-[86px] text-right">SLA</span>
      <span class="w-7"></span>
    </div>

    <!-- rows -->
    <div>
      <?php if (! $list): ?>
        <?= th_empty('inbox', 'Nothing matches these filters', 'Try a broader saved view, or clear the search to see the full queue.',
            '<a href="' . site_url('app/tickets') . '" class="inline-flex items-center gap-1.5 h-8 px-2.5 rounded-lg border border-line bg-white text-[12.5px] font-medium text-ink-500 hover:bg-canvas">' . th_icon('refresh', 'w-3.5 h-3.5') . 'Clear filters</a>') ?>
      <?php endif ?>
      <?php foreach ($list as $t):
        $s = th_sla($t);
        $requester = $users[(int) $t['requester_id']] ?? null;
        $assignee  = $t['agent_id'] ? ($users[(int) $t['agent_id']] ?? null) : null;
        $last = $lastMsgs[(int) $t['id']] ?? null;
        $menu = [
            'code' => $t['code'],
            'subject' => $t['subject'],
            'open' => th_is_open($t),
        ];
      ?>
      <div data-row class="relative border-b border-line row-hover">
        <div class="flex items-center gap-3 px-4 py-2.5">
          <input type="checkbox" data-check="<?= $t['id'] ?>" class="w-[15px] h-[15px] rounded-sm border-line shrink-0" aria-label="Select <?= esc($t['code'], 'attr') ?>">
          <i class="led <?= TH_PRIORITY[$t['priority']]['dot'] ?? 'bg-[#98A1B0]' ?>" title="<?= esc($t['priority'], 'attr') ?> priority"></i>
          <a href="<?= site_url('app/tickets/' . $t['code']) ?>" class="min-w-0 flex-1 text-left">
            <div class="flex items-center gap-2">
              <span class="font-mono text-[11px] text-faint"><?= esc($t['code']) ?></span>
              <?php if ((int) $t['escalated']): ?><span class="inline-flex items-center gap-1 h-[17px] px-1.5 rounded-sm bg-alert-50 text-alert text-[10px] font-bold uppercase tracking-wide">Escalated</span><?php endif ?>
              <span class="text-[13.5px] font-medium text-ink truncate"><?= esc($t['subject']) ?></span>
            </div>
            <div class="flex items-center gap-2 mt-1 text-[11.5px] text-faint">
              <span class="truncate"><?= esc($requester['name'] ?? '—') ?> · <?= esc($t['type']) ?> · <?= esc($t['category']) ?></span>
              <?php if ($last): ?><span class="hidden md:inline truncate">· <?= $last['kind'] === 'note' ? 'Private note' : ($last['kind'] === 'system' ? 'System' : 'Reply') ?> <?= th_rel($last['created_at']) ?></span><?php endif ?>
            </div>
          </a>
          <div class="hidden xl:flex items-center gap-1.5 w-[150px] shrink-0">
            <?= th_avatar($requester, 24) ?><span class="text-[12px] text-muted truncate"><?= esc($requester['name'] ?? '—') ?></span>
          </div>
          <div class="hidden sm:block w-[86px] shrink-0"><?= th_status_chip($t['status']) ?></div>
          <div class="hidden lg:flex w-[130px] shrink-0 items-center gap-1.5">
            <?php if ($assignee): ?>
              <?= th_avatar($assignee, 24) ?><span class="text-[12px] text-muted truncate"><?= esc(explode(' ', $assignee['name'])[0]) ?></span>
            <?php else: ?>
              <span class="text-[12px] text-faint italic">Unassigned</span>
            <?php endif ?>
          </div>
          <div class="w-[86px] shrink-0 text-right">
            <div class="font-mono text-[12px] tick font-medium" style="color:<?= th_sla_color($t) ?>"><?= th_sla_label($t) ?></div>
            <div class="text-[10.5px] text-faint"><?= $s['done'] ? 'resolution' : 'to breach' ?></div>
          </div>
          <button data-row-menu='<?= esc(json_encode($menu), 'attr') ?>' class="w-7 h-7 grid place-items-center rounded-md text-faint hover:text-ink hover:bg-canvas shrink-0" aria-label="Row actions"><?= th_icon('dots', 'w-4 h-4') ?></button>
        </div>
        <div class="burn"><i style="width:<?= $s['pct'] ?>%;background:<?= th_sla_color($t) ?>"></i></div>
      </div>
      <?php endforeach ?>
    </div>
    <?= th_pager($total, $page, $perPage, site_url('app/tickets'), array_filter(['view' => $view] + $f)) ?>
  </section>
</div>
<?= $this->endSection() ?>

<?= $this->section('modals') ?>
<template id="tpl-saveView">
  <form method="post" action="<?= site_url('app/tickets/views') ?>" data-modal-title="Save this view" data-modal-sub="Keeps the filters you have applied right now" data-submit="Save view">
    <?= csrf_field() ?>
    <?php // Carry the current filters through as hidden fields. ?>
    <input type="hidden" name="view" value="<?= esc($view, 'attr') ?>">
    <?php foreach (['q', 'status', 'priority', 'type', 'category', 'source', 'group', 'agent', 'org', 'sort'] as $k): ?>
    <input type="hidden" name="<?= $k ?>" value="<?= esc($filters[$k] ?? '', 'attr') ?>">
    <?php endforeach ?>
    <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Name<span class="text-alert"> *</span></label>
      <input name="name" required maxlength="40" placeholder="e.g. My urgent network tickets" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
    <label class="flex items-center gap-2 mt-3 text-[12.5px] text-ink-500">
      <input type="checkbox" name="shared" value="1" class="w-[15px] h-[15px] rounded-sm border-line">
      Share with the whole team
    </label>
  </form>
</template>
<?= $this->endSection() ?>
