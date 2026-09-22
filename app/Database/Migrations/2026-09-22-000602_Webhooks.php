<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Outbound webhooks: endpoints subscribe to ticket events; every event that
 * matches becomes a delivery row, attempted at once and retried with backoff.
 */
class Webhooks extends Migration
{
    public function up()
    {
        $this->db->query('CREATE TABLE IF NOT EXISTS webhook_endpoints (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(80) NOT NULL,
            url VARCHAR(500) NOT NULL,
            secret VARCHAR(64) NOT NULL,
            events TEXT NOT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            last_status INT NULL,
            last_delivered_at DATETIME NULL,
            failures INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

        $this->db->query('CREATE TABLE IF NOT EXISTS webhook_deliveries (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            endpoint_id INT UNSIGNED NOT NULL,
            event VARCHAR(60) NOT NULL,
            payload MEDIUMTEXT NOT NULL,
            status INT NULL,
            response_excerpt VARCHAR(500) NULL,
            attempts INT NOT NULL DEFAULT 0,
            next_attempt_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            delivered_at DATETIME NULL,
            INDEX idx_next (next_attempt_at),
            INDEX idx_endpoint (endpoint_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    public function down()
    {
        $this->db->query('DROP TABLE IF EXISTS webhook_deliveries');
        $this->db->query('DROP TABLE IF EXISTS webhook_endpoints');
    }
}
