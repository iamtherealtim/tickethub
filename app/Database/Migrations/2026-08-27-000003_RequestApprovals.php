<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Catalog items already declared who must sign off ("Manager", "System owner"),
 * and requests for them were parked in Pending with a note — but nothing could
 * ever clear that note. Changes had a real approval flow; service requests only
 * had the label. This gives them the same bones.
 */
class RequestApprovals extends Migration
{
    public function up()
    {
        $this->db->query("CREATE TABLE ticket_approvals (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ticket_id INT UNSIGNED NOT NULL,
            required_of VARCHAR(60) NOT NULL,
            status ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
            decided_by INT UNSIGNED NULL,
            note VARCHAR(255) NULL,
            created_at DATETIME NOT NULL,
            decided_at DATETIME NULL,
            INDEX idx_ticket (ticket_id),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Existing catalog requests are sitting Pending behind a note nobody can
        // action — give them a real approval row so they become reachable.
        $this->db->query("INSERT INTO ticket_approvals (ticket_id, required_of, status, created_at)
            SELECT t.id,
                   TRIM(REPLACE(REPLACE(m.body, 'Waiting on ', ''), ' approval', '')),
                   'Pending',
                   t.created_at
            FROM tickets t
            JOIN ticket_messages m ON m.ticket_id = t.id AND m.kind = 'system' AND m.body LIKE 'Waiting on % approval'
            WHERE t.status = 'Pending'");
    }

    public function down()
    {
        $this->db->query('DROP TABLE ticket_approvals');
    }
}
