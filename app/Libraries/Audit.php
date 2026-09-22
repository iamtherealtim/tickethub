<?php

namespace App\Libraries;

/** Append-only trail of security-relevant actions. */
class Audit
{
    public static function log(string $action, string $detail = '', ?int $userId = null): void
    {
        try {
            db_connect()->table('audit_log')->insert([
                'user_id'    => $userId ?? (session()->get('user_id') ?: null),
                'action'     => mb_substr($action, 0, 80),
                'detail'     => mb_substr($detail, 0, 255),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Audit log failed: {msg}', ['msg' => $e->getMessage()]);
        }
    }
}
