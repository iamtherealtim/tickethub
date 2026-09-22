<?php

use App\Controllers\BaseController;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Pure-function coverage for the tickethub helper: SLA parsing, the
 * business-hours clock, reopen tracking and the KB HTML sanitiser.
 *
 * @internal
 */
final class TicketHubHelperTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        helper('tickethub');
    }

    /** Mon-Fri 09:00-17:00 UTC, with one holiday. */
    private function calendar(): array
    {
        return th_bh_parse([
            'id'            => 991,
            'days'          => 'Mon-Fri',
            'time_range'    => '09:00 - 17:00',
            'tz'            => 'UTC',
            'holiday_dates' => json_encode(['2026-01-01']),
        ]);
    }

    public function testParseDuration(): void
    {
        $this->assertSame(0.25, th_parse_duration('15 minutes', 9));
        $this->assertSame(1.0, th_parse_duration('1 hour', 9));
        $this->assertSame(48.0, th_parse_duration('2 days', 9));
        $this->assertSame(16.0, th_parse_duration('2 business days', 9));
        $this->assertSame(168.0, th_parse_duration('1 week', 9));
        $this->assertSame(9.0, th_parse_duration('whenever', 9));
    }

    public function testParseMinutesAndFormat(): void
    {
        $this->assertSame(90, th_parse_minutes('1.5h'));
        $this->assertSame(90, th_parse_minutes('1h 30m'));
        $this->assertSame(45, th_parse_minutes('45m'));
        $this->assertSame(20, th_parse_minutes('20'));
        $this->assertSame(0, th_parse_minutes('soon'));
        $this->assertSame('1h 30m', th_minutes(90));
        $this->assertSame('2h', th_minutes(120));
        $this->assertSame('5m', th_minutes(5));
    }

    public function testCalendarParsing(): void
    {
        $cal = $this->calendar();
        $this->assertSame([1 => true, 2 => true, 3 => true, 4 => true, 5 => true], $cal['days']);
        $this->assertSame(9 * 60, $cal['from']);
        $this->assertSame(17 * 60, $cal['to']);
        $this->assertFalse($cal['always']);
        $this->assertArrayHasKey('2026-01-01', $cal['holidays']);

        $allDay = th_bh_parse(['id' => 992, 'days' => 'Mon-Sun', 'time_range' => '00:00 - 24:00', 'tz' => 'UTC']);
        $this->assertTrue($allDay['always']);
    }

    public function testWorkingSecondsSkipNightsWeekendsAndHolidays(): void
    {
        $cal = $this->calendar();
        // Friday 2025-12-26 16:00 -> Monday 2025-12-29 10:00 = 1h Fri + 1h Mon.
        $from = strtotime('2025-12-26 16:00:00 UTC');
        $to   = strtotime('2025-12-29 10:00:00 UTC');
        $this->assertSame(2 * 3600, th_bh_between($from, $to, $cal));

        // Wed 2025-12-31 16:00 -> Fri 2026-01-02 10:00 skips the Jan 1 holiday.
        $from = strtotime('2025-12-31 16:00:00 UTC');
        $to   = strtotime('2026-01-02 10:00:00 UTC');
        $this->assertSame(2 * 3600, th_bh_between($from, $to, $cal));

        // Without a calendar it is wall-clock.
        $this->assertSame(3600, th_bh_between(0, 3600, null));
    }

    public function testAddingWorkingHoursRollsToNextWorkingDay(): void
    {
        $cal = $this->calendar();
        // Friday 16:00 + 2 working hours = Monday 10:00.
        $from = strtotime('2025-12-26 16:00:00 UTC');
        $this->assertSame(strtotime('2025-12-29 10:00:00 UTC'), th_bh_add($from, 2, $cal));
        // Starting on a Saturday begins counting Monday 09:00.
        $sat = strtotime('2025-12-27 12:00:00 UTC');
        $this->assertSame(strtotime('2025-12-29 10:00:00 UTC'), th_bh_add($sat, 1, $cal));
    }

    public function testReopenTracking(): void
    {
        $this->assertTrue(th_is_reopen(['status' => 'Resolved'], 'Open'));
        $this->assertFalse(th_is_reopen(['status' => 'Resolved'], 'Closed'));
        $this->assertFalse(th_is_reopen(['status' => 'Open'], 'Pending'));
        $this->assertFalse(th_is_reopen(['status' => 'Resolved'], null));

        $upd = th_bump_reopen(['status' => 'Closed', 'reopen_count' => 2], ['status' => 'Open']);
        $this->assertSame(3, $upd['reopen_count']);
        $upd = th_bump_reopen(['status' => 'Open'], ['priority' => 'High']);
        $this->assertArrayNotHasKey('reopen_count', $upd);
    }

    public function testSanitizerStripsScriptsAndUnsafeUrls(): void
    {
        $dirty = '<p onclick="x()">Hi <script>alert(1)</script><a href="javascript:alert(1)">bad</a>'
            . '<a href="https://example.com" title="t">ok</a><img src="data:text/html,x"></p>';
        $clean = th_sanitize_html($dirty);

        $this->assertStringNotContainsString('<script', $clean);
        $this->assertStringNotContainsString('alert(1)', $clean);
        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringNotContainsString('javascript:', $clean);
        $this->assertStringNotContainsString('data:', $clean);
        $this->assertStringContainsString('href="https://example.com"', $clean);
        $this->assertStringContainsString('<p>', $clean);
        $this->assertSame('', th_sanitize_html('   '));
    }

    public function testSanitizerDecodesEntitySmuggledSchemes(): void
    {
        $clean = th_sanitize_html('<a href="&#106;avascript:alert(1)">x</a>');
        $this->assertStringNotContainsString('avascript', $clean);
    }

    public function testPasswordPolicy(): void
    {
        $this->assertNotNull(BaseController::passwordProblem('short'));
        $this->assertNotNull(BaseController::passwordProblem('passwordpassword'));
        $this->assertNotNull(BaseController::passwordProblem('maya.ortiz-rocks!', 'maya.ortiz@tickethub.co'));
        $this->assertNull(BaseController::passwordProblem('Correct-Horse-Battery-9', 'maya.ortiz@tickethub.co'));
    }
}
