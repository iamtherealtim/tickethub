<?php

/**
 * Admin tab: canned responses (global + per-group). Personal ones are managed
 * by each agent from the ticket page and are listed here read-only.
 */
return [
    'label' => 'Canned responses',
    'icon'  => 'note',
    'order' => 85,
    'data'  => static function ($request, $me): array {
        $db = db_connect();
        $scoped = $db->fieldExists('scope', 'canned_responses');
        $rows = $db->table('canned_responses')->orderBy($scoped ? "FIELD(scope,'global','group','personal')" : 'title', '', false)->orderBy('title')->get()->getResultArray();
        $owners = [];
        foreach ($db->table('users')->select('id, name')->get()->getResultArray() as $u) {
            $owners[(int) $u['id']] = $u['name'];
        }

        return ['cannedRows' => $rows, 'cannedScoped' => $scoped, 'cannedOwners' => $owners];
    },
];
