<?php

namespace App\Libraries;

/**
 * Outbound webhooks.
 *
 * dispatch() writes one webhook_deliveries row per active endpoint subscribed
 * to the event and tries to deliver each straight away. A failed attempt is
 * rescheduled with backoff (1m, 5m, 30m, 2h, 12h) and picked up by retryDue()
 * from `php spark tickethub:webhooks` (or tickets:cron). After 20 consecutive
 * failures an endpoint is switched off so a dead URL stops generating work.
 *
 * Every request carries:
 *   X-TicketHub-Event      the event name (e.g. ticket.created)
 *   X-TicketHub-Delivery   the delivery id (use it to de-duplicate retries)
 *   X-TicketHub-Signature  sha256=HMAC-SHA256(secret, raw body)
 * Body: {"event": "...", "occurred_at": "<ISO 8601>", "data": {...}}
 */
class Webhooks
{
    /** Retry delays in seconds, indexed by the number of attempts already made. */
    public const BACKOFF = [60, 300, 1800, 7200, 43200];

    /** Consecutive failures after which an endpoint is disabled. */
    public const DISABLE_AFTER = 20;

    public const TIMEOUT = 5;

    /** Event names an endpoint can subscribe to, with a human label. */
    public const EVENTS = [
        'ticket.created'   => 'Ticket created',
        'ticket.updated'   => 'Ticket updated (any change, reply or note)',
        'ticket.assigned'  => 'Ticket assigned',
        'ticket.replied'   => 'Public reply sent',
        'ticket.resolved'  => 'Ticket resolved',
        'ticket.closed'    => 'Ticket closed',
        'ticket.escalated' => 'Ticket escalated',
        'ticket.approved'  => 'Request approved',
        'ticket.rejected'  => 'Request rejected',
        'ticket.sla_warning' => 'SLA warning (80% used)',
        'ticket.time_reached' => 'Time-based automation fired',
        'webhook.test'     => 'Test event (sent from Admin)',
    ];

    /**
     * Human trigger names used across the app (automation triggers, email
     * template triggers, status changes) mapped to webhook event names.
     */
    public const EVENT_MAP = [
        'ticket is created'   => 'ticket.created',
        'ticket created'      => 'ticket.created',
        'ticket is updated'   => 'ticket.updated',
        'ticket updated'      => 'ticket.updated',
        'time is reached'     => 'ticket.time_reached',
        'ticket assigned'     => 'ticket.assigned',
        'public reply sent'   => 'ticket.replied',
        'status → resolved'   => 'ticket.resolved',
        'status -> resolved'  => 'ticket.resolved',
        'ticket resolved'     => 'ticket.resolved',
        'status → closed'     => 'ticket.closed',
        'ticket closed'       => 'ticket.closed',
        'ticket escalated'    => 'ticket.escalated',
        'request approved'    => 'ticket.approved',
        'request rejected'    => 'ticket.rejected',
        '80% of sla'          => 'ticket.sla_warning',
    ];

    /** Turn any trigger label into a dotted event name (unknown → ticket.<slug>). */
    public static function normalize(string $trigger): string
    {
        $key = mb_strtolower(trim($trigger));
        if (isset(self::EVENT_MAP[$key])) {
            return self::EVENT_MAP[$key];
        }
        if (preg_match('/^[a-z]+\.[a-z_]+$/', $key)) {
            return $key;
        }
        $slug = trim(preg_replace('/[^a-z0-9]+/', '_', str_replace(['→', '->'], ' ', $key)), '_');
        $slug = preg_replace('/^(ticket_|status_)?(is_)?/', '', $slug);

        return 'ticket.' . ($slug !== '' ? $slug : 'event');
    }

    /** The fields of a ticket that are safe to send to a third party. */
    public static function ticketPayload(array $t): array
    {
        $db = db_connect();
        $requester = $db->table('users')->select('name, email')->where('id', (int) $t['requester_id'])->get()->getRowArray();
        $agent     = ! empty($t['agent_id']) ? $db->table('users')->select('name, email')->where('id', (int) $t['agent_id'])->get()->getRowArray() : null;
        $group     = ! empty($t['group_id']) ? $db->table('groups')->select('name')->where('id', (int) $t['group_id'])->get()->getRowArray() : null;

        return [
            'code'       => $t['code'],
            'subject'    => $t['subject'],
            'status'     => $t['status'],
            'priority'   => $t['priority'],
            'type'       => $t['type'],
            'category'   => $t['category'],
            'source'     => $t['source'] ?? null,
            'group'      => $group['name'] ?? null,
            'agent'      => $agent ? ['name' => $agent['name'], 'email' => $agent['email']] : null,
            'requester'  => ['name' => $requester['name'] ?? null, 'email' => $requester['email'] ?? null],
            'tags'       => json_decode($t['tags'] ?? '[]', true) ?: [],
            'escalated'  => (bool) ($t['escalated'] ?? 0),
            'created_at' => $t['created_at'],
            'updated_at' => $t['updated_at'],
            'resolved_at' => $t['resolved_at'] ?? null,
            'url'        => site_url('app/tickets/' . $t['code']),
        ];
    }

    /** Was this event already queued for this ticket in the last $seconds? (guards derived events) */
    public static function recentlySent(string $event, string $ticketCode, int $seconds = 20): bool
    {
        try {
            return db_connect()->table('webhook_deliveries')->where('event', self::normalize($event))
                ->where('created_at >=', date('Y-m-d H:i:s', time() - $seconds))
                ->like('payload', '"code":"' . $ticketCode . '"')->countAllResults() > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Queue and attempt delivery of one event to every matching endpoint.
     * Never throws: a webhook problem must not break the action that caused it.
     */
    public static function dispatch(string $event, array $payload): void
    {
        try {
            $db = db_connect();
            if (! $db->tableExists('webhook_endpoints')) {
                return;
            }
            $event = self::normalize($event);
            $endpoints = $db->table('webhook_endpoints')->where('active', 1)->get()->getResultArray();
            if (! $endpoints) {
                return;
            }
            $body = [
                'event'       => $event,
                'occurred_at' => date('c'),
                'data'        => $payload,
            ];
            $now = date('Y-m-d H:i:s');
            foreach ($endpoints as $ep) {
                $events = json_decode($ep['events'] ?? '[]', true) ?: [];
                if (! in_array('*', $events, true) && ! in_array($event, $events, true)) {
                    continue;
                }
                $db->table('webhook_deliveries')->insert([
                    'endpoint_id' => (int) $ep['id'], 'event' => $event,
                    'payload' => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'attempts' => 0, 'next_attempt_at' => $now, 'created_at' => $now,
                ]);
                $id = (int) $db->insertID();
                $delivery = $db->table('webhook_deliveries')->where('id', $id)->get()->getRowArray();
                if ($delivery) {
                    self::attempt($delivery, $ep);
                }
            }
        } catch (\Throwable $e) {
            log_message('error', 'Webhooks::dispatch({event}) failed: {msg}', ['event' => $event, 'msg' => $e->getMessage()]);
        }
    }

    /** Retry every delivery whose next_attempt_at has passed. Returns how many were attempted. */
    public static function retryDue(int $limit = 200): int
    {
        $db = db_connect();
        if (! $db->tableExists('webhook_deliveries')) {
            return 0;
        }
        $due = $db->table('webhook_deliveries')
            ->where('delivered_at', null)
            ->where('next_attempt_at IS NOT NULL', null, false)
            ->where('next_attempt_at <=', date('Y-m-d H:i:s'))
            ->orderBy('next_attempt_at')->limit($limit)->get()->getResultArray();
        $n = 0;
        foreach ($due as $d) {
            $ep = $db->table('webhook_endpoints')->where('id', (int) $d['endpoint_id'])->get()->getRowArray();
            if (! $ep || ! (int) $ep['active']) {
                // Endpoint gone or switched off: stop retrying, keep the row for the log.
                $db->table('webhook_deliveries')->where('id', $d['id'])->update(['next_attempt_at' => null]);

                continue;
            }
            self::attempt($d, $ep);
            $n++;
        }

        return $n;
    }

    /** Force one more attempt right now (Admin → Retry). Returns true on 2xx. */
    public static function retryNow(int $deliveryId): bool
    {
        $db = db_connect();
        $d  = $db->table('webhook_deliveries')->where('id', $deliveryId)->get()->getRowArray();
        $ep = $d ? $db->table('webhook_endpoints')->where('id', (int) $d['endpoint_id'])->get()->getRowArray() : null;
        if (! $d || ! $ep) {
            return false;
        }

        return self::attempt($d, $ep);
    }

    /** One HTTP attempt; records the outcome and schedules the next try. */
    private static function attempt(array $delivery, array $ep): bool
    {
        $db   = db_connect();
        $body = (string) $delivery['payload'];
        $now  = date('Y-m-d H:i:s');
        $attempts = (int) $delivery['attempts'] + 1;

        $status  = null;
        $excerpt = '';
        try {
            if ($err = th_outbound_url_error((string) $ep['url'])) {
                throw new \RuntimeException($err);
            }
            $res = service('curlrequest', [], null, null, false)->post($ep['url'], [
                'body'        => $body,
                'timeout'     => self::TIMEOUT,
                'http_errors' => false,
                'headers'     => [
                    'Content-Type'          => 'application/json',
                    'User-Agent'            => 'TicketHub-Webhooks/1.0',
                    'X-TicketHub-Event'     => $delivery['event'],
                    'X-TicketHub-Delivery'  => (string) $delivery['id'],
                    'X-TicketHub-Signature' => 'sha256=' . hash_hmac('sha256', $body, (string) $ep['secret']),
                ],
            ]);
            $status  = $res->getStatusCode();
            $excerpt = mb_substr((string) $res->getBody(), 0, 500);
        } catch (\Throwable $e) {
            $excerpt = mb_substr('Error: ' . $e->getMessage(), 0, 500);
        }
        $ok = $status !== null && $status >= 200 && $status < 300;

        $upd = [
            'status' => $status, 'response_excerpt' => $excerpt, 'attempts' => $attempts,
            'delivered_at' => $ok ? $now : null,
            'next_attempt_at' => null,
        ];
        if (! $ok && isset(self::BACKOFF[$attempts - 1])) {
            $upd['next_attempt_at'] = date('Y-m-d H:i:s', time() + self::BACKOFF[$attempts - 1]);
        }
        $db->table('webhook_deliveries')->where('id', $delivery['id'])->update($upd);

        $epUpd = ['last_status' => $status];
        if ($ok) {
            $epUpd['last_delivered_at'] = $now;
            $epUpd['failures'] = 0;
        } else {
            $failures = (int) $ep['failures'] + 1;
            $epUpd['failures'] = $failures;
            if ($failures >= self::DISABLE_AFTER) {
                $epUpd['active'] = 0;
                Audit::log('webhook.auto_disabled', $ep['name'] . ' after ' . $failures . ' consecutive failures', null);
                log_message('warning', 'Webhook endpoint "{name}" disabled after {n} consecutive failures.', ['name' => $ep['name'], 'n' => $failures]);
            }
        }
        $db->table('webhook_endpoints')->where('id', $ep['id'])->update($epUpd);

        return $ok;
    }
}
