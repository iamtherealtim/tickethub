<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Effort was never recorded anywhere, so the app could not answer the question a
 * service desk manager asks most: where does the team's time actually go?
 * Entries are per agent per ticket so the same ticket can carry several people's
 * work, and spent_on is a date so time can be logged after the fact.
 */
class TimeTracking extends Migration
{
    public function up()
    {
        $this->db->query('CREATE TABLE ticket_time_entries (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ticket_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            minutes SMALLINT UNSIGNED NOT NULL,
            note VARCHAR(255) NULL,
            spent_on DATE NOT NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_ticket (ticket_id),
            INDEX idx_user_date (user_id, spent_on)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    public function down()
    {
        $this->db->query('DROP TABLE ticket_time_entries');
    }
}
