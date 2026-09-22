<?php

declare(strict_types=1);

namespace Tests\Feature;

use CodeIgniter\Test\TestResponse;
use Tests\Support\FeatureTestCase;

/**
 * Bearer-token JSON API (/api/tickets).
 *
 * @internal
 */
final class ApiTest extends FeatureTestCase
{
    private string $token = '';
    private int $tokenId  = 0;
    private int $userId   = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // A token for the seeded administrator; only the sha256 is stored.
        $this->userId  = (int) $this->userByEmail('maya.ortiz@tickethub.co')['id'];
        $this->token   = bin2hex(random_bytes(32));
        $this->db->table('api_tokens')->insert([
            'user_id'    => $this->userId,
            'name'       => 'phpunit',
            'token_hash' => hash('sha256', $this->token),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $this->tokenId = (int) $this->db->insertID();
    }

    private function auth(?string $token = null, array $extra = []): self
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . ($token ?? $this->token)] + $extra);
    }

    private function json(TestResponse $result): array
    {
        $decoded = json_decode($result->response()->getBody(), true);
        $this->assertIsArray($decoded, 'Response is not JSON: ' . $result->response()->getBody());

        return $decoded;
    }

    private function assertNoSessionCookie(TestResponse $result): void
    {
        $name = config('Session')->cookieName;
        $this->assertFalse($result->response()->hasCookie($name), 'API response must not set the ' . $name . ' cookie');
        $this->assertStringNotContainsString($name . '=', $result->response()->getHeaderLine('Set-Cookie'));
    }

    public function testMissingTokenIsUnauthorized(): void
    {
        $result = $this->get('api/tickets');

        $result->assertStatus(401);
        $this->assertSame('Missing bearer token', $this->json($result)['error']);
        $this->assertNoSessionCookie($result);
    }

    public function testGarbageTokenIsUnauthorized(): void
    {
        $result = $this->auth('not-a-real-token')->get('api/tickets');

        $result->assertStatus(401);
        $this->assertNoSessionCookie($result);
    }

    public function testValidTokenListsTickets(): void
    {
        $result = $this->auth()->get('api/tickets', ['per_page' => 100]);

        $result->assertStatus(200);
        $body = $this->json($result);
        $this->assertArrayHasKey('data', $body);
        $this->assertArrayHasKey('meta', $body);
        $this->assertNotEmpty($body['data']);
        $this->assertContains('SR-4379', array_column($body['data'], 'code'));
        $this->assertSame($body['meta']['total'], count($body['data']));
        $this->assertNoSessionCookie($result);

        // Using the token stamps it; the admin list relies on this.
        $row = $this->db->table('api_tokens')->where('id', $this->tokenId)->get()->getRowArray();
        $this->assertNotNull($row['last_used_at']);
    }

    public function testShowReturnsTheTicketWithMessages(): void
    {
        $result = $this->auth()->get('api/tickets/SR-4379');

        $result->assertStatus(200);
        $body = $this->json($result);
        $this->assertSame('SR-4379', $body['code']);
        $this->assertArrayHasKey('messages', $body);
        $this->assertArrayHasKey('sla', $body);
    }

    public function testUnknownTicketIs404(): void
    {
        $this->auth()->get('api/tickets/INC-0000')->assertStatus(404);
    }

    public function testMalformedJsonIsBadRequest(): void
    {
        $result = $this->auth(null, ['Content-Type' => 'application/json'])
            ->withBody('{"subject": "unterminated"')
            ->post('api/tickets');

        $result->assertStatus(400);
        $this->assertSame('Invalid JSON body', $this->json($result)['error']);
    }

    public function testMissingFieldsAreUnprocessable(): void
    {
        $result = $this->auth()->withBodyFormat('json')->post('api/tickets', ['subject' => 'No body']);

        $result->assertStatus(422);
    }

    public function testCreateReturns201AndTheTicketAppearsInTheList(): void
    {
        $subject = 'API smoke test ' . bin2hex(random_bytes(4));

        $created = $this->auth()->withBodyFormat('json')->post('api/tickets', [
            'subject'  => $subject,
            'body'     => 'Raised by the PHPUnit feature suite.',
            'priority' => 'High',
            'type'     => 'Incident',
        ]);

        $created->assertStatus(201);
        $body = $this->json($created);
        $this->assertNotEmpty($body['code']);
        $this->assertSame($subject, $body['subject']);
        $this->assertSame('High', $body['priority']);
        $this->assertSame('API', $body['source']);
        $this->assertNoSessionCookie($created);

        // Requester defaults to the token's owner.
        $row = $this->db->table('tickets')->where('code', $body['code'])->get()->getRowArray();
        $this->assertSame($this->userId, (int) $row['requester_id']);

        $list = $this->auth()->get('api/tickets', ['per_page' => 100]);
        $this->assertContains($body['code'], array_column($this->json($list)['data'], 'code'));

        $this->assertSame(1, $this->db->table('audit_log')->where('action', 'api.ticket_created')->countAllResults());
    }

    public function testReplyAddsAMessage(): void
    {
        $ticket = $this->db->table('tickets')->where('code', 'SR-4379')->get()->getRowArray();
        $before = $this->db->table('ticket_messages')->where('ticket_id', $ticket['id'])->countAllResults();

        $result = $this->auth()->withBodyFormat('json')
            ->post('api/tickets/SR-4379/reply', ['body' => 'Reply from the API', 'kind' => 'note']);

        $result->assertStatus(201);
        $this->assertSame($before + 1, $this->db->table('ticket_messages')->where('ticket_id', $ticket['id'])->countAllResults());
    }

    public function testRevokedTokenIsUnauthorized(): void
    {
        $this->auth()->get('api/tickets')->assertStatus(200);

        $this->db->table('api_tokens')->where('id', $this->tokenId)->update(['revoked_at' => date('Y-m-d H:i:s')]);

        $result = $this->auth()->get('api/tickets');
        $result->assertStatus(401);
        $this->assertSame('Invalid or revoked token', $this->json($result)['error']);
        $this->assertNoSessionCookie($result);
    }

    public function testExpiredTokenIsUnauthorized(): void
    {
        $this->db->table('api_tokens')->where('id', $this->tokenId)
            ->update(['expires_at' => date('Y-m-d H:i:s', time() - 60)]);

        $result = $this->auth()->get('api/tickets');
        $result->assertStatus(401);
        $this->assertSame('Token has expired', $this->json($result)['error']);
    }

    public function testTokenForDeactivatedUserIsForbidden(): void
    {
        $this->db->table('users')->where('id', $this->userId)->update(['active' => 0]);

        $this->auth()->get('api/tickets')->assertStatus(403);
    }

    public function testApiIsExemptFromCsrf(): void
    {
        // A POST with no CSRF token must reach the controller (422, not 403).
        $this->auth()->withBodyFormat('json')->post('api/tickets', [])->assertStatus(422);
    }
}
