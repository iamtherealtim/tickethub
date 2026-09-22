<?= $this->extend('layouts/agent') ?>
<?= $this->section('content') ?>

<?php
$hour  = (int) date('G');
$greet = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$H     = 3600;
?>
<div class="p-5 max-w-[1400px] mx-auto fade-in">
  <div class="flex flex-wrap items-end justify-between gap-3 mb-5">
    <div>
      <h1 class="font-display text-[22px] font-semibold text-ink"><?= $greet ?>, <?= esc(explode(' ', $me['name'])[0]) ?></h1>
      <?php
        $scopeLabel = count($groups) . ' groups';
        if (empty($isAdmin)) {
            foreach ($groups as $g) {
                if ((int) $g['id'] === (int) ($me['group_id'] ?? 0)) {
                    $scopeLabel = 'the ' . $g['name'] . ' queue';
                }
            }
        }
      ?>
      <p class="text-[13px] text-muted mt-1"><?= th_day(time()) ?> · <?= count($openTickets) ?> tickets open in <?= esc($scopeLabel) ?></p>
    </div>
    <div class="flex items-center gap-2">
      <a href="<?= site_url('app/dashboard') ?>" class="inline-flex items-center gap-1.5 h-8 px-2.5 rounded-lg border border-line bg-white text-[12.5px] font-medium text-ink-500 hover:bg-canvas"><?= th_icon('refresh', 'w-3.5 h-3.5') ?>Refresh</a>
      <?= th_btn('New ticket', 'data-modal="newTicket"', 'brand', 'plus') ?>
    </div>
  </div>

  <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-3 mb-4">
    <?= th_kpi('Unassigned', count($unassigned), 'Waiting for an owner', count($unassigned) ? 'signal' : 'ink', site_url('app/tickets?view=unassigned')) ?>
    <?= th_kpi('Open', count($openTickets), $newToday . ' new today', 'ink', site_url('app/tickets')) ?>
    <?= th_kpi('Due within 4h', count($soon4), 'Resolution SLA', 'signal', site_url('app/tickets')) ?>
    <?= th_kpi('Past due', count($overdue), count($overdue) ? 'Breached resolution SLA' : 'All within target', count($overdue) ? 'alert' : 'brand', site_url('app/tickets?view=overdue')) ?>
    <?= th_kpi('Resolved 24h', $resolvedToday, $fcr === null ? 'No resolved tickets yet' : 'First-contact rate ' . $fcr . '%', 'brand', site_url('app/tickets?view=closed')) ?>
  </div>

  <!-- Breach horizon -->
  <div class="mb-4">
    <?php
      $beyond = count($openTickets) - count($overdue) - count($soon12);
      $marks = '';
      foreach ([0, 3, 6, 9, 12] as $h) {
          $marks .= '<div class="absolute top-0 bottom-0 border-l border-line" style="left:' . ($h / 12) * 100 . '%">'
              . '<span class="absolute -top-[18px] -translate-x-1/2 font-mono text-[10px] text-faint">' . ($h === 0 ? 'now' : '+' . $h . 'h') . '</span></div>';
      }
      $pips = '';
      foreach ($soon12 as $t) {
          $s = th_sla($t);
          $left = min(98, ($s['remaining'] / $H / 12) * 100);
          $p = TH_PRIORITY[$t['priority']];
          $pips .= '<a href="' . site_url('app/tickets/' . $t['code']) . '" title="' . esc($t['code'] . ' · ' . $t['subject'] . ' · due in ' . th_dur($s['remaining']), 'attr') . '"'
              . ' class="absolute -translate-x-1/2 top-1/2 -translate-y-1/2 w-2.5 h-2.5 rounded-full ' . $p['dot'] . ' ring-[3px] ring-white hover:scale-150 transition" style="left:' . $left . '%"></a>';
      }
      $lateList = '';
      foreach (array_slice($overdue, 0, 4) as $t) {
          $lateList .= '<a href="' . site_url('app/tickets/' . $t['code']) . '" class="flex items-center gap-2 w-full text-left group">'
              . '<i class="led ' . TH_PRIORITY[$t['priority']]['dot'] . '"></i>'
              . '<span class="font-mono text-[11px] text-alert">' . esc($t['code']) . '</span>'
              . '<span class="text-[12px] text-ink-500 truncate flex-1 group-hover:underline">' . esc($t['subject']) . '</span>'
              . '<span class="font-mono text-[11px] text-alert tick">+' . th_dur(th_sla($t)['remaining']) . '</span></a>';
      }
      echo th_card(
          th_card_head('Breach horizon', '<span class="text-muted">' . count($soon12) . ' due in 12h · <span class="text-alert font-semibold">' . count($overdue) . ' past due</span> · ' . $beyond . ' beyond</span>')
          . '<div class="p-4 pt-7"><div class="flex gap-3">'
          . '<div class="w-[86px] shrink-0 rounded-lg stripe border border-alert-100 grid place-items-center h-12"><span class="font-mono text-[15px] text-alert">' . count($overdue) . '</span></div>'
          . '<div class="relative flex-1 h-12 rounded-lg border border-line bg-canvas overflow-visible">' . $marks . $pips
          . (count($soon12) === 0 ? '<span class="absolute inset-0 grid place-items-center text-[12px] text-faint">Nothing due in the next 12 hours</span>' : '')
          . '</div></div>'
          . ($lateList ? '<div class="mt-4 pt-3 border-t border-line space-y-2">' . $lateList
              . (count($overdue) > 4 ? '<a href="' . site_url('app/tickets?view=overdue') . '" class="block text-[12px] text-brand font-medium hover:underline">See all ' . count($overdue) . ' past due</a>' : '')
              . '</div>' : '')
          . '</div>'
      );
    ?>
  </div>

  <div class="grid lg:grid-cols-3 gap-4 mb-4">
    <div class="lg:col-span-2">
      <?php
        $max = 1;
        foreach ($volume as $v) {
            $max = max($max, $v['created'], $v['resolved']);
        }
        $bars = '';
        foreach ($volume as $v) {
            $hc = ($v['created'] / $max) * 100;
            $hr = ($v['resolved'] / $max) * 100;
            $bars .= '<div class="flex-1 flex flex-col items-center gap-1.5 group">'
                . '<div class="w-full flex items-end justify-center gap-[3px] h-[112px]">'
                . '<div class="w-[9px] rounded-t-[2px] bg-ink-600 group-hover:bg-ink transition-all" style="height:' . $hc . '%" title="' . $v['created'] . ' created"></div>'
                . '<div class="w-[9px] rounded-t-[2px] bg-brand-100 group-hover:bg-brand transition-all" style="height:' . $hr . '%" title="' . $v['resolved'] . ' resolved"></div>'
                . '</div><span class="text-[10.5px] text-faint">' . $v['day'] . '</span></div>';
        }
        echo th_card(th_card_head('Ticket volume',
            '<span class="flex items-center gap-1.5 text-muted"><i class="w-2 h-2 rounded-sm bg-ink-600"></i>Created</span>'
            . '<span class="flex items-center gap-1.5 text-muted"><i class="w-2 h-2 rounded-sm bg-brand-100"></i>Resolved</span>')
            . '<div class="p-4 flex gap-1 items-end">' . $bars . '</div>');
      ?>
    </div>
    <?php
      $maxW = 1;
      foreach ($workload as $r) {
          $maxW = max($maxW, $r['n']);
      }
      $rows = '';
      foreach ($workload as $r) {
          $rows .= '<div class="flex items-center gap-3">' . th_avatar($r['agent'], 26)
              . '<div class="min-w-0 flex-1"><div class="flex items-center justify-between gap-2">'
              . '<span class="text-[12.5px] font-medium text-ink truncate">' . esc($r['agent']['name']) . '</span>'
              . '<span class="font-mono text-[11.5px] text-muted tick">' . $r['n'] . ($r['late'] ? '<span class="text-alert"> · ' . $r['late'] . ' late</span>' : '') . '</span>'
              . '</div><div class="h-1.5 mt-1.5 rounded-full bg-canvas overflow-hidden">'
              . '<div class="h-full rounded-full ' . ($r['late'] ? 'bg-signal' : 'bg-brand') . '" style="width:' . ($r['n'] / $maxW) * 100 . '%"></div>'
              . '</div></div></div>';
      }
      echo th_card(th_card_head('Agent workload', '<span class="text-muted">Open by assignee</span>') . '<div class="p-4 space-y-3">' . $rows . '</div>');
    ?>
  </div>

  <div class="grid lg:grid-cols-3 gap-4">
    <div class="lg:col-span-2">
      <?php
        $rowFor = static function ($t) use ($users) {
            $s = th_sla($t);

            return '<a href="' . site_url('app/tickets/' . $t['code']) . '" class="w-full text-left px-4 py-2.5 row-hover border-b border-line last:border-0 flex items-center gap-3">'
                . '<i class="led ' . TH_PRIORITY[$t['priority']]['dot'] . '"></i>'
                . '<span class="font-mono text-[11px] text-faint w-[68px] shrink-0">' . esc($t['code']) . '</span>'
                . '<span class="text-[13px] text-ink truncate flex-1">' . esc($t['subject']) . '</span>'
                . '<span class="font-mono text-[11px] tick shrink-0" style="color:' . th_sla_color($t) . '">' . th_sla_label($t) . '</span></a>';
        };
        $mineHtml = $myQueue
            ? implode('', array_map($rowFor, array_slice($myQueue, 0, 5)))
            : '<p class="px-4 py-6 text-[13px] text-muted text-center">Nothing assigned to you. Take one from the unassigned pool below.</p>';
        $unHtml = $unassigned
            ? implode('', array_map($rowFor, array_slice($unassigned, 0, 5)))
            : '<p class="px-4 py-6 text-[13px] text-muted text-center">The pool is empty.</p>';
        echo th_card(th_card_head('My queue', '<a href="' . site_url('app/tickets') . '" class="text-brand font-medium hover:underline">All tickets</a>')
            . '<div>' . $mineHtml . '</div>'
            . '<div class="px-4 h-9 flex items-center bg-canvas border-y border-line">'
            . '<span class="text-[11px] font-semibold uppercase tracking-[.09em] text-faint">Unassigned · ' . count($unassigned) . '</span>'
            . '<a href="' . site_url('app/tickets?view=unassigned') . '" class="ml-auto text-[12px] text-brand font-medium hover:underline">Claim work</a>'
            . '</div><div>' . $unHtml . '</div>');
      ?>
    </div>
    <div class="space-y-4">
      <?php
        $stars = '';
        for ($i = 0; $i < 5; $i++) {
            $stars .= '<span class="' . ($i < round($csatAvg) ? 'text-signal-400' : 'text-line') . '">' . th_icon('star', 'w-3.5 h-3.5 fill-current') . '</span>';
        }
        $distHtml = '';
        foreach ($csatDist as $n => $c) {
            $distHtml .= '<div class="flex items-center gap-2"><span class="font-mono text-[11px] text-faint w-3">' . $n . '</span>'
                . '<div class="flex-1 h-1.5 rounded-full bg-canvas overflow-hidden"><div class="h-full bg-signal-400 rounded-full" style="width:' . ($rated ? ($c / count($rated)) * 100 : 0) . '%"></div></div>'
                . '<span class="font-mono text-[11px] text-faint w-4 text-right">' . $c . '</span></div>';
        }
        echo th_card(th_card_head('Satisfaction', '<span class="text-muted">' . count($rated) . ' responses</span>')
            . '<div class="p-4"><div class="flex items-baseline gap-2"><span class="font-mono text-[28px] text-ink tick">' . number_format($csatAvg, 1) . '</span><span class="text-[12px] text-muted">/ 5</span></div>'
            . '<div class="flex gap-0.5 mt-1">' . $stars . '</div>'
            . '<div class="mt-4 space-y-1.5">' . $distHtml . '</div>'
            // What people actually said. A number alone never told anyone what to fix.
            . ($csatComments
                ? '<div class="mt-4 pt-3 border-t border-line space-y-2">'
                    . implode('', array_map(static function ($t) {
                        return '<a href="' . site_url('app/tickets/' . $t['code']) . '" class="block hover:bg-canvas rounded-md -mx-1 px-1 py-0.5">'
                            . '<span class="flex items-center gap-1.5">'
                            . '<span class="font-mono text-[10.5px] ' . ((int) $t['csat_score'] <= 3 ? 'text-alert' : 'text-faint') . '">' . (int) $t['csat_score'] . '/5</span>'
                            . '<span class="font-mono text-[10.5px] text-faint">' . esc($t['code']) . '</span></span>'
                            . '<span class="block text-[12px] text-ink-500 leading-snug">' . esc($t['csat_comment']) . '</span></a>';
                    }, $csatComments))
                    . '</div>'
                : '')
            . '</div>');

        $tone = ['warn' => 'border-signal-100 bg-signal-50', 'alert' => 'border-alert-100 bg-alert-50', 'info' => 'border-line bg-canvas'];
        $annHtml = '';
        foreach ($announcements as $a) {
            $by = $users[(int) $a['user_id']] ?? null;
            $annHtml .= '<div class="relative rounded-lg border p-3 ' . $tone[$a['level']] . '">'
                . '<form method="post" action="' . site_url('app/announcements/' . $a['id'] . '/delete') . '"'
                . ' data-confirm="Remove this announcement for everyone?" data-confirm-label="Remove" class="absolute top-2 right-2">' . csrf_field()
                . '<button type="submit" class="w-6 h-6 grid place-items-center rounded-md text-faint hover:text-alert hover:bg-white/60" title="Remove">' . th_icon('x', 'w-3.5 h-3.5') . '</button></form>'
                . '<div class="text-[13px] font-semibold text-ink pr-6">' . esc($a['title']) . '</div>'
                . '<p class="text-[12.5px] text-muted mt-1 leading-relaxed">' . esc($a['body']) . '</p>'
                . '<div class="text-[11px] text-faint mt-2">' . esc($by['name'] ?? '—') . ' · ' . th_rel($a['created_at']) . '</div></div>';
        }
        echo th_card(th_card_head('Announcements', th_btn('Post', 'data-modal="newAnnouncement"', 'ghost', 'plus'))
            . '<div class="p-4 space-y-2.5">' . $annHtml . '</div>');
      ?>
    </div>
  </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('modals') ?>
<template id="tpl-newAnnouncement">
  <form method="post" action="<?= site_url('app/announcements') ?>" data-modal-title="Post an announcement" data-submit="Post">
    <?= csrf_field() ?>
    <div class="grid sm:grid-cols-2 gap-3.5">
      <div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Headline<span class="text-alert"> *</span></label>
        <input name="title" required class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Severity</label>
        <select name="level" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px]">
          <option value="info">Information</option><option value="warn">Warning</option><option value="alert">Urgent</option>
        </select></div>
      <div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Message<span class="text-alert"> *</span></label>
        <textarea name="body" rows="4" required placeholder="What is happening, who it affects, and what people should do." class="w-full px-2.5 py-2 rounded-lg border border-line text-[13px] leading-relaxed focus:border-brand"></textarea></div>
    </div>
  </form>
</template>
<?= $this->endSection() ?>
