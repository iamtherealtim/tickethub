<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Tickets can be tied to the change that caused (or will fix) them. */
class TicketChangeLink extends Migration
{
    public function up()
    {
        $this->db->query('ALTER TABLE tickets ADD COLUMN change_id INT UNSIGNED NULL, ADD INDEX idx_change (change_id)');
    }

    public function down()
    {
        $this->db->query('ALTER TABLE tickets DROP INDEX idx_change, DROP COLUMN change_id');
    }
}
