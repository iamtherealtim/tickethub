<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * webhook_endpoints.secret is now stored encrypted ("enc:<base64>", see
 * Settings::encryptValue) when encryption.key is set; the ciphertext does not
 * fit the original VARCHAR(64).
 */
class WebhookSecretWidth extends Migration
{
    public function up()
    {
        if ($this->db->tableExists('webhook_endpoints')) {
            $this->db->query('ALTER TABLE webhook_endpoints MODIFY secret VARCHAR(512) NOT NULL');
        }
    }

    public function down()
    {
        // Narrowing would truncate encrypted secrets; leave the width as is.
    }
}
