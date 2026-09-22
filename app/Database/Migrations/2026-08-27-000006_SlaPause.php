<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Stop-the-clock. Business hours fixed nights and weekends; this fixes the other
 * half — time a ticket spends in Pending is the requester's, not the desk's, and
 * agents were being measured on someone else's silence.
 *
 * paused_seconds accumulates WORKING seconds already spent waiting;
 * paused_at marks an interval still open.
 */
class SlaPause extends Migration
{
    public function up()
    {
        $this->db->query('ALTER TABLE tickets
            ADD COLUMN paused_seconds INT UNSIGNED NOT NULL DEFAULT 0,
            ADD COLUMN paused_at DATETIME NULL');

        // Tickets sitting in Pending right now start their interval from the last
        // update, which is when they most plausibly entered that state.
        $this->db->query("UPDATE tickets SET paused_at = updated_at WHERE status = 'Pending'");
    }

    public function down()
    {
        $this->db->query('ALTER TABLE tickets DROP COLUMN paused_seconds, DROP COLUMN paused_at');
    }
}
