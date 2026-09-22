<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Replies written in the new editor are stored as Markdown. Older rows keep
 * 'text' so they render exactly as before (escaped, line breaks preserved).
 */
class MessageFormat extends Migration
{
    public function up()
    {
        if (! $this->db->fieldExists('format', 'ticket_messages')) {
            $this->db->query("ALTER TABLE ticket_messages ADD COLUMN format ENUM('text','markdown') NOT NULL DEFAULT 'text' AFTER body");
        }
    }

    public function down()
    {
        if ($this->db->fieldExists('format', 'ticket_messages')) {
            $this->db->query('ALTER TABLE ticket_messages DROP COLUMN format');
        }
    }
}
