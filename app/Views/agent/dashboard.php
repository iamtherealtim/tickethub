<?= $this->extend('layouts/agent') ?>
<?= $this->section('content') ?>

<?php
helper('i18n');
// The greeting follows the display timezone, not the server clock.
$hour  = $localHour ?? (int) th_local(time())->format('G');
$greet = lang('Dashboard.greeting.' . ($hour < 12 ? 'morning' : ($hour < 18 ? 'afternoon' : 'evening')));
$H     = 3600;
$atRisk  = $atRisk ?? [];
$canPost = $canPost ?? false;
?>
<div class="p-5 max-w-[1400px] mx-auto fade-in">
  <div class="flex flex-wrap items-end justify-between gap-3 mb-5">
    <div>
      <h1 class="font-display text-[22px] font-semibold text-ink"><?= $greet ?>, <?= esc(explode(' ', $me['name'])[0]) ?></h1>
      <?php
        $scopeLabel = lang('Dashboard.scope.groups', [count($groups)]);
        if (empty($isAdmin)) {
            foreach ($groups as $g) {
                if ((int) $g['id'] === (int) ($me['group_id'] ?? 0)) {
                    $scopeLabel = lang('Dashboard.scope.queue', [$g['name']]);
                }
            }
        }
      ?>
      <p class="text-[13px] text-muted mt-1"><?= esc(lang('Dashboard.scope.line', ['date' => th_day(time()), 'count' => count($openTickets), 'scope' => $scopeLabel])) ?></p>
    </div>
    <div class="flex items-center gap-2">
      <a href="<?= site_url('app/dashboard') ?>" class="inline-flex items-center gap-1.5 h-8 px-2.5 rounded-lg border border-line bg-white text-[12.5px] font-medium text-ink-500 hover:bg-canvas"><?= th_icon('refresh', 'w-3.5 h-3.5') ?><?= lang('Dashboard.btn.refresh') ?></a>
      <?= th_btn(esc(lang('Dashboard.btn.newTicket')), 'data-modal="newTicket"', 'brand', 'plus') ?>
    </div>
  </div>

  <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-3 mb-4">
    <?= th_kpi(esc(lang('Dashboard.kpi.unassigned')), count($unassigned), esc(lang('Dashboard.kpi.unassignedSub')), count($unassigned) ? 'signal' : 'ink', site_url('app/tickets?view=unassigned')) ?>
    <?= th_kpi(esc(lang('Dashboard.kpi.open')), count($openTickets), esc(lang('Dashboard.kpi.openSub', [$newToday])), 'ink', site_url('app/tickets')) ?>
    <?= th_kpi(esc(lang('Dashboard.kpi.due4h')), count($soon4), esc(lang('Dashboard.kpi.due4hSub')), 'signal', site_url('app/tickets')) ?>
    <?= th_kpi(esc(lang('Dashboard.kpi.pastDue')), count($overdue), esc(lang(count($overdue) ? 'Dashboard.kpi.breached' : 'Dashboard.kpi.allWithin')) . ' · <span class="' . ($atRisk ? 'text-signal' : 'text-faint') . '">' . esc(lang('Dashboard.kpi.atRisk', [count($atRisk)])) . '</span>', count($overdue) ? 'alert' : 'brand', site_url('app/tickets?view=overdue')) ?>
    <?= th_kpi(esc(lang('Dashboard.kpi.resolved24h')), $resolvedToday, esc($fcr === null ? lang('Dashboard.kpi.noResolved') : lang('Dashboard.kpi.fcr', [$fcr])), 'brand', site_url('app/tickets?view=closed')) ?>
  </div>

  <!-- Breach horizon -->
  <div class="mb-4">
    <?php
      $beyond = count($openTickets) - count($overdue) - count($soon12);
      $marks = '';
      foreach ([0, 3, 6, 9, 12] as $h) {
          $marks .= '<div class="absolute top-0 bottom-0 border-l border-line" style="left:' . ($h / 12) * 100 . '%">'
              . '<span class="absolute -top-[18px] -translate-x-1/2 font-mono text-[10px] text-faint">' . esc($h === 0 ? lang('Dashboard.horizon.now') : lang('Dashboard.horizon.plusH', [$h])) . '</span></div>';
      }
      $pips = '';
      foreach ($soon12 as $t) {
          $s = th_sla($t);
          $left = min(98, ($s['remaining'] / $H / 12) * 100);
          $p = TH_PRIORITY[$t['priority']];
          $pips .= '<a href="' . site_url('app/tickets/' . $t['code']) . '" title="' . esc($t['code'] . ' · ' . $t['subject'] . ' · ' . lang('Dashboard.horizon.dueIn', [th_dur($s['remaining'])]), 'attr') . '"'
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
          th_card_head(esc(lang('Dashboard.horizon.title')), '<span class="text-muted">' . esc(lang('Dashboard.horizon.summary', [count($soon12)])) . ' · <span class="text-alert font-semibold">' . esc(lang('Dashboard.horizon.pastDue', [count($overdue)])) . '</span> · ' . esc(lang('Dashboard.horizon.beyond', [$beyond])) . '</span>')
          . '<div class="p-4 pt-7"><div class="flex gap-3">'
          . '<div class="w-[86px] shrink-0 rounded-lg stripe border border-alert-100 grid place-items-center h-12"><span class="font-mono text-[15px] text-alert">' . count($overdue) . '</span></div>'
          . '<div class="relative flex-1 h-12 rounded-lg border border-line bg-canvas overflow-visible">' . $marks . $pips
          . (count($soon12) === 0 ? '<span class="absolute inset-0 grid place-items-center text-[12px] text-faint">' . esc(lang('Dashboard.horizon.nothing')) . '</span>' : '')
          . '</div></div>'
          . ($lateList ? '<div class="mt-4 pt-3 border-t border-line space-y-2">' . $lateList
              . (count($overdue) > 4 ? '<a href="' . site_url('app/tickets?view=overdue') . '" class="block text-[12px] text-brand font-medium hover:underline">' . esc(lang('Dashboard.horizon.seeAll', [count($overdue)])) . '</a>' : '')
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
                . '<div class="w-[9px] rounded-t-[2px] bg-ink-600 group-hover:bg-ink transition-all" style="height:' . $hc . '%" title="' . esc(lang('Dashboard.volume.createdN', [$v['created']]), 'attr') . '"></div>'
                . '<div class="w-[9px] rounded-t-[2px] bg-brand-100 group-hover:bg-brand transition-all" style="height:' . $hr . '%" title="' . esc(lang('Dashboard.volume.resolvedN', [$v['resolved']]), 'attr') . '"></div>'
                . '</div><span class="text-[10.5px] text-faint">' . $v['day'] . '</span></div>';
        }
        echo th_card(th_card_head(esc(lang('Dashboard.volume.title')),
            '<span class="flex items-center gap-1.5 text-muted"><i class="w-2 h-2 rounded-xs bg-ink-600"></i>' . esc(lang('Dashboard.volume.created')) . '</span>'
            . '<span class="flex items-center gap-1.5 text-muted"><i class="w-2 h-2 rounded-xs bg-brand-100"></i>' . esc(lang('Dashboard.volume.resolved')) . '</span>')
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
              . '<span class="font-mono text-[11.5px] text-muted tick">' . $r['n'] . ($r['late'] ? '<span class="text-alert"> · ' . esc(lang('Dashboard.workload.late', [$r['late']])) . '</span>' : '') . '</span>'
              . '</div><div class="h-1.5 mt-1.5 rounded-full bg-canvas overflow-hidden">'
              . '<div class="h-full rounded-full ' . ($r['late'] ? 'bg-signal' : 'bg-brand') . '" style="width:' . ($r['n'] / $maxW) * 100 . '%"></div>'
              . '</div></div></div>';
      }
      echo th_card(th_card_head(esc(lang('Dashboard.workload.title')), '<span class="text-muted">' . esc(lang('Dashboard.workload.sub')) . '</span>') . '<div class="p-4 space-y-3">' . $rows . '</div>');
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
                . '<span class="font-mono text-[11px] tick shrink-0 ' . (['ok' => 'text-brand', 'met' => 'text-brand', 'warn' => 'text-signal'][$s['state']] ?? 'text-alert') . '">' . th_sla_label($t) . '</span></a>';
        };
        $mineHtml = $myQueue
            ? implode('', array_map($rowFor, array_slice($myQueue, 0, 5)))
            : '<p class="px-4 py-6 text-[13px] text-muted text-center">' . esc(lang('Dashboard.queue.emptyMine')) . '</p>';
        $unHtml = $unassigned
            ? implode('', array_map($rowFor, array_slice($unassigned, 0, 5)))
            : '<p class="px-4 py-6 text-[13px] text-muted text-center">' . esc(lang('Dashboard.queue.emptyPool')) . '</p>';
        echo th_card(th_card_head(esc(lang('Dashboard.queue.title')), '<a href="' . site_url('app/tickets') . '" class="text-brand font-medium hover:underline">' . esc(lang('Dashboard.queue.allTickets')) . '</a>')
            . '<div>' . $mineHtml . '</div>'
            . '<div class="px-4 h-9 flex items-center bg-canvas border-y border-line">'
            . '<span class="text-[11px] font-semibold uppercase tracking-[.09em] text-faint">' . esc(lang('Dashboard.queue.unassigned', [count($unassigned)])) . '</span>'
            . '<a href="' . site_url('app/tickets?view=unassigned') . '" class="ml-auto text-[12px] text-brand font-medium hover:underline">' . esc(lang('Dashboard.queue.claim')) . '</a>'
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
        echo th_card(th_card_head(esc(lang('Dashboard.csat.title')), '<span class="text-muted">' . esc(lang('Dashboard.csat.responses', [count($rated)])) . '</span>')
            . '<div class="p-4"><div class="flex items-baseline gap-2"><span class="font-mono text-[28px] text-ink tick">' . number_format($csatAvg, 1) . '</span><span class="text-[12px] text-muted">' . esc(lang('Dashboard.csat.outOf')) . '</span></div>'
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
            // Edit and remove are supervisory; everyone else just reads.
            $controls = $canPost
                ? '<span class="absolute top-2 right-2 flex items-center gap-0.5">'
                    . '<button type="button" data-modal="editAnnouncement-' . $a['id'] . '" class="w-6 h-6 grid place-items-center rounded-md text-faint hover:text-ink hover:bg-white/60" title="' . esc(lang('Dashboard.ann.edit'), 'attr') . '">' . th_icon('edit', 'w-3.5 h-3.5') . '</button>'
                    . '<form method="post" action="' . site_url('app/announcements/' . $a['id'] . '/delete') . '"'
                    . ' data-confirm="' . esc(lang('Dashboard.ann.confirmRemove'), 'attr') . '" data-confirm-label="' . esc(lang('Dashboard.ann.remove'), 'attr') . '">' . csrf_field()
                    . '<button type="submit" class="w-6 h-6 grid place-items-center rounded-md text-faint hover:text-alert hover:bg-white/60" title="' . esc(lang('Dashboard.ann.remove'), 'attr') . '">' . th_icon('x', 'w-3.5 h-3.5') . '</button></form></span>'
                : '';
            $annHtml .= '<div class="relative rounded-lg border p-3 ' . ($tone[$a['level']] ?? $tone['info']) . '">' . $controls
                . '<div class="text-[13px] font-semibold text-ink pr-12">' . esc($a['title']) . '</div>'
                . '<p class="text-[12.5px] text-muted mt-1 leading-relaxed">' . esc($a['body']) . '</p>'
                . '<div class="text-[11px] text-faint mt-2">' . esc($by['name'] ?? '—') . ' · ' . th_rel($a['created_at'])
                . (! empty($a['expires_at']) ? ' · ' . esc(lang('Dashboard.ann.expires', [th_day($a['expires_at'])])) : '') . '</div></div>';
        }
        if (! $annHtml) {
            $annHtml = '<p class="text-[13px] text-muted text-center py-4">' . esc(lang('Dashboard.ann.empty')) . '</p>';
        }
        echo th_card(th_card_head(esc(lang('Dashboard.ann.title')), $canPost ? th_btn(esc(lang('Dashboard.btn.post')), 'data-modal="newAnnouncement"', 'ghost', 'plus') : '')
            . '<div class="p-4 space-y-2.5">' . $annHtml . '</div>');
      ?>
    </div>
  </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('modals') ?>
<template id="tpl-newAnnouncement">
  <form method="post" action="<?= site_url('app/announcements') ?>" data-modal-title="<?= esc(lang('Dashboard.ann.newTitle'), 'attr') ?>" data-submit="<?= esc(lang('Dashboard.btn.post'), 'attr') ?>">
    <?= csrf_field() ?>
    <div class="grid sm:grid-cols-2 gap-3.5">
      <div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5"><?= lang('Dashboard.ann.headline') ?><span class="text-alert"> *</span></label>
        <input name="title" required class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5"><?= lang('Dashboard.ann.severity') ?></label>
        <select name="level" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px]">
          <?php foreach (['info', 'warn', 'alert'] as $lv): ?><option value="<?= $lv ?>"><?= esc(lang('Common.level.' . $lv)) ?></option><?php endforeach ?>
        </select></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5"><?= lang('Dashboard.ann.expiresLabel') ?></label>
        <input name="expires_at" type="date" min="<?= th_local(time())->format('Y-m-d') ?>" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] font-mono focus:border-brand">
        <p class="text-[11.5px] text-faint mt-1"><?= lang('Dashboard.ann.expiresHint') ?></p></div>
      <div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5"><?= lang('Dashboard.ann.message') ?><span class="text-alert"> *</span></label>
        <textarea name="body" rows="4" required placeholder="<?= esc(lang('Dashboard.ann.messagePlaceholder'), 'attr') ?>" class="w-full px-2.5 py-2 rounded-lg border border-line text-[13px] leading-relaxed focus:border-brand"></textarea></div>
    </div>
  </form>
</template>
<?php if ($canPost): foreach ($announcements as $a): ?>
<template id="tpl-editAnnouncement-<?= $a['id'] ?>">
  <form method="post" action="<?= site_url('app/announcements/' . $a['id']) ?>" data-modal-title="<?= esc(lang('Dashboard.ann.editTitle'), 'attr') ?>" data-submit="<?= esc(lang('Dashboard.btn.save'), 'attr') ?>">
    <?= csrf_field() ?>
    <div class="grid sm:grid-cols-2 gap-3.5">
      <div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5"><?= lang('Dashboard.ann.headline') ?><span class="text-alert"> *</span></label>
        <input name="title" required value="<?= esc($a['title'], 'attr') ?>" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5"><?= lang('Dashboard.ann.severity') ?></label>
        <select name="level" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px]">
          <?php foreach (['info', 'warn', 'alert'] as $lv): ?><option value="<?= $lv ?>" <?= $a['level'] === $lv ? 'selected' : '' ?>><?= esc(lang('Common.level.' . $lv)) ?></option><?php endforeach ?>
        </select></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5"><?= lang('Dashboard.ann.expiresLabel') ?></label>
        <input name="expires_at" type="date" value="<?= ! empty($a['expires_at']) ? th_local($a['expires_at'])->format('Y-m-d') : '' ?>" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] font-mono focus:border-brand">
        <p class="text-[11.5px] text-faint mt-1"><?= lang('Dashboard.ann.expiresKeep') ?></p></div>
      <div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5"><?= lang('Dashboard.ann.message') ?><span class="text-alert"> *</span></label>
        <textarea name="body" rows="4" required class="w-full px-2.5 py-2 rounded-lg border border-line text-[13px] leading-relaxed focus:border-brand"><?= esc($a['body']) ?></textarea></div>
    </div>
  </form>
</template>
<?php endforeach; endif ?>
<?= $this->endSection() ?>
