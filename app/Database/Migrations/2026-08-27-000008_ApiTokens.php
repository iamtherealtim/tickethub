<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The only way in was the inbound-email webhook, so nothing else in the estate
 * could create or read a ticket. Tokens are per user: an API call acts as that
 * person, inherits their group scope, and shows up in the audit trail as them.
 *
 * Only the hash is stored — the token itself is shown once, at creation.
 */
class ApiTokens extends Migration
{
    public function up()
    {
        $this->db->query('CREATE TABLE api_tokens (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            name VARCHAR(60) NOT NULL,
            token_hash VARCHAR(64) NOT NULL,
            last_used_at DATETIME NULL,
            revoked_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uniq_hash (token_hash),
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    public function down()
    {
        $this->db->query('DROP TABLE api_tokens');
    }
}
