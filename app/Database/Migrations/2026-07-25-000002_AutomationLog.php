<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AutomationLog extends Migration
{
    public function up()
    {
        $this->db->query("CREATE TABLE automation_log (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            kind ENUM('automation','routing') NOT NULL DEFAULT 'automation',
            rule_id INT UNSIGNED NULL,
            rule_name VARCHAR(120) NOT NULL,
            ticket_id INT UNSIGNED NOT NULL,
            ticket_code VARCHAR(12) NOT NULL,
            trigger_event VARCHAR(40) NOT NULL DEFAULT '',
            summary VARCHAR(500) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            INDEX idx_created (created_at),
            INDEX idx_ticket (ticket_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function down()
    {
        $this->db->query('DROP TABLE IF EXISTS automation_log');
    }
}
