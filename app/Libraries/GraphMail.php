<?php

namespace App\Libraries;

/**
 * Polls a Microsoft 365 mailbox through the Graph API and turns unread
 * messages into tickets (or replies) via TicketIntake.
 *
 * Uses the client-credentials flow against the same app registration as SSO
 * (Admin → Single sign-on). The app additionally needs the APPLICATION
 * permission Mail.ReadWrite (admin-consented); restrict it to the service
 * mailbox with an Exchange application access policy.
 */
class GraphMail
{
    public static function configured(): bool
    {
        return Settings::get('graph_inbound_enabled') === '1'
            && Settings::get('graph_mailbox') !== ''
            && Settings::get('azure_tenant_id') !== ''
            && Settings::get('azure_client_id') !== ''
            && Settings::get('azure_client_secret') !== '';
    }

    private static function token(): ?string
    {
        $client = service('curlrequest', ['timeout' => 15, 'http_errors' => false]);
        $res = $client->post(
            'https://login.microsoftonline.com/' . Settings::get('azure_tenant_id') . '/oauth2/v2.0/token',
            ['form_params' => [
                'client_id'     => Settings::get('azure_client_id'),
                'client_secret' => Settings::get('azure_client_secret'),
                'grant_type'    => 'client_credentials',
                'scope'         => 'https://graph.microsoft.com/.default',
            ]]
        );
        $json = json_decode((string) $res->getBody(), true) ?: [];
        if (empty($json['access_token'])) {
            log_message('error', 'GraphMail token error: {body}', ['body' => (string) $res->getBody()]);

            return null;
        }

        return $json['access_token'];
    }

    private static function htmlToText(string $html): string
    {
        $html = preg_replace('/<(style|script)\b[^>]*>.*?<\/\1>/is', '', $html);
        $html = preg_replace('/<br\s*\/?>|<\/p>|<\/div>/i', "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+/", ' ', $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }

    /** Download a message's file attachments and store them on disk. */
    private static function fetchAttachments($client, array $headers, string $mailbox, string $messageId): array
    {
        $out = [];
        try {
            $res = $client->get(
                'https://graph.microsoft.com/v1.0/users/' . $mailbox . '/messages/' . rawurlencode($messageId) . '/attachments',
                ['headers' => $headers]
            );
            if ($res->getStatusCode() >= 400) {
                log_message('error', 'GraphMail attachment list failed for {id}', ['id' => $messageId]);

                return [];
            }
            $json = json_decode((string) $res->getBody(), true) ?: [];
            foreach ($json['value'] ?? [] as $a) {
                // Only real file attachments; skip inline images and item attachments.
                if (($a['@odata.type'] ?? '') !== '#microsoft.graph.fileAttachment' || ! empty($a['isInline'])) {
                    continue;
                }
                $bytes = base64_decode((string) ($a['contentBytes'] ?? ''), true);
                if ($bytes === false || $bytes === '') {
                    continue;
                }
                if ($stored = TicketIntake::storeRawAttachment((string) ($a['name'] ?? 'attachment'), $bytes)) {
                    $out[] = $stored;
                }
            }
        } catch (\Throwable $e) {
            log_message('error', 'GraphMail attachment fetch failed: {msg}', ['msg' => $e->getMessage()]);
        }

        return $out;
    }

    /**
     * Fetch unread messages, process them, mark them read. Returns human-readable action lines.
     *
     * Serialised through a lock file: the cron and Admin → "Fetch now" can run at
     * the same moment, and two polls reading the same unread list would file
     * every message twice before either marked it read.
     */
    public static function poll(int $limit = 25): array
    {
        if (! self::configured()) {
            return ['Graph mailbox polling is not configured.'];
        }
        $lockPath = WRITEPATH . 'cache/graph-poll.lock';
        if (! is_dir(dirname($lockPath))) {
            @mkdir(dirname($lockPath), 0775, true);
        }
        $lock = @fopen($lockPath, 'c');
        if ($lock && ! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            return ['A mailbox poll is already running — skipped this one.'];
        }
        try {
            return self::pollLocked($limit);
        } finally {
            if ($lock) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /**
     * Pull the headers we thread, loop-guard and verify senders on out of
     * Graph's header list. The FIRST occurrence of each wins on purpose: mail
     * headers are prepended, so the top Authentication-Results is the one our
     * own Exchange Online added — any copy further down came with the message
     * and could have been forged by the sender.
     */
    private static function headerMap(array $msg): array
    {
        $want = ['auto-submitted', 'x-auto-response-suppress', 'precedence', 'in-reply-to', 'references', 'x-autoreply', 'x-autorespond',
            'authentication-results', 'x-ms-exchange-organization-authas'];
        $out  = [];
        foreach ((array) ($msg['internetMessageHeaders'] ?? []) as $h) {
            $name = strtolower((string) ($h['name'] ?? ''));
            if (in_array($name, $want, true) && ! isset($out[$name])) {
                $out[$name] = (string) ($h['value'] ?? '');
            }
        }

        return $out;
    }

    private static function pollLocked(int $limit): array
    {
        $token = self::token();
        if (! $token) {
            return ['Could not get a Graph token — check the tenant/client/secret under Single sign-on and the Mail.ReadWrite application permission.'];
        }

        $mailbox = rawurlencode(Settings::get('graph_mailbox'));
        $client  = service('curlrequest', ['timeout' => 20, 'http_errors' => false]);
        $headers = ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'];

        $res = $client->get(
            'https://graph.microsoft.com/v1.0/users/' . $mailbox . '/mailFolders/Inbox/messages'
            . '?$filter=isRead%20eq%20false&$top=' . $limit . '&$orderby=receivedDateTime%20asc'
            . '&$select=id,subject,from,body,bodyPreview,receivedDateTime,hasAttachments,internetMessageId,internetMessageHeaders',
            ['headers' => $headers]
        );
        $json = json_decode((string) $res->getBody(), true) ?: [];
        if ($res->getStatusCode() >= 400) {
            $err = $json['error']['message'] ?? ('HTTP ' . $res->getStatusCode());
            log_message('error', 'GraphMail list error: {err}', ['err' => $err]);

            return ['Graph error while listing messages: ' . $err];
        }

        $messages = $json['value'] ?? [];
        if (! $messages) {
            return ['Mailbox checked — no unread messages.'];
        }

        $intake  = new TicketIntake();
        $actions = [];
        foreach ($messages as $msg) {
            $from    = (string) ($msg['from']['emailAddress']['address'] ?? '');
            $subject = (string) ($msg['subject'] ?? '');
            $body    = (string) ($msg['body']['content'] ?? '');
            $text    = ($msg['body']['contentType'] ?? '') === 'html' ? self::htmlToText($body) : trim($body);
            if ($text === '') {
                $text = (string) ($msg['bodyPreview'] ?? '');
            }

            // Attachments are fetched lazily: intake only asks for them once the
            // sender has been accepted, so dropped mail leaves nothing on disk.
            $atts    = [];
            $fetch   = static function () use (&$atts, $client, $headers, $mailbox, $msg) {
                return $atts = ! empty($msg['hasAttachments']) ? self::fetchAttachments($client, $headers, $mailbox, (string) $msg['id']) : [];
            };
            $hmap = self::headerMap($msg);
            $meta = [
                'message_id'  => (string) ($msg['internetMessageId'] ?? ''),
                'from_name'   => (string) ($msg['from']['emailAddress']['name'] ?? ''),
                'in_reply_to' => $hmap['in-reply-to'] ?? '',
                'references'  => $hmap['references'] ?? '',
                'headers'     => $hmap,
                // Exchange strips X-MS-Exchange-Organization-* from mail arriving
                // from outside, so on this path "AuthAs: Internal" is trustworthy.
                'source'      => 'graph',
            ];

            $result = $intake->inboundEmail($from, $subject, $text, $fetch, $meta);
            $actions[] = $result['ok']
                ? $result['ticket'] . ' ' . $result['action'] . ' (from ' . $from . ')' . ($atts ? ' with ' . count($atts) . ' attachment(s)' : '')
                : ucfirst($result['action']) . ' message from ' . ($from ?: '?') . ' — ' . $result['reason'];

            // Mark processed messages read so they are not ingested twice.
            $patch = $client->patch(
                'https://graph.microsoft.com/v1.0/users/' . $mailbox . '/messages/' . rawurlencode($msg['id']),
                ['headers' => $headers + ['Content-Type' => 'application/json'], 'body' => json_encode(['isRead' => true])]
            );
            if ($patch->getStatusCode() >= 400) {
                log_message('error', 'GraphMail mark-read failed for message {id}', ['id' => $msg['id']]);
                $actions[] = 'Warning: could not mark a message read — check the Mail.ReadWrite permission.';
            }
        }

        return $actions;
    }
}
