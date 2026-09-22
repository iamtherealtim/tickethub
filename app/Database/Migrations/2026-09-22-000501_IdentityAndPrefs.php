<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Identity features: TOTP two-factor columns, the auth provider a user last
 * signed in with (local / ldap / azure / oidc), the per-user daily-digest flag,
 * and the per-user notification preference table.
 */
class IdentityAndPrefs extends Migration
{
    public function up()
    {
        $cols = array_map(static fn ($f) => $f->name, $this->db->getFieldData('users'));
        $add  = [];
        if (! in_array('totp_secret', $cols, true)) {
            $add[] = 'ADD COLUMN totp_secret VARCHAR(255) NULL';
        }
        if (! in_array('totp_enabled_at', $cols, true)) {
            $add[] = 'ADD COLUMN totp_enabled_at DATETIME NULL';
        }
        if (! in_array('totp_recovery', $cols, true)) {
            $add[] = 'ADD COLUMN totp_recovery JSON NULL';
        }
        if (! in_array('auth_provider', $cols, true)) {
            $add[] = 'ADD COLUMN auth_provider VARCHAR(20) NULL';
        }
        if (! in_array('notify_digest', $cols, true)) {
            $add[] = 'ADD COLUMN notify_digest TINYINT(1) NOT NULL DEFAULT 0';
        }
        if ($add) {
            $this->db->query('ALTER TABLE users ' . implode(', ', $add));
        }

        $this->db->query('CREATE TABLE IF NOT EXISTS user_notification_prefs (
            user_id INT UNSIGNED NOT NULL,
            trigger_event VARCHAR(80) NOT NULL,
            email TINYINT(1) NOT NULL DEFAULT 1,
            in_app TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (user_id, trigger_event)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    public function down()
    {
        $this->db->query('DROP TABLE IF EXISTS user_notification_prefs');
        $cols = array_map(static fn ($f) => $f->name, $this->db->getFieldData('users'));
        $drop = [];
        foreach (['totp_secret', 'totp_enabled_at', 'totp_recovery', 'auth_provider', 'notify_digest'] as $c) {
            if (in_array($c, $cols, true)) {
                $drop[] = 'DROP COLUMN ' . $c;
            }
        }
        if ($drop) {
            $this->db->query('ALTER TABLE users ' . implode(', ', $drop));
        }
    }
}
