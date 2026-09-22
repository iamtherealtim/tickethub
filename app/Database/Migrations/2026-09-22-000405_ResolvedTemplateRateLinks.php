<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Append the "Rate this ticket" block to the resolution email, once. */
class ResolvedTemplateRateLinks extends Migration
{
    public const BLOCK = "\n\nRate this ticket — one tap, no sign-in needed:\n{{rate_links}}\n\nOr open {{rate_url}} to pick a score.";

    public function up()
    {
        $rows = $this->db->table('email_templates')->where('trigger_event', 'Status → Resolved')->get()->getResultArray();
        foreach ($rows as $row) {
            $body = (string) ($row['body'] ?? '');
            if (str_contains($body, '{{rate_url}}')) {
                continue;
            }
            $this->db->table('email_templates')->where('id', $row['id'])->update(['body' => rtrim($body) . self::BLOCK]);
        }
    }

    public function down()
    {
        foreach ($this->db->table('email_templates')->where('trigger_event', 'Status → Resolved')->get()->getResultArray() as $row) {
            $this->db->table('email_templates')->where('id', $row['id'])->update(['body' => str_replace(self::BLOCK, '', (string) $row['body'])]);
        }
    }
}
