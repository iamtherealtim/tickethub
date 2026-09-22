<?php

/** Admin → Organizations tab. */
return [
    'label' => 'Organizations',
    'icon'  => 'home',
    'order' => 25,
    'data'  => static function ($request, $me): array {
        $db = db_connect();
        if (! $db->tableExists('organizations')) {
            return ['orgs' => [], 'orgMembers' => [], 'orgsReady' => false];
        }
        $members = [];
        foreach ($db->query('SELECT org_id, COUNT(*) AS n FROM users WHERE org_id IS NOT NULL GROUP BY org_id')->getResultArray() as $r) {
            $members[(int) $r['org_id']] = (int) $r['n'];
        }

        return [
            'orgs'       => $db->table('organizations')->orderBy('name')->get()->getResultArray(),
            'orgMembers' => $members,
            'orgsReady'  => true,
        ];
    },
];
