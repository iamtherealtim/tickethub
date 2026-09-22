<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * One-tap CSAT from the resolution email: a per-ticket token behind the five
 * rating links, and when the rating landed (the comment window is 7 days).
 */
class CsatToken extends Migration
{
    public function up()
    {
        if (! $this->db->fieldExists('csat_token', 'tickets')) {
            $this->db->query('ALTER TABLE tickets ADD COLUMN csat_token VARCHAR(64) NULL AFTER csat_comment, ADD COLUMN csat_rated_at DATETIME NULL AFTER csat_token, ADD INDEX idx_tickets_csat_token (csat_token)');
        }
    }

    public function down()
    {
        if ($this->db->fieldExists('csat_token', 'tickets')) {
            $this->db->query('ALTER TABLE tickets DROP INDEX idx_tickets_csat_token, DROP COLUMN csat_token, DROP COLUMN csat_rated_at');
        }
    }
}
