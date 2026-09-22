<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * A password reset or change must sign out every other browser holding that
 * account. Sessions are file-backed and not indexed by user, so instead each
 * login records the user's epoch and the SessionEpoch filter drops any session
 * whose recorded epoch no longer matches the row.
 */
class SessionEpoch extends Migration
{
    public function up()
    {
        $this->db->query('ALTER TABLE users ADD COLUMN session_epoch INT UNSIGNED NOT NULL DEFAULT 0');
    }

    public function down()
    {
        $this->db->query('ALTER TABLE users DROP COLUMN session_epoch');
    }
}
