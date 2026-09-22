<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Reopen rate is a quality signal the reports page was always meant to carry:
 * it says whether "Resolved" actually meant fixed. It cannot be derived after
 * the fact — every reopen path nulls resolved_at, erasing the evidence — so the
 * count has to be kept as it happens.
 */
class ReopenTracking extends Migration
{
    public function up()
    {
        $this->db->query('ALTER TABLE tickets
            ADD COLUMN reopen_count SMALLINT UNSIGNED NOT NULL DEFAULT 0');

        // Best-effort history: the manual reopen path has always written this
        // system note, so past reopens are recoverable even though the newer
        // paths (portal reply, inbound email, automation) left no trace.
        $this->db->query('UPDATE tickets t
            SET reopen_count = (
                SELECT COUNT(*) FROM ticket_messages m
                WHERE m.ticket_id = t.id AND m.kind = \'system\' AND m.body = \'Ticket reopened\'
            )');
    }

    public function down()
    {
        $this->db->query('ALTER TABLE tickets DROP COLUMN reopen_count');
    }
}
