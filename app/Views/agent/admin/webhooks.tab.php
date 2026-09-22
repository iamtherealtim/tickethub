<?php

/** Admin → Webhooks tab. */
return [
    'label' => 'Webhooks',
    'icon'  => 'send',
    'order' => 125,
    'data'  => static function ($request, $me): array {
        $db = db_connect();
        if (! $db->tableExists('webhook_endpoints')) {
            return ['endpoints' => [], 'deliveries' => [], 'webhooksReady' => false, 'freshSecret' => null];
        }

        return [
            'webhooksReady' => true,
            'endpoints'     => $db->table('webhook_endpoints')->orderBy('id')->get()->getResultArray(),
            'deliveries'    => $db->query(
                'SELECT d.*, e.name AS endpoint_name FROM webhook_deliveries d JOIN webhook_endpoints e ON e.id = d.endpoint_id ORDER BY d.id DESC LIMIT 40'
            )->getResultArray(),
            'freshSecret'   => session()->getFlashdata('new_webhook_secret'),
        ];
    },
];
