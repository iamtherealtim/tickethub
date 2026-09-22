<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Canned responses grow a scope: global (everyone), group (one team) or
 * personal (one agent), plus an optional "/shortcut" for keyboard expansion.
 * Existing rows become global, which is what they already were in practice.
 */
class CannedResponseScope extends Migration
{
    public function up()
    {
        if (! $this->db->fieldExists('scope', 'canned_responses')) {
            $this->db->query("ALTER TABLE canned_responses
                ADD COLUMN scope ENUM('personal','group','global') NOT NULL DEFAULT 'global' AFTER body,
                ADD COLUMN owner_id INT UNSIGNED NULL AFTER scope,
                ADD COLUMN group_id INT UNSIGNED NULL AFTER owner_id,
                ADD COLUMN shortcut VARCHAR(40) NULL AFTER group_id,
                ADD COLUMN created_at DATETIME NULL AFTER shortcut,
                ADD COLUMN updated_at DATETIME NULL AFTER created_at,
                ADD INDEX idx_canned_scope (scope, owner_id, group_id)");
            $this->db->query('UPDATE canned_responses SET created_at = NOW(), updated_at = NOW() WHERE created_at IS NULL');
        }
    }

    public function down()
    {
        if ($this->db->fieldExists('scope', 'canned_responses')) {
            $this->db->query('ALTER TABLE canned_responses DROP INDEX idx_canned_scope, DROP COLUMN scope, DROP COLUMN owner_id, DROP COLUMN group_id, DROP COLUMN shortcut, DROP COLUMN created_at, DROP COLUMN updated_at');
        }
    }
}
