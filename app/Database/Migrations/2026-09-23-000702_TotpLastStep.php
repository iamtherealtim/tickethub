<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * users.totp_last_step — the TOTP time step last accepted at sign-in. A code
 * whose step is at or below it is refused, so an observed code cannot be
 * replayed within its validity window.
 */
class TotpLastStep extends Migration
{
    public function up()
    {
        if (! $this->db->fieldExists('totp_last_step', 'users')) {
            $this->db->query('ALTER TABLE users ADD COLUMN totp_last_step BIGINT NULL');
        }
    }

    public function down()
    {
        if ($this->db->fieldExists('totp_last_step', 'users')) {
            $this->db->query('ALTER TABLE users DROP COLUMN totp_last_step');
        }
    }
}
