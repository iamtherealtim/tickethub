<?php

/** Admin → Data retention tab. */
return [
    'label' => 'Data retention',
    'icon'  => 'trash',
    'order' => 135,
    'data'  => static function ($request, $me): array {
        try {
            $plan = \App\Commands\Retention::plan();
        } catch (\Throwable $e) {
            $plan = ['error' => $e->getMessage()];
        }
        $db = db_connect();

        return [
            'retentionPlan' => $plan,
            'retentionSettings' => \App\Commands\Retention::settings(),
            'retentionTotals' => [
                'closed'  => (int) $db->table('tickets')->where('status', 'Closed')->countAllResults(),
                'audit'   => $db->tableExists('audit_log') ? (int) $db->table('audit_log')->countAllResults() : 0,
                'webhook' => $db->tableExists('webhook_deliveries') ? (int) $db->table('webhook_deliveries')->countAllResults() : 0,
                'notif'   => $db->tableExists('notifications') ? (int) $db->table('notifications')->where('read_at IS NOT NULL', null, false)->countAllResults() : 0,
            ],
        ];
    },
];
