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
      if (! $overdue && ! $mentions && ! $approvals && ! $announcement) {
          echo '<p class="p-3 text-[13px] text-muted">All caught up.</p>';
      }
    ?>
  </div>
</div>
