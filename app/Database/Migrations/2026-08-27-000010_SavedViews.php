<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The eight built-in views were a fixed constant, so the filter an agent runs
 * every morning had to be rebuilt every morning. A saved view is just the query
 * string, stored — which keeps it compatible with whatever filters exist later.
 */
class SavedViews extends Migration
{
    public function up()
    {
        $this->db->query('CREATE TABLE saved_views (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            name VARCHAR(40) NOT NULL,
            query VARCHAR(500) NOT NULL,
            shared TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    public function down()
    {
        $this->db->query('DROP TABLE saved_views');
    }
}
