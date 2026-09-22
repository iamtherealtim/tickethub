<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class VotesAndTemplateActions extends Migration
{
    public function up()
    {
        // One vote per person per article (changeable, not stackable).
        $this->db->query("CREATE TABLE article_votes (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            article_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            vote ENUM('up','down') NOT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uq_article_user (article_id, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // The SLA-warning rule now sends the editable email template instead of
        // a body hardcoded in the rule, so Admin → Email templates governs it.
        $tpl = $this->db->table('email_templates')->where('trigger_event', '80% of SLA')->get()->getRowArray();
        if ($tpl) {
            $rule = $this->db->table('automations')->where('name', 'Warn assignee at 80% of resolution SLA')->get()->getRowArray();
            if ($rule) {
                $this->db->table('automations')->where('id', $rule['id'])->update([
                    'actions' => json_encode([['type' => 'email_template', 'value' => (string) $tpl['id']]]),
                ]);
            }
        }
    }

    public function down()
    {
        $this->db->query('DROP TABLE IF EXISTS article_votes');
    }
}
