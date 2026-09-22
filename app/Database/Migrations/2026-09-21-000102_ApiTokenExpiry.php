<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Optional expiry for API tokens; NULL means the token lives until revoked. */
class ApiTokenExpiry extends Migration
{
    public function up()
    {
        $this->db->query('ALTER TABLE api_tokens ADD COLUMN expires_at DATETIME NULL AFTER last_used_at');
    }

    public function down()
    {
        $this->db->query('ALTER TABLE api_tokens DROP COLUMN expires_at');
    }
}
