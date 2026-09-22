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
 * JSON body: { "from": "user@example.com", "subject": "...", "text": "..." }
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
        $atts = [];
        foreach ((array) ($payload['attachments'] ?? []) as $a) {
            if (! is_array($a) || empty($a['name']) || empty($a['content'])) {
                continue;
            }
            $bytes = base64_decode((string) $a['content'], true);
            if ($bytes === false || $bytes === '') {
                continue;
            }
            if ($stored = TicketIntake::storeRawAttachment((string) $a['name'], $bytes)) {
                $atts[] = $stored;
            }
        }

        $result = (new TicketIntake())->inboundEmail(
            (string) ($payload['from'] ?? ''),
            (string) ($payload['subject'] ?? ''),
            (string) ($payload['text'] ?? ''),
            $atts
        );

        if (! $result['ok']) {
            return $this->response->setStatusCode(422)->setJSON(['error' => $result['reason']]);
        }

        return $this->response->setJSON([
            'ok' => true, 'ticket' => $result['ticket'], 'action' => $result['action'],
            'attachments' => count($atts),
        ]);
    }
}
