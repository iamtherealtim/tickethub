<?php

namespace App\Controllers;

class ReportsController extends BaseController
{
    public function index()
    {
        $m = $this->metrics();

        return view('agent/reports', $this->agentShared() + $m + ['title' => 'Reports', 'nav' => 'reports']);
    }

    /**
     * CSV of the same window the page is showing — summary figures first, then
     * the per-group breakdown. Shares metrics() with index() so an exported
     * number can never disagree with the one on screen.
     */
    public function export()
    {
        $m = $this->metrics();

        $out = fopen("php://temp", "r+");
        fputcsv($out, ["Metric", "Value", "Detail"]);
        fputcsv($out, ["Window", $m["range"] . " days", $m["ticketsInRange"] . " ticket(s) raised"]);
        fputcsv($out, ["Median first response", $m["medianFr"] ? th_dur($m["medianFr"]) : "-", "Across answered tickets"]);
        fputcsv($out, ["Median resolution", $m["medianRes"] ? th_dur($m["medianRes"]) : "-", "Across resolved tickets"]);
        fputcsv($out, ["SLA attainment", $m["slaPct"] === null ? "-" : $m["slaPct"] . "%", "Resolution, all priorities"]);
        fputcsv($out, ["Resolved total", $m["resolvedN"], "In the selected window"]);
        fputcsv($out, ["Reopen rate", $m["reopenPct"] === null ? "-" : $m["reopenPct"] . "%", $m["reopenedN"] . " of " . $m["resolvedN"] . " resolved"]);
        fputcsv($out, ["Time logged", $m["timeTotal"] ? th_minutes($m["timeTotal"]) : "-", "Across the window"]);
        fputcsv($out, ["Effort per ticket", $m["timePerTicket"] ? th_minutes($m["timePerTicket"]) : "-", "Mean across all raised"]);

        fputcsv($out, []);
        fputcsv($out, ["Agent", "Time logged"]);
        foreach ($m["timeByAgent"] as $k => $v) {
            fputcsv($out, [$k, th_minutes((int) $v)]);
        }

        fputcsv($out, []);
        fputcsv($out, ["Group", "Tickets", "Avg resolution", "SLA attainment"]);
        foreach ($m["groupPerf"] as $row) {
            fputcsv($out, [$row["g"]["name"], $row["n"], $row["avg"] ? th_dur($row["avg"]) : "-", $row["pct"] . "%"]);
        }

        fputcsv($out, []);
        fputcsv($out, ["Category", "Tickets"]);
        foreach ($m["cats"] as $k => $v) {
            fputcsv($out, [$k, $v]);
        }

        fputcsv($out, []);
        fputcsv($out, ["Source", "Tickets"]);
        foreach ($m["srcs"] as $k => $v) {
            fputcsv($out, [$k, $v]);
        }

        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $this->response
            ->setHeader("Content-Type", "text/csv")
            ->setHeader("Content-Disposition", "attachment; filename=\"tickethub-reports-" . $m["range"] . "d-" . date("Ymd-His") . ".csv\"")
            ->setBody($csv);
    }
    /** Every figure the reports page shows, for the selected window. */
    private function metrics(): array
    {
        $range   = (int) ($this->request->getGet('range') ?: 30);
        $range   = in_array($range, [7, 30, 90], true) ? $range : 30;
        $cutoff  = time() - $range * 86400;
        // Respect the same group visibility scope as the ticket list, then apply
        // the selected window to EVERY metric on the page (no silent fallback).
        $all     = $this->scopeTickets($this->db->table('tickets')->get()->getResultArray());
        $tickets = array_values(array_filter($all, static fn ($t) => strtotime($t['created_at']) >= $cutoff));

        $cats = [];
        $srcs = [];
        foreach ($tickets as $t) {
            $cats[$t['category']] = ($cats[$t['category']] ?? 0) + 1;
            $srcs[$t['source']]   = ($srcs[$t['source']] ?? 0) + 1;
        }
        arsort($cats);
        arsort($srcs);

        // medians
        $frTimes = [];
        $resTimes = [];
        $met = 0;
        $resolvedN = 0;
        $reopenedN = 0;
        foreach ($tickets as $t) {
            if ($t['responded_at']) {
                $frTimes[] = strtotime($t['responded_at']) - strtotime($t['created_at']);
            }
            if ($t['resolved_at']) {
                $resolvedN++;
                // Counted against tickets that reached a resolution, which is what
                // makes the ratio meaningful: how often "resolved" did not stick.
                $reopenedN += (int) $t['reopen_count'] > 0 ? 1 : 0;
                $resTimes[] = strtotime($t['resolved_at']) - strtotime($t['created_at']);
                if (strtotime($t['resolved_at']) <= strtotime($t['res_due'])) {
                    $met++;
                }
            }
        }
        $median = static function (array $xs) {
            if (! $xs) {
                return 0;
            }
            sort($xs);
            $mid = intdiv(count($xs), 2);

            return count($xs) % 2 ? $xs[$mid] : (int) (($xs[$mid - 1] + $xs[$mid]) / 2);
        };

        // Non-admins only see their own group's row.
        $visibleGroups = $this->agentShared()['groups'];
        if (! $this->isAdmin()) {
            $mine = (int) ($this->me['group_id'] ?? 0);
            $visibleGroups = array_values(array_filter($visibleGroups, static fn ($g) => (int) $g['id'] === $mine));
        }

        $groupPerf = [];
        foreach ($visibleGroups as $g) {
            $ts = array_values(array_filter($tickets, static fn ($t) => (int) $t['group_id'] === (int) $g['id']));
            $resolved = array_values(array_filter($ts, static fn ($t) => $t['resolved_at']));
            $avg = 0;
            $metG = 0;
            foreach ($resolved as $t) {
                $avg += strtotime($t['resolved_at']) - strtotime($t['created_at']);
                if (strtotime($t['resolved_at']) <= strtotime($t['res_due'])) {
                    $metG++;
                }
            }
            $avg = $resolved ? (int) ($avg / count($resolved)) : 0;
            $groupPerf[] = [
                'g' => $g, 'n' => count($ts), 'avg' => $avg,
                'pct' => $resolved ? (int) round($metG / count($resolved) * 100) : 100,
            ];
        }
        usort($groupPerf, static fn ($a, $b) => $b['n'] <=> $a['n']);

        // Effort, scoped the same way the rest of the page is: only tickets the
        // viewer can see contribute, so the totals match the counts beside them.
        $ids = array_column($tickets, 'id');
        $timeByAgent = [];
        $timeByCategory = [];
        $timeTotal = 0;
        if ($ids) {
            $rows = $this->db->query(
                'SELECT e.user_id, e.minutes, u.name AS who, t.category
                 FROM ticket_time_entries e
                 JOIN users u ON u.id = e.user_id
                 JOIN tickets t ON t.id = e.ticket_id
                 WHERE e.ticket_id IN (' . implode(',', array_map('intval', $ids)) . ')'
            )->getResultArray();
            foreach ($rows as $r) {
                $m = (int) $r['minutes'];
                $timeTotal += $m;
                $timeByAgent[$r['who']] = ($timeByAgent[$r['who']] ?? 0) + $m;
                $timeByCategory[$r['category']] = ($timeByCategory[$r['category']] ?? 0) + $m;
            }
            arsort($timeByAgent);
            arsort($timeByCategory);
        }

        return [
            'range' => $range, 'cats' => $cats, 'srcs' => $srcs,
            'medianFr' => $median($frTimes), 'medianRes' => $median($resTimes),
            'slaPct' => $resolvedN ? (int) round($met / $resolvedN * 100) : null,
            'resolvedN' => $resolvedN, 'groupPerf' => $groupPerf,
            'reopenedN' => $reopenedN,
            'reopenPct' => $resolvedN ? round($reopenedN / $resolvedN * 100, 1) : null,
            'ticketsInRange' => count($tickets),
            'timeByAgent' => $timeByAgent, 'timeByCategory' => $timeByCategory,
            'timeTotal' => $timeTotal,
            'timePerTicket' => $tickets ? (int) round($timeTotal / count($tickets)) : 0,
        ];
    }
}
