# Ripples PHP SDK

Server-side PHP SDK for [Ripples Metrics](https://ripples.sh) analytics.

## Install

```bash
composer require ripples/ripples-php
```

Add to your `.env`:

```
RIPPLES_SECRET_KEY=priv_your_secret_key
```

## Usage

```php
use Ripples\Ripples;

$ripples = new Ripples();

$ripples->revenue(49.99, 'user_123');
$ripples->signup('user_123', ['email' => 'jane@example.com']);
$ripples->track('created a budget', 'user_123', ['area' => 'budgets']);
$ripples->identify('user_123', [
    'email' => 'jane@example.com',
    'signed_up_at' => $user->created_at->toIso8601String(),
]);
```

That's it.

## Track product usage

Call `track()` **only** for significant product usage — actions that prove a user got real value (created a budget, sent a message, invited a teammate). This is not a generic event log like PostHog or Mixpanel: do **not** send pageviews, banner impressions, button clicks, or "viewed X" events. Every `track()` call feeds the Activation dashboard, so noise here pollutes your funnel. Ripples auto-detects activation (first occurrence per user), computes adoption rates, and correlates with retention and payment.

```php
$ripples->track('created a budget', 'user_123', ['area' => 'budgets']);
$ripples->track('shared a list', 'user_123', ['area' => 'sharing', 'via' => 'link']);
$ripples->track('exported report', 'user_123', ['area' => 'reports', 'format' => 'csv']);
```

Use `area` to group actions into product areas. Use `activated => true` to mark the specific moment a user activates — it flags this occurrence, not the event type:

```php
// User added their 10th transaction — we consider this their activation moment
$ripples->track('added transaction', 'user_123', [
    'area'      => 'transactions',
    'activated' => true,  // only on THIS occurrence
]);
```

## Track subscriptions (MRR)

Call `subscription()` when a subscription is created, upgraded, downgraded, or canceled. This powers the MRR metric on your dashboard.

> **Stripe / Paddle users:** MRR is tracked automatically via the integration. Only use this method if you use a payment provider without a native Ripples integration.

```php
// User subscribes to Pro Monthly ($29/mo)
$ripples->subscription('sub_123', 'user_456', 'active', 29.00, 'month', [
    'name' => 'Pro',
    'currency' => 'EUR',
]);

// User upgrades to Business Annual ($499/yr)
$ripples->subscription('sub_123', 'user_456', 'active', 499.00, 'year', [
    'name' => 'Business',
]);

// User cancels
$ripples->subscription('sub_123', 'user_456', 'canceled', 0);
```

Parameters:

- `subscriptionId` (string, required) — stable identifier for the subscription
- `userId` (string, required) — your internal user ID
- `status` (string, required) — one of: `active`, `canceled`, `past_due`, `trialing`, `paused`
- `amount` (float, required) — amount per billing cycle (e.g. `29.00`), pass `0` when canceling
- `interval` (string, optional) — `month` (default), `year`, `week`, or `day`
- `attributes` (array, optional) — `currency`, `name` or `plan`, `interval_count`

## Track revenue

```php
$ripples->revenue(49.99, 'user_123');
```

Any key you pass that isn't a known field becomes a custom property automatically:

```php
$ripples->revenue(49.99, 'user_123', [
    'email'          => 'jane@example.com',
    'currency'       => 'EUR',
    'transaction_id' => 'txn_abc123',
    'name'           => 'Pro Plan',
    'plan'           => 'annual',       // custom property
    'coupon'         => 'WELCOME20',    // custom property
]);
```

Refunds are just negative revenue:

```php
$ripples->revenue(-29.99, 'user_123', ['transaction_id' => 'txn_abc123']);
```

## Track signups

```php
$ripples->signup('user_123', [
    'email'    => 'jane@example.com',
    'name'     => 'Jane Smith',
    'referral' => 'twitter',    // custom property
    'plan'     => 'free',       // custom property
]);
```

## Identify users

Update user traits at any time:

```php
$ripples->identify('user_123', [
    'email'        => 'jane@example.com',
    'name'         => 'Jane Smith',
    'signed_up_at' => $user->created_at->toIso8601String(),
    'company'      => 'Acme Inc',   // custom property
    'role'         => 'admin',      // custom property
]);
```

**Always pass `signed_up_at` from server-side calls.** `identify()` can be the
first time Ripples ever hears about a user — a cron job, a login listener, a
user who never went through a client-side signup. When that happens, Ripples
treats the call as a brand-new signup; without `signed_up_at` it dates that
signup "now" instead of the user's real account age, which corrupts your
signups chart and retention cohorts. It only ever moves the stored signup date
earlier, never later, so there's no downside to sending it on every call.

## Track companies (B2B)

Say which company a user works in, at signup and whenever they switch or the company's traits change. Their later events count for it with nothing passed per call:

```php
$ripples->group($user->id, $team->id, ['name' => $team->name, 'plan' => 'business']);

$ripples->track('created a report', $user->id);   // counts for $team
```

Use your own id for the company, never its name. Docs: https://ripples.sh/docs/companies

## Backfill historical events

Pass a `DateTimeInterface` as the last argument to any tracking method to override the event's timestamp — useful when importing from a CSV, replaying from another analytics tool, or catching up after an outage.

```php
foreach ($csvRows as $row) {
    $ripples->track(
        $row['action'],
        $row['user_id'],
        ['area' => $row['area']],
        new DateTimeImmutable($row['occurred_at']), // any tz — converted to UTC
    );
}
$ripples->flush(); // guarantee delivery at end of script
```

Naive behavior (no timestamp passed) uses "now" in UTC. Non-UTC timezones are converted automatically.

## Error handling

```php
use Ripples\RipplesException;

try {
    $ripples->revenue(49.99, 'user_123');
} catch (RipplesException $e) {
    // handle error
}
```

## Configuration

The SDK reads `RIPPLES_SECRET_KEY` from your environment automatically. You can override everything:

```php
$ripples = new Ripples('priv_explicit_key', [
    'base_url' => 'https://your-domain.com/api', // self-hosted
    'timeout'  => 10, // seconds (default: 5)
]);
```

Self-hosted URL can also be set via env:

```
RIPPLES_URL=https://your-domain.com/api
```

## Custom HTTP client

Extend the class and override `post()` to use Guzzle, Symfony HTTP, or anything else:

```php
class MyRipples extends \Ripples\Ripples
{
    protected function post(string $path, array $data): array
    {
        // your custom implementation
    }
}
```

## Requirements

- PHP 8.1+
- ext-curl
- ext-json

## License

MIT
