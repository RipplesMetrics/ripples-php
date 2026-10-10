<?php

namespace Ripples\Tests;

use PHPUnit\Framework\TestCase;
use Ripples\Ripples;
use Ripples\RipplesException;

/**
 * A testable Ripples client that captures batch requests instead of sending them.
 */
class FakeRipples extends Ripples
{
    /** @var list<array{path: string, data: array}> */
    public array $batches = [];
    public bool $shouldThrow = false;
    public ?\Throwable $throwable = null;

    /** Stands in for the tracker cookie — PHP_SAPI is 'cli' under phpunit. */
    public ?string $cookie = null;

    protected function post(string $path, array $data): void
    {
        if ($this->shouldThrow) {
            throw $this->throwable ?? new RipplesException('Simulated failure');
        }
        $this->batches[] = compact('path', 'data');
    }

    protected function visitorIdFromCookie(): ?string
    {
        return $this->cookie;
    }
}

class RipplesTest extends TestCase
{
    private FakeRipples $ripples;

    protected function setUp(): void
    {
        $this->ripples = new FakeRipples('priv_test_key');
    }

    /** Events from the most recent flush. */
    private function lastEvents(): array
    {
        $last = end($this->ripples->batches);
        return $last ? $last['data']['events'] : [];
    }

    // ------------------------------------------------------------------
    // Constructor
    // ------------------------------------------------------------------

    public function testConstructorWithExplicitKey(): void
    {
        $r = new FakeRipples('priv_abc');
        $this->assertInstanceOf(Ripples::class, $r);
    }

    public function testConstructorFromEnv(): void
    {
        putenv('RIPPLES_SECRET_KEY=priv_from_env');
        $r = new FakeRipples();
        $this->assertInstanceOf(Ripples::class, $r);
        putenv('RIPPLES_SECRET_KEY'); // cleanup
    }

    public function testConstructorThrowsWithoutKey(): void
    {
        putenv('RIPPLES_SECRET_KEY');
        unset($_ENV['RIPPLES_SECRET_KEY'], $_SERVER['RIPPLES_SECRET_KEY']);

        $this->expectException(RipplesException::class);
        $this->expectExceptionMessage('Missing secret key');
        new FakeRipples();
    }

    public function testCustomBaseUrl(): void
    {
        $r = new FakeRipples('priv_abc', ['base_url' => 'https://custom.example.com/api']);
        $r->signup('u1');
        $r->flush();
        $this->assertSame('/v1/ingest/batch', $r->batches[0]['path']);
    }

    // ------------------------------------------------------------------
    // Revenue
    // ------------------------------------------------------------------

    public function testRevenueMinimal(): void
    {
        $this->ripples->revenue(49.99, 'u1');
        $this->ripples->flush();

        $event = $this->lastEvents()[0];
        $this->assertSame('revenue', $event['$type']);
        $this->assertSame(49.99, $event['$amount']);
        $this->assertSame('u1', $event['$user_id']);
    }

    public function testRevenueFlatProperties(): void
    {
        $this->ripples->revenue(100.0, 'u1', [
            'currency' => 'EUR',
            'plan'     => 'annual',
            'coupon'   => 'WELCOME',
        ]);
        $this->ripples->flush();

        $event = $this->lastEvents()[0];
        $this->assertSame(100.0, $event['$amount']);
        $this->assertSame('u1', $event['$user_id']);
        $this->assertSame('EUR', $event['currency']);
        $this->assertSame('annual', $event['plan']);
        $this->assertSame('WELCOME', $event['coupon']);
    }

    public function testRefund(): void
    {
        $this->ripples->revenue(-29.99, 'u1');
        $this->ripples->flush();
        $this->assertSame(-29.99, $this->lastEvents()[0]['$amount']);
    }

    // ------------------------------------------------------------------
    // Signup
    // ------------------------------------------------------------------

    public function testSignupMinimal(): void
    {
        $this->ripples->signup('user_42');
        $this->ripples->flush();

        $event = $this->lastEvents()[0];
        $this->assertSame('signup', $event['$type']);
        $this->assertSame('user_42', $event['$user_id']);
    }

    public function testSignupWithAttributes(): void
    {
        $this->ripples->signup('user_42', [
            'email'    => 'jane@example.com',
            'name'     => 'Jane',
            'referral' => 'twitter',
        ]);
        $this->ripples->flush();

        $event = $this->lastEvents()[0];
        $this->assertSame('user_42', $event['$user_id']);
        $this->assertSame('jane@example.com', $event['email']);
        $this->assertSame('Jane', $event['name']);
        $this->assertSame('twitter', $event['referral']);
    }

    // ------------------------------------------------------------------
    // Identify
    // ------------------------------------------------------------------

    public function testIdentifyMinimal(): void
    {
        $this->ripples->identify('user_42');
        $this->ripples->flush();

        $event = $this->lastEvents()[0];
        $this->assertSame('identify', $event['$type']);
        $this->assertSame('user_42', $event['$user_id']);
    }

    public function testIdentifyWithAttributes(): void
    {
        $this->ripples->identify('user_42', [
            'email'   => 'jane@example.com',
            'company' => 'Acme',
            'role'    => 'admin',
        ]);
        $this->ripples->flush();

        $event = $this->lastEvents()[0];
        $this->assertSame('user_42', $event['$user_id']);
        $this->assertSame('jane@example.com', $event['email']);
        $this->assertSame('Acme', $event['company']);
        $this->assertSame('admin', $event['role']);
    }

    // ------------------------------------------------------------------
    // Track (product usage)
    // ------------------------------------------------------------------

    public function testTrackMinimal(): void
    {
        $this->ripples->track('created a budget', 'user_42');
        $this->ripples->flush();

        $event = $this->lastEvents()[0];
        $this->assertSame('track', $event['$type']);
        $this->assertSame('created a budget', $event['$name']);
        $this->assertSame('user_42', $event['$user_id']);
    }

    public function testTrackWithArea(): void
    {
        $this->ripples->track('created a budget', 'user_42', [
            'area' => 'budgets',
        ]);
        $this->ripples->flush();

        $event = $this->lastEvents()[0];
        $this->assertSame('track', $event['$type']);
        $this->assertSame('created a budget', $event['$name']);
        $this->assertSame('budgets', $event['$area']);
    }

    public function testTrackWithActivatedFlag(): void
    {
        $this->ripples->track('shared a list', 'user_42', [
            'area'      => 'sharing',
            'activated' => true,
            'via'       => 'link',
        ]);
        $this->ripples->flush();

        $event = $this->lastEvents()[0];
        $this->assertSame('track', $event['$type']);
        $this->assertSame('shared a list', $event['$name']);
        $this->assertSame('sharing', $event['$area']);
        $this->assertTrue($event['$activated']);
        $this->assertSame('link', $event['via']);
    }

    // ------------------------------------------------------------------
    // Batching
    // ------------------------------------------------------------------

    public function testMultipleEventsAreCollectedInOneBatch(): void
    {
        $this->ripples->signup('u1');
        $this->ripples->identify('u1', ['email' => 'a@b.com']);
        $this->ripples->revenue(9.99, 'u1');
        $this->ripples->flush();

        $this->assertCount(1, $this->ripples->batches); // one HTTP call
        $events = $this->lastEvents();
        $this->assertCount(3, $events);
        $this->assertSame('signup', $events[0]['$type']);
        $this->assertSame('identify', $events[1]['$type']);
        $this->assertSame('revenue', $events[2]['$type']);
    }

    public function testFlushSendsToBatchEndpoint(): void
    {
        $this->ripples->signup('u1');
        $this->ripples->flush();
        $this->assertSame('/v1/ingest/batch', $this->ripples->batches[0]['path']);
    }

    public function testFlushClearsTheQueue(): void
    {
        $this->ripples->signup('u1');
        $this->ripples->flush();
        $this->ripples->flush(); // second flush — queue is empty, no HTTP call

        $this->assertCount(1, $this->ripples->batches);
    }

    public function testFlushOnEmptyQueueDoesNothing(): void
    {
        $this->ripples->flush();
        $this->assertCount(0, $this->ripples->batches);
    }

    public function testMaxQueueSizeTriggersAutoFlush(): void
    {
        $r = new FakeRipples('priv_test_key', ['max_queue_size' => 3]);

        $r->signup('u1');
        $r->signup('u2');
        $this->assertCount(0, $r->batches); // not yet

        $r->signup('u3'); // hits limit — auto-flush
        $this->assertCount(1, $r->batches);
        $this->assertCount(3, $r->batches[0]['data']['events']);
    }

    // ------------------------------------------------------------------
    // Resilience
    // ------------------------------------------------------------------

    public function testNetworkErrorDoesNotThrow(): void
    {
        $this->ripples->shouldThrow = true;

        $this->ripples->signup('u1');
        $this->ripples->identify('u1');
        $this->ripples->revenue(9.99, 'u1');
        $this->ripples->flush();

        $this->assertTrue(true); // reached without exception
    }

    public function testOnErrorCallbackIsInvokedWithException(): void
    {
        $caught = null;

        $r = new FakeRipples('priv_test_key', [
            'on_error' => function (\Throwable $e) use (&$caught) {
                $caught = $e;
            },
        ]);
        $r->shouldThrow = true;
        $r->throwable   = new RipplesException('Simulated timeout');

        $r->signup('u1');
        $r->flush();

        $this->assertInstanceOf(RipplesException::class, $caught);
        $this->assertSame('Simulated timeout', $caught->getMessage());
    }

    public function testOnErrorIsNotCalledOnSuccess(): void
    {
        $called = false;

        $r = new FakeRipples('priv_test_key', [
            'on_error' => function () use (&$called) { $called = true; },
        ]);
        $r->signup('u1');
        $r->flush();

        $this->assertFalse($called);
    }

    // ------------------------------------------------------------------
    // Options
    // ------------------------------------------------------------------

    public function testCustomTimeoutOptions(): void
    {
        $r = new FakeRipples('priv_test_key', [
            'timeout'         => 5,
            'connect_timeout' => 3,
        ]);
        $r->signup('u1');
        $r->flush();
        $this->assertSame('signup', $r->batches[0]['data']['events'][0]['$type']);
    }

    // ------------------------------------------------------------------
    // Timestamp override (backfilling historical events)
    // ------------------------------------------------------------------

    public function testOmittedTimestampUsesNow(): void
    {
        $this->ripples->signup('u1');
        $this->ripples->flush();

        $event = $this->lastEvents()[0];
        // Format: 2026-04-19T12:34:56Z — UTC, second precision
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
            $event['$sent_at']
        );
        $now = time();
        $eventTs = strtotime($event['$sent_at']);
        $this->assertLessThan(5, abs($now - $eventTs));
    }

    public function testTrackWithDateTimeImmutable(): void
    {
        $past = new \DateTimeImmutable('2024-03-15T12:00:00Z');
        $this->ripples->track('created a budget', 'u1', [], $past);
        $this->ripples->flush();

        $this->assertSame('2024-03-15T12:00:00Z', $this->lastEvents()[0]['$sent_at']);
    }

    public function testTrackWithDateTimeInNonUtcZoneIsConvertedToUtc(): void
    {
        // 2024-06-01 09:00:00 in Europe/Berlin (UTC+2 DST) → 2024-06-01 07:00:00 UTC
        $berlin = new \DateTimeImmutable('2024-06-01 09:00:00', new \DateTimeZone('Europe/Berlin'));
        $this->ripples->revenue(29.99, 'u1', [], $berlin);
        $this->ripples->flush();

        $this->assertSame('2024-06-01T07:00:00Z', $this->lastEvents()[0]['$sent_at']);
    }

    public function testRevenueSignupIdentifyAllAcceptTimestamp(): void
    {
        $t = new \DateTimeImmutable('2023-01-01T00:00:00Z');
        $this->ripples->revenue(10.0, 'u1', [], $t);
        $this->ripples->signup('u1', [], $t);
        $this->ripples->identify('u1', [], $t);
        $this->ripples->flush();

        $events = $this->lastEvents();
        $this->assertSame('2023-01-01T00:00:00Z', $events[0]['$sent_at']);
        $this->assertSame('2023-01-01T00:00:00Z', $events[1]['$sent_at']);
        $this->assertSame('2023-01-01T00:00:00Z', $events[2]['$sent_at']);
    }

    public function testSubscriptionAcceptsTimestamp(): void
    {
        $t = new \DateTimeImmutable('2024-02-14T15:30:00Z');
        $this->ripples->subscription('sub_1', 'u1', 'active', 29.0, 'month', [], $t);
        $this->ripples->flush();

        $this->assertSame('2024-02-14T15:30:00Z', $this->lastEvents()[0]['$sent_at']);
    }

    public function testSubscriptionSendsStartAndCancelDates(): void
    {
        $this->ripples->subscription('sub_1', 'u1', 'canceled', 29.0, 'month', [
            'started_at' => new \DateTimeImmutable('2021-01-05 00:00:00', new \DateTimeZone('UTC')),
            'canceled_at' => '2023-05-10T00:00:00Z',
        ]);
        $this->ripples->flush();

        $event = $this->lastEvents()[0];
        $this->assertSame('2021-01-05T00:00:00Z', $event['subscription_started_at']);
        $this->assertSame('2023-05-10T00:00:00Z', $event['subscription_canceled_at']);
    }

    public function testSubscriptionOmitsDatesWhenAbsent(): void
    {
        $this->ripples->subscription('sub_1', 'u1', 'active', 29.0);
        $this->ripples->flush();

        $event = $this->lastEvents()[0];
        $this->assertArrayNotHasKey('subscription_started_at', $event);
        $this->assertArrayNotHasKey('subscription_canceled_at', $event);
    }

    public function testSubscriptionSendsRenewalWithFalseKept(): void
    {
        $this->ripples->subscription('sub_1', 'u1', 'trialing', 9.99, 'month', [
            'renews' => false,
            'period_ends_at' => new \DateTimeImmutable('2026-10-14 08:10:00', new \DateTimeZone('UTC')),
        ]);
        $this->ripples->flush();

        $event = $this->lastEvents()[0];
        $this->assertFalse($event['subscription_renews']);
        $this->assertSame('2026-10-14T08:10:00Z', $event['subscription_period_ends_at']);
    }

    public function testSubscriptionOmitsRenewalWhenAbsent(): void
    {
        $this->ripples->subscription('sub_1', 'u1', 'active', 29.0);
        $this->ripples->flush();

        $event = $this->lastEvents()[0];
        $this->assertArrayNotHasKey('subscription_renews', $event);
        $this->assertArrayNotHasKey('subscription_period_ends_at', $event);
    }

    // ------------------------------------------------------------------
    // Visitor id
    // ------------------------------------------------------------------

    private const VID = '3f2504e0-4f89-41d3-9a0c-0305e82c3301';

    /** No cookie, no pinned id: the key is absent so the API assigns one. */
    public function testVisitorIdIsOmittedWhenNoCookieIsPresent(): void
    {
        $this->ripples->signup('u1');
        $this->ripples->flush();

        $this->assertArrayNotHasKey('$visitor_id', $this->lastEvents()[0]);
    }

    public function testCookieVisitorIdIsAttachedToEveryEventType(): void
    {
        $this->ripples->cookie = self::VID;
        $this->ripples->signup('u1');
        $this->ripples->identify('u1');
        $this->ripples->track('did a thing', 'u1');
        $this->ripples->revenue(9.99, 'u1');
        $this->ripples->flush();

        foreach ($this->lastEvents() as $event) {
            $this->assertSame(self::VID, $event['$visitor_id']);
        }
    }

    public function testPinnedVisitorIdBeatsCookieAndExplicitBeatsBoth(): void
    {
        $explicit = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
        $pinned   = '11111111-2222-3333-4444-555555555555';

        $this->ripples->cookie = self::VID;
        $this->ripples->setVisitorId($pinned);
        $this->ripples->signup('u1');
        $this->ripples->signup('u2', ['visitor_id' => $explicit]);
        $this->ripples->flush();

        $this->assertSame($pinned, $this->lastEvents()[0]['$visitor_id']);
        $this->assertSame($explicit, $this->lastEvents()[1]['$visitor_id']);
    }

    public function testSetVisitorIdNullFallsBackToCookie(): void
    {
        $this->ripples->cookie = self::VID;
        $this->ripples->setVisitorId('11111111-2222-3333-4444-555555555555');
        $this->ripples->setVisitorId(null);
        $this->ripples->signup('u1');
        $this->ripples->flush();

        $this->assertSame(self::VID, $this->lastEvents()[0]['$visitor_id']);
    }

    /**
     * A hand-edited cookie must not travel: `visitor_id` is a UUID column at the
     * other end and a bad value would fail the insert for the whole batch.
     */
    public function testMalformedVisitorIdIsDroppedRatherThanForwarded(): void
    {
        foreach (['', 'not-a-uuid', '../../etc/passwd', self::VID . 'x'] as $bad) {
            $r = new FakeRipples('priv_test_key');
            $r->cookie = $bad;
            $r->signup('u1');
            $r->flush();

            $this->assertArrayNotHasKey('$visitor_id', $r->batches[0]['data']['events'][0], "cookie: {$bad}");
        }
    }

    public function testVisitorIdIsLowercasedAndTrimmed(): void
    {
        $this->ripples->cookie = '  ' . strtoupper(self::VID) . '  ';
        $this->ripples->signup('u1');
        $this->ripples->flush();

        $this->assertSame(self::VID, $this->lastEvents()[0]['$visitor_id']);
    }

    /** The raw `visitor_id` key must never survive as a custom property. */
    public function testExplicitVisitorIdIsNotAlsoSentAsACustomProperty(): void
    {
        $this->ripples->revenue(5.0, 'u1', ['visitor_id' => self::VID, 'plan' => 'pro']);
        $this->ripples->flush();

        $event = $this->lastEvents()[0];
        $this->assertSame(self::VID, $event['$visitor_id']);
        $this->assertArrayNotHasKey('visitor_id', $event);
        $this->assertSame('pro', $event['plan']);
    }

    // ------------------------------------------------------------------
    // Companies
    // ------------------------------------------------------------------

    public function testGroupSendsUserCompanyAndTraits(): void
    {
        $this->ripples->group('u1', 42, ['name' => 'Acme', 'seats' => 12]);
        $this->ripples->flush();

        $event = $this->lastEvents()[0];
        $this->assertSame('group', $event['$type']);
        $this->assertSame('u1', $event['$user_id']);
        $this->assertSame('42', $event['$company_id']);
        $this->assertSame('{"name":"Acme","seats":12}', json_encode($event['$traits']));
    }

    public function testGroupWithoutUserOnlyUpdatesTheCompany(): void
    {
        $this->ripples->group(null, 'acme');
        $this->ripples->flush();

        $event = $this->lastEvents()[0];
        $this->assertArrayNotHasKey('$user_id', $event);
        // {} not [] — the API reads traits as a map.
        $this->assertStringContainsString('"$traits":{}', json_encode($event));
    }

    public function testCompanyIdOnOtherCallsStaysACustomProperty(): void
    {
        // Membership is server-side state: track() needs no company, and a
        // company_id the app already sends keeps meaning what it meant.
        $this->ripples->track('created a report', 'u1', ['company_id' => 'acme']);
        $this->ripples->flush();

        $event = $this->lastEvents()[0];
        $this->assertSame('acme', $event['company_id']);
        $this->assertArrayNotHasKey('$company_id', $event);
    }

    public function testBackfillLoopAcrossAutoFlushBoundary(): void
    {
        $r = new FakeRipples('priv_test_key', ['max_queue_size' => 10]);
        for ($i = 0; $i < 25; $i++) {
            $ts = new \DateTimeImmutable("2024-01-01T00:00:00Z +{$i} days");
            $r->track('did a thing', "u{$i}", [], $ts);
        }
        $r->flush();

        // 25 events → three batches of 10, 10, 5 (last via explicit flush).
        $this->assertCount(3, $r->batches);
        $all = array_merge(
            $r->batches[0]['data']['events'],
            $r->batches[1]['data']['events'],
            $r->batches[2]['data']['events'],
        );
        $this->assertCount(25, $all);
        $this->assertSame('2024-01-01T00:00:00Z', $all[0]['$sent_at']);
        $this->assertSame('2024-01-25T00:00:00Z', $all[24]['$sent_at']);
    }
}
