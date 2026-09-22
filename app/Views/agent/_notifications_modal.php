<div data-modal-title="Notifications" data-modal-sub="What needs you next" data-modal-width="max-w-md">
  <div class="-mx-2">
    <?php
      $row = static function (string $ic, string $tone, string $title, string $sub, ?string $url = null) {
          $tag = $url ? 'a' : 'div';
          $href = $url ? ' href="' . $url . '"' : '';

          return '<' . $tag . $href . ' class="w-full flex gap-3 p-3 rounded-lg hover:bg-canvas text-left">'
              . '<span class="w-7 h-7 rounded-lg grid place-items-center shrink-0 ' . $tone . '">' . th_icon($ic, 'w-3.5 h-3.5') . '</span>'
              . '<span class="min-w-0"><span class="block text-[13px] font-medium text-ink">' . esc($title) . '</span>'
              . '<span class="block text-[12px] text-muted truncate">' . esc($sub) . '</span></span></' . $tag . '>';
      };
      // Persisted rows post to the read endpoint, which marks them and follows the link.
      $kindStyle = [
          'assigned'  => ['user', 'bg-brand-50 text-brand'],
          'reply'     => ['send', 'bg-canvas text-muted'],
          'escalated' => ['warn', 'bg-alert-50 text-alert'],
          'approval'  => ['lock', 'bg-signal-50 text-signal'],
          'task'      => ['check', 'bg-canvas text-muted'],
      ];
      $persisted = static function (array $n, bool $isRead) use ($kindStyle) {
          [$ic, $tone] = $kindStyle[$n['kind']] ?? ['bell', 'bg-canvas text-muted'];
          $sub = ($n['body'] ? $n['body'] . ' · ' : '') . th_rel($n['created_at']);

          return '<form method="post" action="' . site_url('app/notifications/' . $n['id'] . '/read') . '" class="m-0">' . csrf_field()
              . '<button type="submit" class="w-full flex gap-3 p-3 rounded-lg hover:bg-canvas text-left ' . ($isRead ? 'opacity-60' : '') . '">'
              . '<span class="w-7 h-7 rounded-lg grid place-items-center shrink-0 ' . $tone . '">' . th_icon($ic, 'w-3.5 h-3.5') . '</span>'
              . '<span class="min-w-0 flex-1"><span class="block text-[13px] font-medium text-ink">' . esc($n['title']) . '</span>'
              . '<span class="block text-[12px] text-muted truncate">' . esc($sub) . '</span></span>'
              . (! $isRead ? '<i class="w-2 h-2 mt-1.5 rounded-full bg-brand shrink-0"></i>' : '')
              . '</button></form>';
      };

      if ($unread) {
          echo '<div class="flex items-center justify-between px-3 pt-1 pb-1.5">'
              . '<span class="text-[11px] font-semibold uppercase tracking-[.09em] text-faint">New · ' . count($unread) . '</span>'
              . '<form method="post" action="' . site_url('app/notifications/read') . '" class="m-0">' . csrf_field()
              . '<button type="submit" class="text-[12px] text-muted hover:text-ink">Mark all read</button></form></div>';
          foreach ($unread as $n) {
              echo $persisted($n, false);
          }
      }
      foreach ($recent as $n) {
          echo $persisted($n, true);
      }

      if ($overdue || $mentions || $approvals || $announcement) {
          echo '<div class="px-3 pt-3 pb-1.5 text-[11px] font-semibold uppercase tracking-[.09em] text-faint">Needs attention</div>';
      }
      foreach ($overdue as $t) {
          echo $row('warn', 'bg-alert-50 text-alert', 'SLA breached', $t['code'] . ' · ' . $t['subject'], site_url('app/tickets/' . $t['code']));
      }
      foreach ($mentions as $t) {
          echo $row('clock', 'bg-signal-50 text-signal', 'Pending your follow-up', $t['code'] . ' · ' . $t['subject'], site_url('app/tickets/' . $t['code']));
      }
      foreach ($approvals as $c) {
          echo $row('branch', 'bg-violet-50 text-violet', 'Change awaiting approval', $c['code'] . ' · ' . $c['title'], site_url('app/changes'));
      }
      if ($announcement) {
          echo $row('bell', 'bg-canvas text-muted', $announcement['title'], th_rel($announcement['created_at']));
      }
      if (! $unread && ! $recent && ! $overdue && ! $mentions && ! $approvals && ! $announcement) {
          echo '<p class="p-3 text-[13px] text-muted">All caught up.</p>';
      }
    ?>
  </div>
</div>
