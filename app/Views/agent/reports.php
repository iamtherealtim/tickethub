<?= $this->extend('layouts/agent') ?>
<?= $this->section('content') ?>

<div class="p-5 max-w-[1400px] mx-auto fade-in">
  <div class="flex flex-wrap items-end justify-between gap-3 mb-5">
    <div>
      <h1 class="font-display text-[22px] font-semibold text-ink">Reports</h1>
      <?php
        $scope = 'all groups';
        if (empty($isAdmin)) {
            foreach ($groups as $g) {
                if ((int) $g['id'] === (int) ($me['group_id'] ?? 0)) {
                    $scope = $g['name'];
                }
            }
        }
      ?>
      <p class="text-[13px] text-muted mt-1">Last <?= $range ?> days · <?= esc($scope) ?> · <span class="font-mono"><?= $ticketsInRange ?></span> ticket(s) in range</p>
    </div>
    <div class="flex items-center gap-2">
      <form method="get" action="<?= site_url('app/reports') ?>" class="flex items-center gap-2">
        <?= th_select('range', (string) $range, [['7', 'Last 7 days'], ['30', 'Last 30 days'], ['90', 'Last quarter']], 'data-autosubmit') ?>
      </form>
      <?php // Carries the current window through, so the file matches the screen. ?>
      <a href="<?= site_url('app/reports/export?range=' . $range) ?>"
         class="inline-flex items-center gap-1.5 h-8 px-2.5 rounded-lg border border-line bg-white text-[12.5px] font-medium text-ink-500 hover:bg-canvas transition"><?= th_icon('ext', 'w-3.5 h-3.5') ?>Export</a>
    </div>
  </div>

  <?php if ($ticketsInRange === 0): ?>
    <?= th_card(th_empty('chart', 'No tickets in this window', 'Nothing was raised in the last ' . $range . ' days for this scope. Try a longer range.')) ?>
  <?php else: ?>
  <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-3 mb-4">
    <?= th_kpi('Median first response', $medianFr ? th_dur($medianFr) : '—', 'Across answered tickets', 'brand') ?>
    <?= th_kpi('Median resolution', $medianRes ? th_dur($medianRes) : '—', 'Across resolved tickets', 'brand') ?>
    <?= th_kpi('SLA attainment', $slaPct === null ? '—' : $slaPct . '%', $slaPct === null ? 'Nothing resolved yet' : 'Resolution, all priorities', ($slaPct ?? 0) >= 90 ? 'brand' : 'signal') ?>
    <?= th_kpi('Resolved total', $resolvedN, 'In the selected window') ?>
    <?php // Low is good here — a high rate means "Resolved" is being called too early. ?>
    <?= th_kpi(
        'Reopen rate',
        $reopenPct === null ? '—' : $reopenPct . '%',
        $reopenPct === null ? 'Nothing resolved yet' : $reopenedN . ' of ' . $resolvedN . ' resolved',
        ($reopenPct ?? 0) > 10 ? 'alert' : 'ink'
    ) ?>
    <?= th_kpi('Time logged', $timeTotal ? th_minutes($timeTotal) : '—', $timeTotal ? 'Across the window' : 'Nothing logged yet', 'brand') ?>
    <?= th_kpi('Effort per ticket', $timePerTicket ? th_minutes($timePerTicket) : '—', 'Mean across all raised') ?>
  </div>

  <?php if ($timeByAgent || $timeByCategory): ?>
  <div class="grid lg:grid-cols-2 gap-4 mb-4">
    <?php
      // Where the team's hours actually went - the question the app could not
      // answer at all before effort was recorded.
      $bar = static function (array $data, string $title): string {
          $max  = max(1, ...array_values($data ?: [1]));
          $html = '';
          foreach ($data as $k => $v) {
              $html .= '<div class="flex items-center gap-3"><span class="text-[12.5px] text-ink-500 w-[110px] truncate">' . esc($k) . '</span>'
                  . '<div class="flex-1 h-5 rounded bg-canvas overflow-hidden"><div class="h-full bg-brand rounded" style="width:' . ($v / $max) * 100 . '%"></div></div>'
                  . '<span class="font-mono text-[12px] text-muted w-[52px] text-right">' . th_minutes((int) $v) . '</span></div>';
          }

          return th_card(th_card_head($title) . '<div class="p-4 space-y-2.5">' . ($html ?: '<p class="text-[13px] text-muted">Nothing logged.</p>') . '</div>');
      };
      echo $bar($timeByAgent, 'Time by agent');
      echo $bar($timeByCategory, 'Time by category');
    ?>
  </div>
  <?php endif ?>

  <div class="grid lg:grid-cols-2 gap-4 mb-4">
    <?php
      $maxSrc = max(1, ...array_values($srcs ?: [1]));
      $srcHtml = '';
      foreach ($srcs as $k => $v) {
          $srcHtml .= '<div class="flex items-center gap-3"><span class="text-[12.5px] text-ink-500 w-[74px]">' . esc($k) . '</span>'
              . '<div class="flex-1 h-5 rounded bg-canvas overflow-hidden"><div class="h-full bg-ink-600 rounded" style="width:' . ($v / $maxSrc) * 100 . '%"></div></div>'
              . '<span class="font-mono text-[12px] text-muted w-6 text-right">' . $v . '</span></div>';
      }
      echo th_card(th_card_head('Where tickets come from') . '<div class="p-4 space-y-2.5">' . $srcHtml . '</div>');

      // Donut
      $palette = ['#0E7C6B', '#B8760B', '#5A4FB0', '#BC332D', '#4C6EF5', '#8B93A3', '#0B6558', '#E9A23B'];
      $total = max(1, array_sum($cats));
      $arcs = '';
      $legend = '';
      $offset = 0;
      $i = 0;
      foreach ($cats as $k => $v) {
          $color = $palette[$i % count($palette)];
          $frac = $v / $total;
          $dash = $frac * 339.3;
          $arcs .= '<circle cx="60" cy="60" r="54" fill="none" stroke="' . $color . '" stroke-width="12"'
              . ' stroke-dasharray="' . $dash . ' ' . (339.3 - $dash) . '" stroke-dashoffset="' . (-$offset) . '" transform="rotate(-90 60 60)"><title>' . esc($k) . ': ' . $v . '</title></circle>';
          $legend .= '<div class="flex items-center gap-2 text-[12.5px]"><i class="w-2.5 h-2.5 rounded-sm" style="background:' . $color . '"></i>'
              . '<span class="text-ink-500 flex-1">' . esc($k) . '</span><span class="font-mono text-muted">' . $v . '</span>'
              . '<span class="font-mono text-faint w-9 text-right">' . round($v / $total * 100) . '%</span></div>';
          $offset += $dash;
          $i++;
      }
      $donut = '<div class="flex items-center gap-5 flex-wrap">'
          . '<svg viewBox="0 0 120 120" class="w-[120px] h-[120px] shrink-0"><circle cx="60" cy="60" r="54" fill="none" stroke="#EEF0F4" stroke-width="12"/>' . $arcs
          . '<text x="60" y="58" text-anchor="middle" class="font-mono" font-size="19" fill="#10141C">' . $total . '</text>'
          . '<text x="60" y="72" text-anchor="middle" font-size="8.5" fill="#8B93A3">tickets</text></svg>'
          . '<div class="space-y-1.5 flex-1 min-w-[140px]">' . $legend . '</div></div>';
      echo th_card(th_card_head('Category mix') . '<div class="p-4">' . $donut . '</div>');
    ?>
  </div>

  <?php
    $maxAvg = 1;
    foreach ($groupPerf as $p) {
        $maxAvg = max($maxAvg, $p['avg']);
    }
    $rows = '';
    foreach ($groupPerf as $p) {
        $tone = $p['pct'] >= 90 ? 'text-brand' : ($p['pct'] >= 75 ? 'text-signal' : 'text-alert');
        $rows .= '<div><div class="flex items-center justify-between text-[12.5px] mb-1.5">'
            . '<span class="font-medium text-ink">' . esc($p['g']['name']) . '</span>'
            . '<span class="flex items-center gap-3 font-mono text-[11.5px]">'
            . '<span class="text-muted">' . $p['n'] . ' tickets</span>'
            . '<span class="text-ink-500">' . ($p['avg'] ? th_dur($p['avg']) : '—') . '</span>'
            . '<span class="' . $tone . '">' . $p['pct'] . '% SLA</span></span></div>'
            . '<div class="h-2 rounded-full bg-canvas overflow-hidden"><div class="h-full rounded-full bg-brand" style="width:' . ($p['avg'] / $maxAvg) * 100 . '%"></div></div></div>';
    }
    echo th_card(th_card_head('Group performance', '<span class="text-muted">Avg resolution time and SLA attainment</span>')
        . '<div class="p-4 space-y-3.5">' . $rows . '</div>');
  ?>
  <?php endif ?>
</div>
<?= $this->endSection() ?>
