<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Announcements can carry an expiry so stale notices drop off on their own. */
class AnnouncementExpiry extends Migration
{
    public function up()
    {
        $this->db->query('ALTER TABLE announcements ADD COLUMN expires_at DATETIME NULL');
    }

    public function down()
    {
        $this->db->query('ALTER TABLE announcements DROP COLUMN expires_at');
    }
}
