<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Libraries\Settings;
use App\Libraries\TicketIntake;

/**
 * Inbound email webhook — point your mail provider's inbound parse
 * (Mailgun routes, SendGrid inbound parse, Postmark inbound, etc.) here.
 * (Prefer the Graph mailbox poller for Microsoft 365 — Admin → Email settings.)
 *
 * POST /api/inbound-email  with header X-Inbound-Secret: <secret>
 * JSON body: {
 *   "from": "user@example.com" | "Name <user@example.com>", "from_name": "...",
 *   "subject": "...", "text": "...",
 *   "message_id": "<...>", "in_reply_to": "<...>", "references": "<...> <...>",
 *   "headers": { "Auto-Submitted": "...", "Precedence": "...", ... },
 *   "attachments": [{ "name": "log.txt", "content": "<base64>" }]
 * }
 * Everything but from/subject/text is optional.
 */
class InboundEmailController extends BaseController
{
    public function receive()
    {
        if (Settings::get('inbound_email_enabled') !== '1') {
            return $this->response->setStatusCode(403)->setJSON(['error' => 'inbound email is disabled']);
        }
        // Header only — a secret in the query string ends up in access and proxy logs.
        $secret = (string) $this->request->getHeaderLine('X-Inbound-Secret');
        if ($secret === '' || ! hash_equals(Settings::get('inbound_email_secret'), $secret)) {
            return $this->response->setStatusCode(401)->setJSON(['error' => 'bad secret']);
        }

        $payload = $this->request->getJSON(true) ?? [];

        // Optional attachments: [{ "name": "log.txt", "content": "<base64>" }, ...]
        // Decoded and written only once intake has accepted the sender.
        $stored = [];
        $atts   = static function () use (&$stored, $payload) {
            foreach ((array) ($payload['attachments'] ?? []) as $a) {
                if (! is_array($a) || empty($a['name']) || empty($a['content'])) {
                    continue;
                }
                $bytes = base64_decode((string) $a['content'], true);
                if ($bytes === false || $bytes === '') {
                    continue;
                }
                if ($s = TicketIntake::storeRawAttachment((string) $a['name'], $bytes)) {
                    $stored[] = $s;
                }
            }

            return $stored;
        };

        $headers = [];
        foreach ((array) ($payload['headers'] ?? []) as $k => $v) {
            if (is_string($k) && is_scalar($v)) {
                $headers[$k] = (string) $v;
            }
        }

        $result = (new TicketIntake())->inboundEmail(
            (string) ($payload['from'] ?? ''),
            (string) ($payload['subject'] ?? ''),
            (string) ($payload['text'] ?? ''),
            $atts,
            [
                'message_id'  => (string) ($payload['message_id'] ?? ($headers['Message-ID'] ?? $headers['Message-Id'] ?? '')),
                'in_reply_to' => (string) ($payload['in_reply_to'] ?? ($headers['In-Reply-To'] ?? '')),
                'references'  => $payload['references'] ?? ($headers['References'] ?? ''),
                'from_name'   => (string) ($payload['from_name'] ?? ''),
                'headers'     => $headers,
            ]
        );

        if (! $result['ok']) {
            // Duplicates and auto-replies are handled, not errors: a 2xx stops the
            // provider retrying them.
            $code = in_array($result['action'], ['duplicate', 'ignored'], true) ? 200 : 422;

            return $this->response->setStatusCode($code)->setJSON(['ok' => false, 'action' => $result['action'], 'error' => $result['reason']]);
        }

        return $this->response->setJSON([
            'ok' => true, 'ticket' => $result['ticket'], 'action' => $result['action'],
            'attachments' => count($stored),
        ]);
    }
}
