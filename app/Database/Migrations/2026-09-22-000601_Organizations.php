<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Organizations: a company/department a requester belongs to. `domain` lets an
 * organization claim everyone whose email ends in it (auto-assignment on save).
 */
class Organizations extends Migration
{
    public function up()
    {
        $this->db->query('CREATE TABLE IF NOT EXISTS organizations (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL UNIQUE,
            domain VARCHAR(190) NULL,
            notes TEXT NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            INDEX idx_domain (domain)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

        if (! $this->db->fieldExists('org_id', 'users')) {
            $this->db->query('ALTER TABLE users ADD COLUMN org_id INT UNSIGNED NULL, ADD INDEX idx_org (org_id)');
        }
    }

    public function down()
    {
        if ($this->db->fieldExists('org_id', 'users')) {
            $this->db->query('ALTER TABLE users DROP INDEX idx_org, DROP COLUMN org_id');
        }
        $this->db->query('DROP TABLE IF EXISTS organizations');
    }
}
