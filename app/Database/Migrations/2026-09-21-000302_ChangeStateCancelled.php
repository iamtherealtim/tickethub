<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** A change that will not go ahead needs a terminal state other than Rejected. */
class ChangeStateCancelled extends Migration
{
    public function up()
    {
        $this->db->query("ALTER TABLE changes MODIFY state ENUM('Awaiting approval','Scheduled','In progress','Completed','Rejected','Cancelled') NOT NULL DEFAULT 'Awaiting approval'");
    }

    public function down()
    {
        $this->db->query("UPDATE changes SET state = 'Rejected' WHERE state = 'Cancelled'");
        $this->db->query("ALTER TABLE changes MODIFY state ENUM('Awaiting approval','Scheduled','In progress','Completed','Rejected') NOT NULL DEFAULT 'Awaiting approval'");
    }
}
