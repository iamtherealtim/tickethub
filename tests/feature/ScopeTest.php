<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\FeatureTestCase;

/**
 * Ticket visibility: agents are limited to their group (or tickets assigned to
 * them), administrators see everything, requesters only see their own — in the
 * workspace, the portal and portal replies alike.
 *
 * Seeded fixtures used here (see TicketHubSeeder):
 *   Maya Ortiz    id 1, Administrator, group 1
 *   Devin Park    id 2, Agent, group 1 (Service Desk)
 *   Priya Raman   id 3, Agent, group 2 (Network & Infra)
 *   Jordan        id 7, Requester
 *   Camille       id 8, Requester
 *   SR-4379       group 1, assigned to Devin, requested by Jordan
 *
 * @internal
 */
final class ScopeTest extends FeatureTestCase
{
    private const GROUP1_TICKET = 'SR-4379';

    private function ticket(string $code): array
    {
        $t = $this->db->table('tickets')->where('code', $code)->get()->getRowArray();
        $this->assertNotNull($t, 'Seeded ticket ' . $code . ' missing');

        return $t;
    }

    public function testFixtureAssumptionsHold(): void
    {
        $t = $this->ticket(self::GROUP1_TICKET);
        $this->assertSame(1, (int) $t['group_id']);
        $this->assertSame(7, (int) $t['requester_id']);

        $this->assertSame(2, (int) $this->userByEmail('priya.raman@tickethub.co')['group_id']);
        $this->assertSame('Administrator', $this->userByEmail('maya.ortiz@tickethub.co')['role']);
        $this->assertSame('Requester', $this->userByEmail('camille.roy@tickethub.co')['role']);
    }

    public function testAgentInAnotherGroupCannotOpenTheTicket(): void
    {
        $priya = $this->userByEmail('priya.raman@tickethub.co');

        $result = $this->withSession($this->sessionFor((int) $priya['id']))
            ->get('app/tickets/' . self::GROUP1_TICKET);

        $result->assertRedirectTo('/app/tickets');
    }

    public function testAgentInTheTicketsGroupCanOpenIt(): void
    {
        $devin = $this->userByEmail('devin.park@tickethub.co');

        $this->withSession($this->sessionFor((int) $devin['id']))
            ->get('app/tickets/' . self::GROUP1_TICKET)
            ->assertOK();
    }

    public function testAdministratorCanOpenAnyTicket(): void
    {
        $maya = $this->userByEmail('maya.ortiz@tickethub.co');

        $this->withSession($this->sessionFor((int) $maya['id']))
            ->get('app/tickets/' . self::GROUP1_TICKET)
            ->assertOK();
    }

    public function testRequesterCannotOpenAnotherRequestersTicket(): void
    {
        $camille = $this->userByEmail('camille.roy@tickethub.co');

        $this->withSession($this->sessionFor((int) $camille['id']))
            ->get('portal/tickets/' . self::GROUP1_TICKET)
            ->assertRedirectTo('/portal/tickets');
    }

    public function testRequesterCanOpenTheirOwnTicket(): void
    {
        $jordan = $this->userByEmail('jordan.whitfield@tickethub.co');

        $this->withSession($this->sessionFor((int) $jordan['id']))
            ->get('portal/tickets/' . self::GROUP1_TICKET)
            ->assertOK();
    }

    public function testRequesterCannotUseTheWorkspace(): void
    {
        $jordan = $this->userByEmail('jordan.whitfield@tickethub.co');

        $this->withSession($this->sessionFor((int) $jordan['id']))
            ->get('app/tickets/' . self::GROUP1_TICKET)
            ->assertRedirectTo('/portal');
    }

    public function testPortalReplyToAForeignTicketIsRejected(): void
    {
        $camille = $this->userByEmail('camille.roy@tickethub.co');
        $ticket  = $this->ticket(self::GROUP1_TICKET);
        $before  = $this->db->table('ticket_messages')->where('ticket_id', $ticket['id'])->countAllResults();

        $result = $this->postForm(
            'portal/tickets/' . self::GROUP1_TICKET . '/reply',
            ['body' => 'I should not be able to post this'],
            $this->sessionFor((int) $camille['id']),
        );

        $result->assertRedirectTo('/portal/tickets');
        $this->assertSame($before, $this->db->table('ticket_messages')->where('ticket_id', $ticket['id'])->countAllResults());
        $this->assertSame(0, $this->db->table('ticket_messages')->where('user_id', $camille['id'])->where('ticket_id', $ticket['id'])->countAllResults());
    }

    public function testPortalReplyToOwnTicketIsStored(): void
    {
        $jordan = $this->userByEmail('jordan.whitfield@tickethub.co');
        $ticket = $this->ticket(self::GROUP1_TICKET);
        $before = $this->db->table('ticket_messages')->where('ticket_id', $ticket['id'])->countAllResults();

        $result = $this->postForm(
            'portal/tickets/' . self::GROUP1_TICKET . '/reply',
            ['body' => 'Thanks, that worked.'],
            $this->sessionFor((int) $jordan['id']),
        );

        $result->assertRedirect();
        $this->assertSame($before + 1, $this->db->table('ticket_messages')->where('ticket_id', $ticket['id'])->countAllResults());
    }
}
