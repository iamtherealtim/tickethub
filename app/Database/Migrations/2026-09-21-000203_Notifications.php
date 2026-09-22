<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * In-app notifications. The bell used to recompute "what needs you" on every
 * open, so nothing was ever new and nothing could be dismissed. These rows are
 * the events themselves: assignment, replies on your tickets, escalations and
 * approvals waiting on you.
 */
class Notifications extends Migration
{
    public function up()
    {
        $this->db->query('CREATE TABLE IF NOT EXISTS notifications (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            ticket_id INT UNSIGNED NULL,
            kind VARCHAR(30) NOT NULL,
            title VARCHAR(160) NOT NULL,
            body VARCHAR(255) NULL,
            url VARCHAR(255) NULL,
            read_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_user_read (user_id, read_at),
            INDEX idx_ticket (ticket_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    public function down()
    {
        $this->db->query('DROP TABLE IF EXISTS notifications');
    }
}
