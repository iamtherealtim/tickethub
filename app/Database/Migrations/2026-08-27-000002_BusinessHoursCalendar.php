<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Business hours were configurable, assigned per group and displayed — but no
 * calculation ever read them, so every SLA figure in the product was wall-clock.
 * Honouring them needs real dates: `holidays` was a free-text label ("Canadian
 * statutory") that no clock can act on.
 */
class BusinessHoursCalendar extends Migration
{
    public function up()
    {
        $this->db->query("ALTER TABLE business_hours
            ADD COLUMN holiday_dates TEXT NULL COMMENT 'JSON array of YYYY-MM-DD'");

        // The label stays as the human description; these are what the clock uses.
        $canada = [
            '2026-01-01', '2026-02-16', '2026-04-03', '2026-05-18', '2026-07-01', '2026-08-03',
            '2026-09-07', '2026-09-30', '2026-10-12', '2026-11-11', '2026-12-25', '2026-12-26',
            '2027-01-01', '2027-02-15', '2027-03-26', '2027-05-24', '2027-07-01', '2027-08-02',
            '2027-09-06', '2027-09-30', '2027-10-11', '2027-11-11', '2027-12-25', '2027-12-26',
        ];
        $this->db->table('business_hours')
            ->where('holidays', 'Canadian statutory')
            ->update(['holiday_dates' => json_encode($canada)]);

        // Anything else starts with an empty list rather than NULL, so the admin
        // form round-trips without a special case.
        $this->db->table('business_hours')->where('holiday_dates IS NULL', null, false)->update(['holiday_dates' => '[]']);
    }

    public function down()
    {
        $this->db->query('ALTER TABLE business_hours DROP COLUMN holiday_dates');
    }
}
