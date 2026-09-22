<?php

/**
 * TicketHub UI helpers — ports the mockup's rendering primitives to PHP.
 * Times are handled as unix timestamps; DB datetimes parsed with strtotime().
 */

const TH_PRIORITY = [
    'Urgent' => ['dot' => 'bg-alert', 'text' => 'text-alert', 'bg' => 'bg-alert-50', 'rank' => 0],
    'High'   => ['dot' => 'bg-signal', 'text' => 'text-signal', 'bg' => 'bg-signal-50', 'rank' => 1],
    'Medium' => ['dot' => 'bg-[#4C6EF5]', 'text' => 'text-[#3B5BDB]', 'bg' => 'bg-[#EDF2FF]', 'rank' => 2],
    'Low'    => ['dot' => 'bg-[#98A1B0]', 'text' => 'text-muted', 'bg' => 'bg-[#EFF1F5]', 'rank' => 3],
];

const TH_STATUS = [
    'New'      => 'bg-ink text-white border-ink',
    'Open'     => 'bg-signal-50 text-signal border-signal-100',
    'Pending'  => 'bg-violet-50 text-violet border-[#DCD8F0]',
    'Resolved' => 'bg-brand-50 text-brand border-brand-100',
    'Closed'   => 'bg-[#EFF1F5] text-muted border-line',
];

const TH_OPEN_STATES = ['New', 'Open', 'Pending'];

const TH_SLA_COLOR = ['ok' => '#0E7C6B', 'warn' => '#B8760B', 'breached' => '#BC332D', 'met' => '#0E7C6B', 'missed' => '#BC332D'];

const TH_AVATAR_TONE = [
    'brand'  => 'bg-brand-50 text-brand border-brand-100',
    'ink'    => 'bg-[#E8EAEF] text-ink-500 border-[#D8DCE4]',
    'violet' => 'bg-violet-50 text-violet border-[#DCD8F0]',
    'signal' => 'bg-signal-50 text-signal border-signal-100',
    'alert'  => 'bg-alert-50 text-alert border-alert-100',
];

const TH_ASSET_ICON = ['Laptop' => 'laptop', 'Mobile' => 'phone', 'Printer' => 'monitor', 'Server' => 'server', 'Switch' => 'server', 'Dock' => 'monitor', 'License' => 'layers'];

const TH_ASSET_STATUS = [
    'In use'       => 'bg-brand-50 text-brand border-brand-100',
    'In stock'     => 'bg-[#EFF1F5] text-muted border-line',
    'Needs repair' => 'bg-alert-50 text-alert border-alert-100',
    'Retired'      => 'bg-[#EFF1F5] text-faint border-line',
];

const TH_RISK = ['High' => 'bg-alert-50 text-alert border-alert-100', 'Medium' => 'bg-signal-50 text-signal border-signal-100', 'Low' => 'bg-brand-50 text-brand border-brand-100'];

const TH_CHANGE_STATE = [
    'Awaiting approval' => 'bg-signal-50 text-signal border-signal-100',
    'Scheduled'         => 'bg-violet-50 text-violet border-[#DCD8F0]',
    'In progress'       => 'bg-ink text-white border-ink',
    'Completed'         => 'bg-brand-50 text-brand border-brand-100',
    'Rejected'          => 'bg-[#EFF1F5] text-muted border-line',
];

const TH_PROBLEM_STATUS = [
    'Root cause identified' => 'bg-signal-50 text-signal border-signal-100',
    'Under investigation'   => 'bg-violet-50 text-violet border-[#DCD8F0]',
    'Resolved'              => 'bg-brand-50 text-brand border-brand-100',
];

const TH_CATEGORIES = ['Network', 'Hardware', 'Software', 'Access', 'Email', 'Printing', 'Security', 'Facilities'];
const TH_SOURCES    = ['Phone', 'Email', 'Chat', 'Walk-up', 'Portal'];
const TH_SITES      = ['Toronto HQ', 'Montréal', 'Mississauga', 'Vancouver', 'Remote'];

// Fallback targets, priority => [first response hours, resolution hours].
// Used only when no matching active SLA policy exists in the database.
const TH_SLA_HOURS = ['Urgent' => [0.25, 4], 'High' => [1, 8], 'Medium' => [4, 48], 'Low' => [8, 120]];

/** Parse an SLA duration string ("15 minutes", "1 hour", "2 days") into hours. */
function th_parse_duration(string $text, float $default): float
{
    if (! preg_match('/([\d.]+)\s*(minute|min|hour|hr|day|week|business day)/i', $text, $m)) {
        return $default;
    }
    $n    = (float) $m[1];
    $unit = strtolower($m[2]);

    return match (true) {
        str_starts_with($unit, 'min')  => $n / 60,
        str_starts_with($unit, 'hour'), str_starts_with($unit, 'hr') => $n,
        str_contains($unit, 'business') => $n * 8,   // a business day = 8 working hours
        str_starts_with($unit, 'day')  => $n * 24,
        str_starts_with($unit, 'week') => $n * 168,
        default => $default,
    };
}

/**
 * Resolution/response targets for a priority, taken from the active SLA
 * policies configured in Admin → SLA policies (matched by priority name),
 * falling back to TH_SLA_HOURS when nothing matches.
 */
function th_sla_targets(string $priority): array
{
    static $cache = [];
    if (isset($cache[$priority])) {
        return $cache[$priority];
    }
    [$fr, $res] = TH_SLA_HOURS[$priority] ?? TH_SLA_HOURS['Medium'];

    try {
        foreach (db_connect()->table('slas')->where('active', 1)->get()->getResultArray() as $p) {
            if (stripos($p['name'], $priority) !== false) {
                $fr  = th_parse_duration((string) $p['first_response'], $fr);
                $res = th_parse_duration((string) $p['resolution'], $res);
                break;
            }
        }
    } catch (\Throwable $e) {
        log_message('error', 'SLA policy lookup failed: {msg}', ['msg' => $e->getMessage()]);
    }

    return $cache[$priority] = [$fr, $res];
}

function th_icons(): array
{
    static $icons = [
        'grid' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/>',
        'inbox' => '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.6-3.6"/>',
        'bell' => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'x' => '<path d="M18 6 6 18M6 6l12 12"/>',
        'check' => '<path d="M20 6 9 17l-5-5"/>',
        'down' => '<path d="m6 9 6 6 6-6"/>',
        'right' => '<path d="m9 18 6-6-6-6"/>',
        'back' => '<path d="M19 12H5m7-7-7 7 7 7"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'filter' => '<path d="M3 5h18l-7 8v6l-4 2v-8z"/>',
        'dots' => '<circle cx="12" cy="5" r="1.4" fill="currentColor"/><circle cx="12" cy="12" r="1.4" fill="currentColor"/><circle cx="12" cy="19" r="1.4" fill="currentColor"/>',
        'clip' => '<path d="M21.4 11.05 12.2 20.2a5 5 0 0 1-7.07-7.07l9.19-9.19a3.33 3.33 0 1 1 4.71 4.71l-9.2 9.19a1.67 1.67 0 1 1-2.35-2.36l8.49-8.48"/>',
        'send' => '<path d="M22 2 11 13M22 2l-7 20-4-9-9-4z"/>',
        'book' => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>',
        'server' => '<rect x="2" y="3" width="20" height="8" rx="2"/><rect x="2" y="13" width="20" height="8" rx="2"/><path d="M6 7h.01M6 17h.01"/>',
        'branch' => '<circle cx="6" cy="6" r="2.5"/><circle cx="6" cy="18" r="2.5"/><circle cx="18" cy="8" r="2.5"/><path d="M6 8.5v7"/><path d="M18 10.5a6 6 0 0 1-6 6H9"/>',
        'warn' => '<path d="M10.3 3.7 1.9 18a2 2 0 0 0 1.7 3h16.8a2 2 0 0 0 1.7-3L13.7 3.7a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/>',
        'chart' => '<path d="M3 3v18h18"/><path d="M7 15v3M12 10v8M17 6v12"/>',
        'cog' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-1.8-.3 1.6 1.6 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1A1.6 1.6 0 0 0 9 19.4a1.6 1.6 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.6 1.6 0 0 0 .3-1.8 1.6 1.6 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1A1.6 1.6 0 0 0 4.6 9a1.6 1.6 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.6 1.6 0 0 0 1.8.3H9a1.6 1.6 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.6 1.6 0 0 0 1 1.5 1.6 1.6 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.6 1.6 0 0 0-.3 1.8V9a1.6 1.6 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.6 1.6 0 0 0-1.5 1z"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'star' => '<path d="m12 2.6 2.9 5.9 6.5.95-4.7 4.58 1.1 6.47L12 17.45 6.2 20.5l1.1-6.47L2.6 9.45l6.5-.95z"/>',
        'tag' => '<path d="M20.6 13.4 12 4.8 4 4l.8 8 8.6 8.6a2 2 0 0 0 2.8 0l4.4-4.4a2 2 0 0 0 0-2.8z"/><path d="M8.5 8.5h.01"/>',
        'link' => '<path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.5 1.5"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.5-1.5"/>',
        'refresh' => '<path d="M21 12a9 9 0 1 1-2.6-6.4"/><path d="M21 3v6h-6"/>',
        'home' => '<path d="m3 10 9-7 9 7v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22V12h6v10"/>',
        'layers' => '<path d="m12 2 9 5-9 5-9-5z"/><path d="m3 12 9 5 9-5"/><path d="m3 17 9 5 9-5"/>',
        'mail' => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m2.5 7 9.5 6 9.5-6"/>',
        'laptop' => '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M2 20h20"/>',
        'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
        'edit' => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
        'trash' => '<path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14"/>',
        'zap' => '<path d="M13 2 3 14h8l-1 8 10-12h-8z"/>',
        'ext' => '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M21 14v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5"/>',
        'monitor' => '<rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/>',
        'phone' => '<path d="M5 3h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 12l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 5a2 2 0 0 1 2-2z"/>',
        'note' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8M8 17h5"/>',
        'lock' => '<rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
        'play' => '<path d="M6 4l14 8-14 8z"/>',
    ];

    return $icons;
}

function th_icon(string $name, string $cls = 'w-4 h-4'): string
{
    $paths = th_icons()[$name] ?? '';

    return '<svg class="' . $cls . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths . '</svg>';
}

function th_initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    $out = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        $out .= mb_strtoupper(mb_substr($p, 0, 1));
    }

    return $out;
}

/** $user: array with name + color (or null). */
function th_avatar(?array $user, int $size = 28, string $cls = ''): string
{
    $name  = $user['name'] ?? 'Unknown';
    $color = $user['color'] ?? 'ink';
    $tone  = TH_AVATAR_TONE[$color] ?? TH_AVATAR_TONE['ink'];
    $fs    = $size <= 24 ? 'text-[10px]' : ($size <= 30 ? 'text-[11px]' : 'text-[13px]');

    return '<span class="inline-flex items-center justify-center rounded-full border font-semibold ' . $fs . ' ' . $tone . ' ' . $cls . '"'
        . ' style="width:' . $size . 'px;height:' . $size . 'px" title="' . esc($name) . '">' . esc(th_initials($name)) . '</span>';
}

function th_status_chip(string $s): string
{
    $cls = TH_STATUS[$s] ?? TH_STATUS['Closed'];

    return '<span class="inline-flex items-center h-[22px] px-2 rounded-md border text-[11px] font-semibold tracking-wide ' . $cls . '">' . esc($s) . '</span>';
}

function th_priority_tag(string $p): string
{
    $c = TH_PRIORITY[$p] ?? TH_PRIORITY['Low'];

    return '<span class="inline-flex items-center gap-1.5 text-[12px] font-medium ' . $c['text'] . '"><i class="led ' . $c['dot'] . '"></i>' . esc($p) . '</span>';
}

function th_tag_pill(string $t): string
{
    return '<span class="inline-flex items-center h-[20px] px-1.5 rounded bg-[#EFF1F5] text-[11px] text-muted font-mono">' . esc($t) . '</span>';
}

function th_is_open(array $t): bool
{
    return in_array($t['status'], TH_OPEN_STATES, true);
}

/** SLA math — mirror of the mockup's sla(). Returns pct, remaining(s), state, done. */

/* ---------- business-hours clock ----------
   Every SLA figure used to be wall-clock, which made a Friday-evening ticket
   "breached" by Saturday for a team that works Mon-Fri. These turn a
   business_hours row into a calendar the clock can actually run against. */

/** Parse a business_hours row into {days, from, to, tz, holidays, always}. */
function th_bh_parse(?array $row): ?array
{
    if (! $row) {
        return null;
    }
    static $cache = [];
    $key = (int) ($row['id'] ?? 0);
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $names = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7];
    $days  = [];
    foreach (explode(',', strtolower((string) ($row['days'] ?? 'mon-fri'))) as $part) {
        $part = trim($part);
        if (preg_match('/^([a-z]{3})\s*-\s*([a-z]{3})$/', $part, $m) && isset($names[$m[1]], $names[$m[2]])) {
            for ($d = $names[$m[1]]; $d <= $names[$m[2]]; $d++) {
                $days[$d] = true;
            }
        } elseif (isset($names[substr($part, 0, 3)])) {
            $days[$names[substr($part, 0, 3)]] = true;
        }
    }
    if (! $days) {
        $days = [1 => true, 2 => true, 3 => true, 4 => true, 5 => true];
    }

    $from = 8 * 60;
    $to   = 18 * 60;
    if (preg_match('/(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})/', (string) ($row['time_range'] ?? ''), $m)) {
        $from = (int) $m[1] * 60 + (int) $m[2];
        $to   = (int) $m[3] * 60 + (int) $m[4];
    }
    if ($to <= $from) {
        $to = 24 * 60;
    }

    $holidays = [];
    foreach (json_decode((string) ($row['holiday_dates'] ?? '[]'), true) ?: [] as $d) {
        $holidays[$d] = true;
    }

    return $cache[$key] = [
        'days' => $days, 'from' => $from, 'to' => $to,
        'tz' => $row['tz'] ?: 'UTC', 'holidays' => $holidays,
        // Round-the-clock calendars skip the walk entirely.
        'always' => count($days) === 7 && $from === 0 && $to === 24 * 60 && ! $holidays,
    ];
}

function th_bh_tz(array $cal): \DateTimeZone
{
    static $zones = [];
    $name = $cal['tz'];
    if (! isset($zones[$name])) {
        try {
            $zones[$name] = new \DateTimeZone($name);
        } catch (\Throwable $e) {
            $zones[$name] = new \DateTimeZone('UTC');
        }
    }

    return $zones[$name];
}

/** The calendar a ticket's group works to, or null when none is configured. */
function th_ticket_calendar(array $t): ?array
{
    static $byGroup = [];
    $gid = (int) ($t['group_id'] ?? 0);
    if (array_key_exists($gid, $byGroup)) {
        return $byGroup[$gid];
    }
    try {
        $row = db_connect()->query(
            'SELECT h.* FROM business_hours h JOIN groups g ON g.hours_id = h.id WHERE g.id = ?',
            [$gid]
        )->getRowArray();
    } catch (\Throwable $e) {
        log_message('error', 'Business-hours lookup failed: {msg}', ['msg' => $e->getMessage()]);
        $row = null;
    }

    return $byGroup[$gid] = th_bh_parse($row);
}

/** Working window for one calendar day, as minutes-from-midnight. */
function th_bh_day_window(\DateTimeInterface $day, array $cal): array
{
    $dow = (int) $day->format('N');
    if (! isset($cal['days'][$dow]) || isset($cal['holidays'][$day->format('Y-m-d')])) {
        return [0, 0];
    }

    return [$cal['from'], $cal['to']];
}

/** Working seconds between two instants. Non-working time simply does not count. */
function th_bh_between(int $from, int $to, ?array $cal): int
{
    if (! $cal || $cal['always'] || $to <= $from) {
        return max(0, $to - $from);
    }
    $tz    = th_bh_tz($cal);
    $total = 0;
    $day   = (new \DateTimeImmutable('@' . $from))->setTimezone($tz)->setTime(0, 0);
    $end   = (new \DateTimeImmutable('@' . $to))->setTimezone($tz);

    // Guard so a pathological range cannot walk forever.
    for ($i = 0; $i < 800 && $day <= $end; $i++, $day = $day->modify('+1 day')) {
        [$ws, $we] = th_bh_day_window($day, $cal);
        if ($ws === $we) {
            continue;
        }
        $open  = $day->setTime(intdiv($ws, 60), $ws % 60)->getTimestamp();
        $close = $day->getTimestamp() + $we * 60;
        $total += max(0, min($to, $close) - max($from, $open));
    }

    return $total;
}

/** The instant $hours of working time after $from. */
function th_bh_add(int $from, float $hours, ?array $cal): int
{
    $budget = (int) round($hours * 3600);
    if (! $cal || $cal['always']) {
        return $from + $budget;
    }
    $tz  = th_bh_tz($cal);
    $day = (new \DateTimeImmutable('@' . $from))->setTimezone($tz)->setTime(0, 0);

    for ($i = 0; $i < 800; $i++, $day = $day->modify('+1 day')) {
        [$ws, $we] = th_bh_day_window($day, $cal);
        if ($ws === $we) {
            continue;
        }
        $open  = $day->setTime(intdiv($ws, 60), $ws % 60)->getTimestamp();
        $close = $day->getTimestamp() + $we * 60;
        $start = max($from, $open);
        if ($start >= $close) {
            continue;
        }
        $available = $close - $start;
        if ($available >= $budget) {
            return $start + $budget;
        }
        $budget -= $available;
    }

    // Calendar too sparse to ever satisfy the target; fall back to wall clock.
    return $from + (int) round($hours * 3600);
}

/** Due timestamp for a target in hours, against the calendar a group works to. */
function th_due_at(int $from, float $hours, ?int $groupId): int
{
    return th_bh_add($from, $hours, th_ticket_calendar(['group_id' => $groupId]));
}

/**
 * Working seconds this ticket has spent waiting on the requester, up to $mark.
 * Includes an interval that is still open.
 */
function th_paused_working(array $t, ?array $cal, int $mark): int
{
    $total = (int) ($t['paused_seconds'] ?? 0);
    if (! empty($t['paused_at'])) {
        $from = strtotime($t['paused_at']);
        if ($mark > $from) {
            $total += th_bh_between($from, $mark, $cal);
        }
    }

    return $total;
}

/**
 * Side effects of a status change, in one place because six different paths can
 * move a ticket: the reopen counter, and the stop-the-clock accounting.
 *
 * Entering Pending opens a pause interval. Leaving it banks the working time
 * waited and pushes the deadlines out by exactly that much, so "time remaining"
 * and "percent consumed" both stay honest.
 */
function th_status_change(array $before, array $upd): array
{
    $new = $upd['status'] ?? null;
    if ($new === null || $new === ($before['status'] ?? null)) {
        return $upd;
    }

    $upd = th_bump_reopen($before, $upd);

    $wasPaused = ($before['status'] ?? '') === 'Pending';
    $nowPaused = $new === 'Pending';
    if ($wasPaused === $nowPaused) {
        return $upd;
    }

    $cal = th_ticket_calendar($before);
    $now = time();

    if ($nowPaused) {
        $upd['paused_at'] = date('Y-m-d H:i:s', $now);

        return $upd;
    }

    // Resuming: bank the wait, then move the targets by the same working time.
    $from = ! empty($before['paused_at']) ? strtotime($before['paused_at']) : null;
    $waited = $from && $now > $from ? th_bh_between($from, $now, $cal) : 0;
    $upd['paused_at'] = null;
    $upd['paused_seconds'] = (int) ($before['paused_seconds'] ?? 0) + $waited;

    if ($waited > 0) {
        foreach (['fr_due', 'res_due'] as $field) {
            if (! empty($before[$field])) {
                $upd[$field] = date('Y-m-d H:i:s', th_bh_add(strtotime($before[$field]), $waited / 3600, $cal));
            }
        }
    }

    return $upd;
}

function th_sla(array $t): array
{
    $created  = strtotime($t['created_at']);
    $resDue   = strtotime($t['res_due']);
    // Coalesced: th_sla() is called from a dozen places, some with rows built in
    // memory rather than read back, and a missing key must not be a 500.
    $resolved = ! empty($t['resolved_at']) ? strtotime($t['resolved_at']) : null;
    $now      = time();
    $done     = ! th_is_open($t);

    // Consumption is measured in working time, so the clock stops overnight,
    // at weekends and on holidays for teams that do not run 24/7. The deadline
    // itself stays a real instant, so the countdown remains wall-clock.
    $cal    = th_ticket_calendar($t);
    $mark   = $done ? ($resolved ?? $resDue) : $now;
    // Waiting on the requester counts against neither side of the ratio: the
    // deadline was already pushed out by the same amount when the ticket resumed.
    $paused  = th_paused_working($t, $cal, $mark);
    $total   = max(1, th_bh_between($created, $resDue, $cal) - $paused);
    $elapsed = max(0, th_bh_between($created, $mark, $cal) - $paused);
    $pct     = max(0, min(100, ($elapsed / $total) * 100));
    $remaining = $resDue - $now;

    $state = 'ok';
    if ($done) {
        $state = ($resolved !== null && $resolved <= $resDue) ? 'met' : 'missed';
    } elseif ($remaining <= 0) {
        $state = 'breached';
    } elseif ($pct >= 75) {
        $state = 'warn';
    }

    return ['pct' => $pct, 'remaining' => $remaining, 'state' => $state, 'done' => $done];
}

function th_sla_label(array $t): string
{
    $s = th_sla($t);
    if ($s['done']) {
        return $s['state'] === 'met' ? 'Met' : 'Missed';
    }

    return ($s['remaining'] <= 0 ? 'Over by ' : '') . th_dur(abs($s['remaining']));
}

function th_sla_color(array $t): string
{
    return TH_SLA_COLOR[th_sla($t)['state']];
}

/**
 * Minutes from what an agent would actually type: "90", "1h30", "1h 30m", "45m",
 * "1.5h". Returns 0 when nothing sensible is in there.
 */
function th_parse_minutes(string $text): int
{
    $text = trim(mb_strtolower($text));
    if ($text === '') {
        return 0;
    }
    if (preg_match('/^(\d+(?:\.\d+)?)\s*h(?:ours?)?\s*(\d+)?\s*m?(?:ins?|inutes?)?$/', $text, $m)) {
        return (int) round((float) $m[1] * 60) + (int) ($m[2] ?? 0);
    }
    if (preg_match('/^(\d+)\s*m(?:ins?|inutes?)?$/', $text, $m)) {
        return (int) $m[1];
    }
    if (preg_match('/^\d+$/', $text)) {
        return (int) $text;
    }

    return 0;
}

/** "1h 30m" from 90. */
function th_minutes(int $minutes): string
{
    if ($minutes < 60) {
        return $minutes . 'm';
    }
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;

    return $m ? $h . 'h ' . $m . 'm' : $h . 'h';
}

/** Duration label from seconds. */
function th_dur(int $seconds): string
{
    $seconds = abs($seconds);
    $m = intdiv($seconds, 60);
    $h = intdiv($m, 60);
    $d = intdiv($h, 24);
    if ($d >= 1) {
        return $d . 'd ' . ($h % 24) . 'h';
    }
    if ($h >= 1) {
        return $h . 'h ' . ($m % 60) . 'm';
    }

    return max($m, 0) . 'm';
}

function th_rel(string|int|null $dt): string
{
    if ($dt === null) {
        return '—';
    }
    $ts = is_int($dt) ? $dt : strtotime($dt);
    $diff = time() - $ts;
    if ($diff < 0) {
        return 'in ' . th_dur(-$diff);
    }
    if ($diff < 60) {
        return 'just now';
    }

    return th_dur($diff) . ' ago';
}

/** Display timezone from admin settings (storage stays UTC). */
function th_tz(): \DateTimeZone
{
    static $tz = null;
    if ($tz === null) {
        try {
            $name = \App\Libraries\Settings::get('app_timezone', 'UTC') ?: 'UTC';
            $tz = new \DateTimeZone($name);
        } catch (\Throwable) {
            $tz = new \DateTimeZone('UTC');
        }
    }

    return $tz;
}

function th_local(string|int $dt): \DateTime
{
    $d = is_int($dt) ? (new \DateTime('@' . $dt)) : new \DateTime($dt, new \DateTimeZone('UTC'));

    return $d->setTimezone(th_tz());
}

function th_date(string|int|null $dt): string
{
    return $dt === null ? '—' : th_local($dt)->format('j M, H:i');
}

function th_day(string|int|null $dt): string
{
    return $dt === null ? '—' : th_local($dt)->format('j M Y');
}

/** Attachment chips with authenticated download links. */
function th_att_chips(string|array|null $atts): string
{
    $list = is_string($atts) ? (json_decode($atts, true) ?: []) : ($atts ?? []);
    if (! $list) {
        return '';
    }
    $html = '<div class="flex flex-wrap gap-2 mt-3">';
    foreach ($list as $a) {
        $kb = isset($a['s']) ? ' · ' . max(1, (int) round($a['s'] / 1024)) . ' KB' : '';
        $html .= '<a href="' . site_url('files/' . $a['f']) . '?n=' . urlencode($a['n']) . '"'
            . ' class="inline-flex items-center gap-1.5 h-7 px-2 rounded-lg border border-line bg-white text-[12px] text-ink-500 hover:bg-canvas hover:border-[#CBD1DC]">'
            . th_icon('clip', 'w-3.5 h-3.5 text-faint') . esc($a['n']) . '<span class="text-faint font-mono text-[10.5px]">' . $kb . '</span></a>';
    }

    return $html . '</div>';
}

/**
 * Sanitise author-supplied article HTML with a strict allowlist.
 *
 * Articles render unescaped in the agent workspace and the employee portal, so a
 * careless or compromised agent account must not be able to inject script into
 * every reader's page. Parses the markup and keeps only known-safe tags and
 * attributes — an allowlist, because blacklisting tags/patterns is bypassable
 * (e.g. entity-encoded "jav&#x09;ascript:" URLs).
 */
function th_sanitize_html(string $html): string
{
    $html = trim($html);
    if ($html === '') {
        return '';
    }

    $allowedTags = [
        'p', 'br', 'hr', 'strong', 'b', 'em', 'i', 'u', 'span', 'small',
        'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'code', 'pre', 'blockquote',
        'a', 'img', 'table', 'thead', 'tbody', 'tr', 'th', 'td',
    ];
    $allowedAttrs = ['a' => ['href', 'title'], 'img' => ['src', 'alt', 'title']];

    $urlIsSafe = static function (string $url): bool {
        // Normalise before checking: entities and control characters are how
        // "javascript:" gets smuggled past naive filters.
        $u = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $u = preg_replace('/[\x00-\x20\x7F]/', '', $u) ?? '';
        if ($u === '') {
            return false;
        }
        if (! preg_match('#^([a-z][a-z0-9+.\-]*):#i', $u, $m)) {
            return true; // relative URL
        }

        return in_array(strtolower($m[1]), ['http', 'https', 'mailto'], true);
    };

    $prev = libxml_use_internal_errors(true);
    $doc  = new DOMDocument();
    $doc->loadHTML('<?xml encoding="UTF-8"><div id="th-root">' . $html . '</div>', LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    $root = $doc->getElementById('th-root');
    if (! $root) {
        return '';
    }

    // Depth-first so children are visited before a parent is unwrapped.
    $walk = static function (DOMNode $node) use (&$walk, $allowedTags, $allowedAttrs, $urlIsSafe): void {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                $walk($child);
                $tag = strtolower($child->nodeName);

                if (! in_array($tag, $allowedTags, true)) {
                    // Drop scripting containers entirely; unwrap harmless unknowns.
                    if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'form', 'link', 'meta', 'svg', 'math'], true)) {
                        $child->parentNode->removeChild($child);
                    } else {
                        while ($child->firstChild) {
                            $child->parentNode->insertBefore($child->firstChild, $child);
                        }
                        $child->parentNode->removeChild($child);
                    }

                    continue;
                }

                foreach (iterator_to_array($child->attributes) as $attr) {
                    $name = strtolower($attr->nodeName);
                    if (! in_array($name, $allowedAttrs[$tag] ?? [], true)) {
                        $child->removeAttribute($attr->nodeName);

                        continue;
                    }
                    if (in_array($name, ['href', 'src'], true) && ! $urlIsSafe($attr->nodeValue ?? '')) {
                        $child->setAttribute($name, '#');
                    }
                }
            } elseif ($child instanceof DOMComment || $child instanceof DOMProcessingInstruction) {
                $child->parentNode->removeChild($child);
            }
        }
    };
    $walk($root);

    $out = '';
    foreach ($root->childNodes as $child) {
        $out .= $doc->saveHTML($child);
    }

    return trim($out);
}

/**
 * Distinctive keywords from free text, for similarity scoring (merge
 * suggestions, problem linking). Strips filler that would otherwise make
 * unrelated tickets look related — a shared "new" means nothing.
 */
function th_keywords(string $text): array
{
    static $stop = [
        'the', 'and', 'for', 'not', 'with', 'from', 'this', 'that', 'have', 'has', 'had', 'was', 'were',
        'are', 'but', 'you', 'your', 'our', 'can', 'cant', 'will', 'wont', 'when', 'what', 'they', 'them',
        'get', 'got', 'any', 'all', 'out', 'about', 'into', 'every', 'been', 'since', 'after', 'before',
        'again', 'ticket', 'tickets', 'issue', 'issues', 'please', 'new', 'old', 'under', 'over', 'using',
        'use', 'used', 'need', 'needs', 'needed', 'work', 'works', 'working', 'still', 'just', 'only',
        'also', 'more', 'most', 'some', 'one', 'two', 'day', 'days', 'week', 'time', 'times', 'now',
        'today', 'morning', 'afternoon', 'yesterday', 'here', 'there', 'then', 'than', 'why', 'how',
        'would', 'could', 'should', 'says', 'said', 'see', 'seen', 'try', 'tried', 'trying', 'help',
        'thanks', 'hi', 'hello', 'team', 'user', 'users', 'people', 'someone', 'anything', 'something',
        'other', 'another', 'same', 'back', 'down', 'off', 'per', 'via', 'set', 'run', 'runs',
    ];

    preg_match_all('/[a-z0-9]{3,}/i', mb_strtolower($text), $m);

    return array_values(array_diff(array_unique($m[0]), $stop));
}

/**
 * Rank published articles against what someone is typing, so a request can be
 * answered before it becomes a ticket. Shared by the agent and portal forms —
 * same scoring, different link target.
 *
 * @return list<array{a: array, score: int}> best first, at most $limit
 */
function th_kb_suggest(array $articles, string $q, int $limit = 3): array
{
    $words = th_keywords($q);
    if (mb_strlen(trim($q)) < 4 || ! $words) {
        return [];
    }

    $scored = [];
    foreach ($articles as $a) {
        $title = th_keywords($a['title']);
        $body  = th_keywords($a['category'] . ' ' . strip_tags((string) $a['body']) . ' ' . (string) $a['tags']);

        $score = 0;
        foreach ($words as $w) {
            if (in_array($w, $title, true)) {
                $score += 5;      // a title hit means far more than a passing mention
            } elseif (in_array($w, $body, true)) {
                $score++;
            }
        }
        if ($score > 0) {
            $scored[] = ['a' => $a, 'score' => $score];
        }
    }
    // Ties broken by readership: the article people actually use wins.
    usort($scored, static fn ($x, $y) => $y['score'] <=> $x['score'] ?: (int) $y['a']['views'] <=> (int) $x['a']['views']);

    return array_slice($scored, 0, $limit);
}

/**
 * Guard for admin-configurable outbound URLs (integration base URLs).
 * Blocks non-HTTP schemes and link-local / cloud-metadata addresses so a
 * hijacked admin session cannot turn an integration into an SSRF probe.
 * Returns an error string, or null when the URL is acceptable.
 */
function th_outbound_url_error(string $url): ?string
{
    $url = trim($url);
    if ($url === '') {
        return null; // empty falls back to the built-in default
    }
    $parts = parse_url($url);
    if (! $parts || empty($parts['scheme']) || empty($parts['host'])) {
        return 'That does not look like a valid URL.';
    }
    if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
        return 'Only http and https URLs are allowed.';
    }

    $host = strtolower($parts['host']);
    $ips  = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
    foreach ($ips as $ip) {
        // 169.254.0.0/16 covers the AWS/Azure/GCP metadata endpoints.
        if (str_starts_with($ip, '169.254.')) {
            return 'That address range (link-local / cloud metadata) is not allowed.';
        }
    }
    if (in_array($host, ['metadata.google.internal', 'metadata'], true)) {
        return 'That host is not allowed.';
    }

    return null;
}

/** Render one admin-defined custom field (ticket_fields row) as a form control. */
function th_custom_field(array $f): string
{
    $name  = 'cf_' . $f['id'];
    $req   = (int) $f['required'] === 1;
    $star  = $req ? '<span class="text-alert"> *</span>' : '';
    $reqAttr = $req ? ' required' : '';
    $base  = 'w-full h-9 px-2.5 rounded-lg border border-line bg-white text-[13px] placeholder:text-faint focus:border-brand';
    $label = '<label class="block text-[12px] font-medium text-ink-500 mb-1.5">' . esc($f['label']) . $star . '</label>';

    switch ($f['type']) {
        case 'Paragraph':
            $ctl = '<textarea name="' . $name . '" rows="3"' . $reqAttr . ' class="w-full px-2.5 py-2 rounded-lg border border-line text-[13px] leading-relaxed focus:border-brand"></textarea>';
            break;

        case 'Dropdown':
        case 'Lookup':
            $opts = json_decode($f['options'] ?? '[]', true) ?: [];
            if (! $opts) { // no options defined yet — degrade to free text so the form stays usable
                $ctl = '<input name="' . $name . '" type="text"' . $reqAttr . ' class="' . $base . '">';
                break;
            }
            $ctl = '<select name="' . $name . '"' . $reqAttr . ' class="' . $base . '">';
            if (! $req) {
                $ctl .= '<option value="">—</option>';
            }
            foreach ($opts as $o) {
                $ctl .= '<option>' . esc($o) . '</option>';
            }
            $ctl .= '</select>';
            break;

        case 'Checkbox':
            return '<div class="flex items-center pt-6"><label class="inline-flex items-center gap-2 text-[13px] text-ink-500 cursor-pointer">'
                . '<input type="checkbox" name="' . $name . '" value="Yes" class="w-[15px] h-[15px] rounded border-line"> ' . esc($f['label']) . '</label></div>';

        case 'Date':
            $ctl = '<input name="' . $name . '" type="date"' . $reqAttr . ' class="' . $base . ' font-mono">';
            break;

        default: // Text
            $ctl = '<input name="' . $name . '" type="text"' . $reqAttr . ' class="' . $base . '">';
    }

    return '<div>' . $label . $ctl . '</div>';
}

/** Prev/next pager for filtered lists. $qs = current query string params (page replaced). */
function th_pager(int $total, int $page, int $perPage, string $baseUrl, array $qs = []): string
{
    if ($total <= $perPage) {
        return '';
    }
    $pages = (int) ceil($total / $perPage);
    $link = static function (int $p) use ($baseUrl, $qs) {
        return $baseUrl . '?' . http_build_query(array_filter(['page' => $p] + $qs, static fn ($v) => $v !== '' && $v !== null));
    };
    $from = ($page - 1) * $perPage + 1;
    $to   = min($total, $page * $perPage);
    $btn  = 'inline-flex items-center gap-1 h-8 px-2.5 rounded-lg border border-line bg-white text-[12.5px] font-medium text-ink-500 hover:bg-canvas';
    $off  = 'inline-flex items-center gap-1 h-8 px-2.5 rounded-lg border border-line bg-canvas text-[12.5px] text-faint pointer-events-none';

    return '<div class="flex items-center gap-2 px-4 h-12 border-t border-line">'
        . '<span class="text-[12px] text-muted">Showing <span class="font-mono">' . $from . '–' . $to . '</span> of <span class="font-mono">' . $total . '</span></span>'
        . '<div class="flex-1"></div>'
        . '<a ' . ($page > 1 ? 'href="' . $link($page - 1) . '" class="' . $btn . '"' : 'class="' . $off . '"') . '>' . th_icon('back', 'w-3.5 h-3.5') . 'Prev</a>'
        . '<span class="font-mono text-[12px] text-muted">' . $page . ' / ' . $pages . '</span>'
        . '<a ' . ($page < $pages ? 'href="' . $link($page + 1) . '" class="' . $btn . '"' : 'class="' . $off . '"') . '>Next' . th_icon('right', 'w-3.5 h-3.5') . '</a>'
        . '</div>';
}

/* ---------- small layout builders ---------- */

function th_card(string $inner, string $cls = ''): string
{
    return '<section class="bg-white border border-line rounded-xl shadow-card ' . $cls . '">' . $inner . '</section>';
}

function th_card_head(string $title, string $right = ''): string
{
    return '<div class="flex items-center justify-between gap-3 px-4 h-12 border-b border-line">'
        . '<h2 class="font-display text-[14px] font-semibold text-ink">' . $title . '</h2>'
        . '<div class="flex items-center gap-2 text-[12px]">' . $right . '</div></div>';
}

/** Generic button. $attrs is a raw attribute string (e.g. 'type="submit"' or 'data-modal="newTicket"'). */
function th_btn(string $label, string $attrs = '', string $kind = 'ghost', string $ic = ''): string
{
    $styles = [
        'solid'  => 'bg-ink text-white hover:bg-ink-700 border-ink',
        'brand'  => 'bg-brand text-white hover:bg-brand-600 border-brand',
        'ghost'  => 'bg-white text-ink-500 hover:bg-canvas border-line',
        'danger' => 'bg-white text-alert hover:bg-alert-50 border-alert-100',
    ];

    return '<button ' . $attrs . ' class="inline-flex items-center gap-1.5 h-8 px-2.5 rounded-lg border text-[12.5px] font-medium transition ' . $styles[$kind] . '">'
        . ($ic ? th_icon($ic, 'w-3.5 h-3.5') : '') . $label . '</button>';
}

function th_kpi(string $label, string|int $value, string $sub, string $tone = 'ink', string $href = ''): string
{
    $tones = ['ink' => 'text-ink', 'alert' => 'text-alert', 'signal' => 'text-signal', 'brand' => 'text-brand'];
    $tag   = $href ? 'a' : 'div';
    $hrefAttr = $href ? ' href="' . $href . '"' : '';

    return '<' . $tag . $hrefAttr . ' class="block text-left bg-white border border-line rounded-xl shadow-card p-3.5 hover:border-[#CBD1DC] transition">'
        . '<div class="text-[11px] font-semibold uppercase tracking-[.09em] text-faint">' . $label . '</div>'
        . '<div class="font-mono text-[26px] leading-none mt-2.5 ' . $tones[$tone] . ' tick">' . $value . '</div>'
        . '<div class="text-[11.5px] text-muted mt-2">' . $sub . '</div></' . $tag . '>';
}

function th_empty(string $ic, string $title, string $body, string $action = ''): string
{
    return '<div class="py-16 px-6 text-center">'
        . '<div class="w-11 h-11 mx-auto rounded-xl bg-canvas border border-line grid place-items-center text-faint">' . th_icon($ic, 'w-5 h-5') . '</div>'
        . '<h3 class="font-display text-[15px] font-semibold mt-3">' . esc($title) . '</h3>'
        . '<p class="text-[13px] text-muted mt-1 max-w-sm mx-auto">' . esc($body) . '</p>'
        . ($action ? '<div class="mt-4 flex justify-center">' . $action . '</div>' : '') . '</div>';
}

function th_table_head(array $cols): string
{
    $cells = '';
    foreach ($cols as $c) {
        $cells .= '<span class="' . $c[1] . '">' . $c[0] . '</span>';
    }

    return '<div class="hidden md:flex items-center gap-3 px-4 h-9 bg-canvas border-b border-line text-[11px] font-semibold uppercase tracking-[.09em] text-faint">' . $cells . '</div>';
}

/** Toggle switch that submits a POST form. */
function th_toggle(bool $on, string $action): string
{
    return '<form method="post" action="' . $action . '" class="inline">' . csrf_field()
        . '<button type="submit" role="switch" aria-checked="' . ($on ? 'true' : 'false') . '"'
        . ' class="w-9 h-[20px] rounded-full transition relative shrink-0 ' . ($on ? 'bg-brand' : 'bg-[#D3D8E0]') . '">'
        . '<i class="absolute top-[2px] w-4 h-4 rounded-full bg-white shadow transition-all ' . ($on ? 'left-[18px]' : 'left-[2px]') . '"></i>'
        . '</button></form>';
}

/** Property-panel row (ticket sidebar). */
function th_prop_row(string $label, string $control): string
{
    return '<div class="flex items-center gap-3 px-3.5 py-2 border-b border-line last:border-0">'
        . '<span class="text-[12px] text-muted w-[86px] shrink-0">' . $label . '</span>'
        . '<div class="flex-1 min-w-0">' . $control . '</div></div>';
}

/** Select that auto-submits its parent form on change. $opts = [[value,label],...] */
function th_select(string $name, string $value, array $opts, string $extra = ''): string
{
    $html = '<select name="' . $name . '" ' . $extra . ' class="h-8 rounded-lg border border-line bg-white text-[12.5px] text-ink-500 pl-2.5">';
    foreach ($opts as $o) {
        [$v, $l] = is_array($o) ? $o : [$o, $o];
        $html .= '<option value="' . esc($v, 'attr') . '"' . ((string) $v === (string) $value ? ' selected' : '') . '>' . esc($l) . '</option>';
    }

    return $html . '</select>';
}

/**
 * Did this status change reopen the ticket? Six different paths can move a
 * ticket back out of Resolved/Closed — the reopen button, the status dropdown,
 * bulk edit, a portal reply, an inbound email reply and automation — so the
 * rule lives here rather than being restated (and drifting) at each one.
 */
function th_is_reopen(array $before, ?string $newStatus): bool
{
    $closed = ['Resolved', 'Closed'];

    return $newStatus !== null
        && in_array($before['status'] ?? '', $closed, true)
        && ! in_array($newStatus, $closed, true);
}

/** Merge a reopen bump into a pending ticket update, when the change is one. */
function th_bump_reopen(array $before, array $upd): array
{
    if (th_is_reopen($before, $upd['status'] ?? null)) {
        $upd['reopen_count'] = (int) ($before['reopen_count'] ?? 0) + 1;
    }

    return $upd;
}
/* ---------- asset facts ----------
   The asset record renders in two places — the quick-look modal and the full
   page — so the field sets live here rather than in either view. Each builder
   returns [[label, valueHtml, valueCls?], ...]; values are already escaped and
   may carry markup, so th_asset_dl() must not escape them again. */

function th_asset_dl(array $cells, string $gridCls = 'grid-cols-2'): string
{
    $html = '';
    foreach ($cells as $c) {
        $html .= '<div><dt class="text-[11px] uppercase tracking-[.09em] text-faint">' . esc($c[0]) . '</dt>'
            . '<dd class="text-[13px] mt-0.5 break-words ' . ($c[2] ?? 'text-ink-500') . '">' . $c[1] . '</dd></div>';
    }

    return '<dl class="grid ' . $gridCls . ' gap-x-4 gap-y-3">' . $html . '</dl>';
}

/** Em-dash for the blanks the seed data and PDQ sync both leave behind. */
function th_asset_val(?string $v): string
{
    return esc($v !== null && $v !== '' ? $v : '—');
}

function th_asset_identity(array $a): array
{
    return [
        ['Asset tag', '<span class="font-mono text-[12px]">' . esc($a['tag']) . '</span>'],
        ['Name', esc($a['name'])],
        ['Type', esc($a['type'])],
        ['Model', th_asset_val($a['model'])],
        ['Serial number', '<span class="font-mono text-[12px]">' . th_asset_val($a['serial']) . '</span>'],
        ['Operating system', th_asset_val($a['os'])],
    ];
}

function th_asset_assignment(array $a, ?array $holder): array
{
    return [
        ['Assigned to', $holder
            ? '<span class="inline-flex items-center gap-1.5">' . th_avatar($holder, 20) . esc($holder['name']) . '</span>'
            : '<span class="text-faint italic">Unassigned</span>'],
        ['Department', th_asset_val($holder['dept'] ?? null)],
        ['Site', th_asset_val($a['site'])],
        ['Status', esc($a['status'])],
    ];
}

function th_asset_warranty(array $a): array
{
    $wts     = $a['warranty_until'] ? strtotime($a['warranty_until']) : null;
    $expired = $wts !== null && $wts < time();
    $soon    = $wts !== null && ! $expired && $wts < time() + 90 * 86400;
    $tone    = $expired ? 'text-alert font-medium' : ($soon ? 'text-signal font-medium' : 'text-ink-500');

    return [
        ['Expires', $wts === null ? '—' : th_day($a['warranty_until']), $tone],
        ['Remaining', $wts === null ? '—'
            : ($expired ? 'Expired ' . th_dur(time() - $wts) . ' ago' : th_dur($wts - time()) . ' left'), $tone],
    ];
}

function th_asset_pdq(array $a): array
{
    return [
        ['Device ID', '<span class="font-mono text-[12px]">' . esc($a['pdq_device_id']) . '</span>'],
        ['Last synced', $a['pdq_synced_at'] ? th_rel($a['pdq_synced_at']) : '—'],
    ];
}
