<?php

namespace App\Controllers\Admin;

use App\Commands\Retention;
use App\Controllers\BaseController;
use App\Libraries\Audit;
use App\Libraries\Settings;

/** Admin → Data retention: saves the retention settings the cron command reads. */
class DataController extends BaseController
{
    public function save()
    {
        $p = $this->request->getPost();
        $days = static fn ($v) => (string) max(0, min(3650, (int) $v));
        Settings::saveMany([
            'retention_closed_days'           => $days($p['retention_closed_days'] ?? 0),
            'retention_purge_attachments'     => isset($p['retention_purge_attachments']) ? '1' : '0',
            'retention_audit_days'            => $days($p['retention_audit_days'] ?? 0),
            'retention_webhook_delivery_days' => $days($p['retention_webhook_delivery_days'] ?? 0),
            'retention_notification_days'     => $days($p['retention_notification_days'] ?? 0),
        ]);
        $s = Retention::settings();
        Audit::log('admin.retention_saved', 'closed=' . $s['closed_days'] . 'd attachments=' . ($s['purge_attachments'] ? 1 : 0)
            . ' audit=' . $s['audit_days'] . 'd webhooks=' . $s['webhook_days'] . 'd notifications=' . $s['notification_days'] . 'd');
        $this->toast('Retention settings saved');

        return redirect()->to('/app/admin/data');
    }
}
