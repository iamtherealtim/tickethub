<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class QueuesAndCustomFields extends Migration
{
    public function up()
    {
        // Ordered first-match-wins routing for tickets that arrive without an explicit team.
        $this->db->query("CREATE TABLE routing_rules (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            position INT NOT NULL DEFAULT 10,
            match_type ENUM('Category','Subject contains','Source') NOT NULL DEFAULT 'Category',
            match_value VARCHAR(120) NOT NULL,
            group_id INT UNSIGNED NOT NULL,
            agent_id INT UNSIGNED NULL,
            priority VARCHAR(10) NULL,
            active TINYINT(1) NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Custom submission-form fields get options (for dropdowns) and per-ticket values.
        $this->db->query('ALTER TABLE ticket_fields ADD COLUMN options TEXT NULL');
        $this->db->query("CREATE TABLE ticket_field_values (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ticket_id INT UNSIGNED NOT NULL,
            field_id INT UNSIGNED NOT NULL,
            value TEXT NULL,
            UNIQUE KEY uq_ticket_field (ticket_id, field_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Catalog items can route straight to a team.
        $this->db->query('ALTER TABLE catalog_items ADD COLUMN group_id INT UNSIGNED NULL');

        $this->db->table('settings')->insert(['skey' => 'default_group_id', 'svalue' => '1']);

        // Everything below is demo data that assumes the seeded teams (ids 1-5),
        // catalog items and custom fields exist. A production desk starts clean.
        if (ENVIRONMENT === 'production') {
            return;
        }

        // Seed routing that mirrors how the demo teams are organised.
        $this->db->table('routing_rules')->insertBatch([
            ['position' => 10, 'match_type' => 'Subject contains', 'match_value' => 'password', 'group_id' => 4, 'agent_id' => null, 'priority' => null, 'active' => 1],
            ['position' => 20, 'match_type' => 'Category', 'match_value' => 'Network', 'group_id' => 2, 'agent_id' => null, 'priority' => null, 'active' => 1],
            ['position' => 30, 'match_type' => 'Category', 'match_value' => 'Hardware', 'group_id' => 3, 'agent_id' => null, 'priority' => null, 'active' => 1],
            ['position' => 40, 'match_type' => 'Category', 'match_value' => 'Printing', 'group_id' => 3, 'agent_id' => null, 'priority' => null, 'active' => 1],
            ['position' => 50, 'match_type' => 'Category', 'match_value' => 'Access', 'group_id' => 4, 'agent_id' => null, 'priority' => null, 'active' => 1],
            ['position' => 60, 'match_type' => 'Category', 'match_value' => 'Security', 'group_id' => 4, 'agent_id' => null, 'priority' => 'High', 'active' => 1],
            ['position' => 70, 'match_type' => 'Category', 'match_value' => 'Software', 'group_id' => 5, 'agent_id' => null, 'priority' => null, 'active' => 1],
        ]);

        // Point catalog items at their fulfilling teams.
        foreach ([1 => 3, 2 => 5, 3 => 4, 4 => 3, 5 => 4, 6 => 3, 7 => 1, 8 => 2] as $item => $group) {
            $this->db->table('catalog_items')->where('id', $item)->update(['group_id' => $group]);
        }

        // Make the seeded custom fields usable out of the box.
        $this->db->table('ticket_fields')->where('label', 'Impacted site')->update(['options' => json_encode(['Toronto HQ', 'Montréal', 'Mississauga', 'Vancouver', 'Remote'])]);
        $this->db->table('ticket_fields')->where('label', 'Subcategory')->update(['options' => json_encode(['Wi-Fi', 'VPN', 'Email', 'Laptop', 'Monitor', 'Application', 'Other'])]);
        // The seeded "Category" field duplicates the built-in Area selector — keep it defined but off the forms.
        $this->db->table('ticket_fields')->where('label', 'Category')->update(['portal' => 0, 'agents' => 0]);
    }

    public function down()
    {
        $this->db->query('DROP TABLE IF EXISTS routing_rules');
        $this->db->query('DROP TABLE IF EXISTS ticket_field_values');
        $this->db->query('ALTER TABLE ticket_fields DROP COLUMN options');
        $this->db->query('ALTER TABLE catalog_items DROP COLUMN group_id');
        $this->db->table('settings')->where('skey', 'default_group_id')->delete();
    }
}
