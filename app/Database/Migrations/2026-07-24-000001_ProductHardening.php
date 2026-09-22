<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class ProductHardening extends Migration
{
    public function up()
    {
        $this->db->query("ALTER TABLE tickets
            ADD COLUMN problem_id INT UNSIGNED NULL,
            ADD COLUMN sla_warned_at DATETIME NULL,
            ADD COLUMN nudged_at DATETIME NULL");

        $this->db->query("ALTER TABLE users
            ADD COLUMN remember_selector VARCHAR(24) NULL,
            ADD COLUMN remember_validator VARCHAR(255) NULL");

        $this->db->query("CREATE TABLE password_resets (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(150) NOT NULL,
            token_hash VARCHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->query("CREATE TABLE audit_log (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NULL,
            action VARCHAR(80) NOT NULL,
            detail VARCHAR(255) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        foreach ([
            'app_timezone'          => 'America/Toronto',
            'inbound_email_enabled' => '0',
            'inbound_email_secret'  => bin2hex(random_bytes(16)),
        ] as $k => $v) {
            $this->db->table('settings')->insert(['skey' => $k, 'svalue' => $v]);
        }

        // Link the seeded incidents to their problems (demo data only; a no-op
        // on an empty database anyway, but never touch a production desk).
        if (ENVIRONMENT !== 'production') {
            foreach (['INC-2098' => 'PRB-0044', 'INC-2090' => 'PRB-0041'] as $ticket => $problem) {
                $this->db->query(
                    'UPDATE tickets SET problem_id = (SELECT id FROM problems WHERE code = ?) WHERE code = ?',
                    [$problem, $ticket]
                );
            }
        }
    }

    public function down()
    {
        $this->db->query('ALTER TABLE tickets DROP COLUMN problem_id, DROP COLUMN sla_warned_at, DROP COLUMN nudged_at');
        $this->db->query('ALTER TABLE users DROP COLUMN remember_selector, DROP COLUMN remember_validator');
        $this->db->query('DROP TABLE IF EXISTS password_resets');
        $this->db->query('DROP TABLE IF EXISTS audit_log');
        $this->db->table('settings')->whereIn('skey', ['app_timezone', 'inbound_email_enabled', 'inbound_email_secret'])->delete();
    }
}
