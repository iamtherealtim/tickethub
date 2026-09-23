<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\Audit;
use App\Libraries\Webhooks;

/** Admin → Webhooks: endpoints, event subscriptions, test sends and the delivery log. */
class WebhooksController extends BaseController
{
    private function payload(): ?array
    {
        $p    = $this->request->getPost();
        $name = trim((string) ($p['name'] ?? ''));
        $url  = trim((string) ($p['url'] ?? ''));
        if ($name === '' || $url === '') {
            $this->toast('A name and a URL are needed', 'warn');

            return null;
        }
        if ($err = th_outbound_url_error($url)) {
            $this->toast('URL rejected — ' . $err, 'warn');

            return null;
        }
        $events = array_values(array_filter((array) ($p['events'] ?? []), static fn ($e) => $e === '*' || isset(Webhooks::EVENTS[$e])));
        if (! $events) {
            $this->toast('Tick at least one event (or "All events")', 'warn');

            return null;
        }
        if (in_array('*', $events, true)) {
            $events = ['*'];
        }

        return [
            'name'   => mb_substr($name, 0, 80),
            'url'    => mb_substr($url, 0, 500),
            'events' => json_encode($events),
        ];
    }

    public function create()
    {
        if (! $data = $this->payload()) {
            return redirect()->to('/app/admin/webhooks');
        }
        $secret = bin2hex(random_bytes(24));
        $this->db->table('webhook_endpoints')->insert($data + [
            'secret' => \App\Libraries\Settings::encryptValue($secret, 'webhook secret'),
            'active' => 1, 'failures' => 0, 'created_at' => date('Y-m-d H:i:s'),
        ]);
        Audit::log('admin.webhook_added', $data['name'] . ' → ' . $data['url']);
        // Shown once, like API tokens: the signing secret is what the receiver verifies with.
        $this->session->setFlashdata('new_webhook_secret', $secret);
        $this->toast('Webhook created — copy the signing secret now');

        return redirect()->to('/app/admin/webhooks');
    }

    public function update(int $id)
    {
        $ep = $this->db->table('webhook_endpoints')->where('id', $id)->get()->getRowArray();
        if (! $ep) {
            $this->toast('Webhook not found', 'warn');

            return redirect()->to('/app/admin/webhooks');
        }
        if (! $data = $this->payload()) {
            return redirect()->to('/app/admin/webhooks');
        }
        if ($this->request->getPost('rotate_secret')) {
            $plain          = bin2hex(random_bytes(24));
            $data['secret'] = \App\Libraries\Settings::encryptValue($plain, 'webhook secret');
            $this->session->setFlashdata('new_webhook_secret', $plain);
        }
        // Editing is an explicit "try again" — clear the failure streak.
        $data['failures'] = 0;
        $this->db->table('webhook_endpoints')->where('id', $id)->update($data);
        Audit::log('admin.webhook_updated', $data['name']);
        $this->toast($data['name'] . ' updated' . (isset($data['secret']) ? ' — new signing secret below' : ''));

        return redirect()->to('/app/admin/webhooks');
    }

    /** outbound_allow_private: whether integrations may call private-network addresses (see th_outbound_resolve). */
    public function savePolicy()
    {
        $on = $this->request->getPost('outbound_allow_private') ? '1' : '0';
        \App\Libraries\Settings::set('outbound_allow_private', $on);
        Audit::log('admin.outbound_private_' . ($on === '1' ? 'allowed' : 'blocked'));
        $this->toast($on === '1' ? 'Integrations may now reach private networks' : 'Integrations are limited to public addresses');

        return redirect()->to('/app/admin/webhooks');
    }

    public function toggle(int $id)
    {
        $ep = $this->db->table('webhook_endpoints')->where('id', $id)->get()->getRowArray();
        if ($ep) {
            $on = ! (int) $ep['active'];
            $this->db->table('webhook_endpoints')->where('id', $id)->update(['active' => (int) $on, 'failures' => 0]);
            Audit::log('admin.webhook_toggle', $ep['name'] . ' → ' . ($on ? 'on' : 'off'));
            $this->toast($ep['name'] . ($on ? ' enabled' : ' disabled'), $on ? 'ok' : 'warn');
        }

        return redirect()->to('/app/admin/webhooks');
    }

    public function delete(int $id)
    {
        $ep = $this->db->table('webhook_endpoints')->where('id', $id)->get()->getRowArray();
        if ($ep) {
            $this->db->table('webhook_deliveries')->where('endpoint_id', $id)->delete();
            $this->db->table('webhook_endpoints')->where('id', $id)->delete();
            Audit::log('admin.webhook_deleted', $ep['name']);
            $this->toast($ep['name'] . ' deleted', 'bad');
        }

        return redirect()->to('/app/admin/webhooks');
    }

    /** Send a webhook.test event to this endpoint only (regardless of its subscriptions). */
    public function test(int $id)
    {
        $ep = $this->db->table('webhook_endpoints')->where('id', $id)->get()->getRowArray();
        if (! $ep) {
            return redirect()->to('/app/admin/webhooks');
        }
        $now = date('Y-m-d H:i:s');
        $this->db->table('webhook_deliveries')->insert([
            'endpoint_id' => $id, 'event' => 'webhook.test',
            'payload' => json_encode([
                'event' => 'webhook.test', 'occurred_at' => date('c'),
                'data' => ['message' => 'Test event from TicketHub', 'sent_by' => $this->me['name'], 'endpoint' => $ep['name']],
            ], JSON_UNESCAPED_SLASHES),
            'attempts' => 0, 'next_attempt_at' => $now, 'created_at' => $now,
        ]);
        $ok = Webhooks::retryNow((int) $this->db->insertID());
        $fresh = $this->db->table('webhook_endpoints')->where('id', $id)->get()->getRowArray();
        $this->toast(
            $ok ? 'Test delivered (HTTP ' . $fresh['last_status'] . ')' : 'Test failed' . ($fresh['last_status'] ? ' (HTTP ' . $fresh['last_status'] . ')' : ' — see the delivery log'),
            $ok ? 'ok' : 'bad'
        );

        return redirect()->to('/app/admin/webhooks');
    }

    public function retry(int $deliveryId)
    {
        $ok = Webhooks::retryNow($deliveryId);
        $this->toast($ok ? 'Delivered' : 'Still failing — check the response excerpt', $ok ? 'ok' : 'bad');

        return redirect()->to('/app/admin/webhooks');
    }
}
