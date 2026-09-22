<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Ticket templates (pre-filled new-ticket forms with a task list) and the
 * recurring schedules that raise a ticket from one automatically.
 */
class TicketTemplates extends Migration
{
    public function up()
    {
        $this->db->query("CREATE TABLE IF NOT EXISTS ticket_templates (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            subject VARCHAR(250) NOT NULL,
            body TEXT NOT NULL,
            type VARCHAR(30) NOT NULL DEFAULT 'Incident',
            priority VARCHAR(20) NOT NULL DEFAULT 'Medium',
            category VARCHAR(40) NOT NULL DEFAULT 'Software',
            group_id INT UNSIGNED NULL,
            agent_id INT UNSIGNED NULL,
            tasks JSON NULL,
            custom JSON NULL,
            created_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->query("CREATE TABLE IF NOT EXISTS recurring_tickets (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            template_id INT UNSIGNED NOT NULL,
            requester_id INT UNSIGNED NOT NULL,
            every ENUM('day','week','month') NOT NULL DEFAULT 'week',
            `interval` INT NOT NULL DEFAULT 1,
            weekday TINYINT NULL,
            day_of_month TINYINT NULL,
            at_time TIME NOT NULL DEFAULT '09:00:00',
            tz VARCHAR(64) NOT NULL DEFAULT 'UTC',
            next_run_at DATETIME NOT NULL,
            last_run_at DATETIME NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            INDEX idx_recurring_due (active, next_run_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function down()
    {
        $this->db->query('DROP TABLE IF EXISTS recurring_tickets');
        $this->db->query('DROP TABLE IF EXISTS ticket_templates');
    }
}
