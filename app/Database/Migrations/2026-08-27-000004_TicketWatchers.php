<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Only the requester and the assignee ever heard anything about a ticket. A
 * manager following their report's request, or a third party pulled in for
 * context, had no way to stay in the loop short of being made the requester.
 */
class TicketWatchers extends Migration
{
    public function up()
    {
        $this->db->query('CREATE TABLE ticket_watchers (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ticket_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            added_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uniq_watch (ticket_id, user_id),
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    public function down()
    {
        $this->db->query('DROP TABLE ticket_watchers');
    }
}
