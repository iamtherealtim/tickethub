<?php

/**
 * Admin tab: ticket templates (pre-filled new-ticket forms with a task list)
 * and the recurring schedules that raise tickets from them.
 */
return [
    'label' => 'Ticket templates',
    'icon'  => 'layers',
    'order' => 75,
    'data'  => static function ($request, $me): array {
        $db = db_connect();
        if (! $db->tableExists('ticket_templates')) {
            return ['tplRows' => [], 'recurringRows' => [], 'tplReady' => false, 'tplRequesters' => []];
        }
        $rows = $db->table('ticket_templates')->orderBy('name')->get()->getResultArray();
        $rec  = $db->query(
            'SELECT r.*, t.name AS template_name, u.name AS requester_name FROM recurring_tickets r
             JOIN ticket_templates t ON t.id = r.template_id LEFT JOIN users u ON u.id = r.requester_id
             ORDER BY r.active DESC, r.next_run_at ASC'
        )->getResultArray();

        return [
            'tplRows' => $rows, 'recurringRows' => $rec, 'tplReady' => true,
            'tplRequesters' => $db->table('users')->select('id, name, dept')->where('active', 1)->orderBy('name')->get()->getResultArray(),
        ];
    },
];
