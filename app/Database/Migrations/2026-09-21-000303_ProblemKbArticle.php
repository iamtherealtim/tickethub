<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** A known error points at the article that documents its workaround. */
class ProblemKbArticle extends Migration
{
    public function up()
    {
        $this->db->query('ALTER TABLE problems ADD COLUMN kb_article_id INT UNSIGNED NULL');
    }

    public function down()
    {
        $this->db->query('ALTER TABLE problems DROP COLUMN kb_article_id');
    }
}
