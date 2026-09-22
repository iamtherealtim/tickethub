<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Merge is destructive and problem-linking is many-to-one, so there was no way
 * to say "these two are related" without losing one of them. Links are stored
 * once and read from both ends, so the pair stays consistent.
 */
class TicketLinks extends Migration
{
    public function up()
    {
        $this->db->query("CREATE TABLE ticket_links (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            from_id INT UNSIGNED NOT NULL,
            to_id INT UNSIGNED NOT NULL,
            kind ENUM('related','duplicate','blocks') NOT NULL DEFAULT 'related',
            created_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uniq_pair (from_id, to_id),
            INDEX idx_to (to_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function down()
    {
        $this->db->query('DROP TABLE ticket_links');
    }
}
