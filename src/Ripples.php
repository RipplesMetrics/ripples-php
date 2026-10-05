<?php

namespace Ripples;

class Ripples
{
    private const SDK_NAME = 'php';

    /** First-party cookie the browser tracker writes on every pageview. */
    private const VISITOR_COOKIE = '_rpl_vid';

    protected string $secretKey;
    protected string $baseUrl;
    protected int $timeout;
    protected int $connectTimeout;
    /** @var callable|null */
    protected $onError;

    /** @var list<array<string, mixed>> */
    private array $queue = [];
    private int $maxQueueSize;
    private string $sdkVersion;
    private ?string $visitorId;

    public function __construct(?string $secretKey = null, array $options = [])
    {
        $this->secretKey      = $secretKey ?? getenv('RIPPLES_SECRET_KEY') ?: ($_ENV['RIPPLES_SECRET_KEY'] ?? $_SERVER['RIPPLES_SECRET_KEY'] ?? '');
        $this->baseUrl        = rtrim($options['base_url'] ?? getenv('RIPPLES_URL') ?: 'https://api.ripples.sh', '/');
        $this->timeout        = $options['timeout'] ?? 3;
        $this->connectTimeout = $options['connect_timeout'] ?? 2;
        $this->onError        = $options['on_error'] ?? null;
        $this->maxQueueSize   = $options['max_queue_size'] ?? 100;
        $this->visitorId      = self::normalizeVisitorId($options['visitor_id'] ?? null);

        if ($this->secretKey === '') {
            throw new RipplesException('Missing secret key. Set RIPPLES_SECRET_KEY in your .env or pass it to the constructor.');
        }

        $this->sdkVersion = $this->resolveSdkVersion();

        // In PHP-FPM the runtime calls fastcgi_finish_request() before running
        // shutdown functions, so the HTTP response has already been sent to the
        // client by the time the batch is delivered to Ripples — zero latency
        // impact on your users, guaranteed delivery on every request.
        register_shutdown_function([$this, 'flush']);
    }

    /**
     * Track revenue.
     *
     * At least one of user_id, email, or visitor_id is required.
     * Any extra keys become custom properties automatically.
     *
     * Pass $timestamp to backfill a historical event; omit for "now".
     */
    public function revenue(float $amount, string $userId, array $attributes = [], ?\DateTimeInterface $timestamp = null): void
    {
        $this->enqueue('revenue', [...$attributes, '$amount' => $amount, '$user_id' => $userId], $timestamp);
    }

    /**
     * Track a signup.
     *
     * Call this during the browser request that creates the account and the
     * tracker's visitor cookie is attached automatically, so the signup keeps
     * the acquisition channel of the session it came from. Called from a queue
     * worker or a webhook it still works — the API assigns a per-user id — but
     * the channel is then only recoverable once the browser identifies.
     *
     * Any extra keys beyond the known fields become custom properties automatically.
     *
     * Pass $timestamp to backfill a historical event; omit for "now".
     */
    public function signup(string $userId, array $attributes = [], ?\DateTimeInterface $timestamp = null): void
    {
        $this->enqueue('signup', [...$attributes, '$user_id' => $userId], $timestamp);
    }

    /**
     * Track significant product usage only — actions that prove a user got real value
     * (created a budget, sent a message, invited a teammate).
     *
     * This is NOT a generic event log like PostHog or Mixpanel. Do not send pageviews,
     * banner impressions, button clicks, or "viewed X" events. Every track() call feeds
     * the Activation dashboard — noise pollutes your funnel.
     *
     * Ripples auto-detects activation (first occurrence per user per action),
     * computes adoption rates, and correlates with retention/payment.
     *
     * Pass 'area' in attributes to group actions into product areas.
     * Pass 'activated' => true to mark this specific occurrence as the activation moment.
     * Pass $timestamp to backfill a historical event; omit for "now".
     */
    public function track(string $actionName, string $userId, array $attributes = [], ?\DateTimeInterface $timestamp = null): void
    {
        $sys = ['$name' => $actionName, '$user_id' => $userId];
        if (isset($attributes['area'])) {
            $sys['$area'] = $attributes['area'];
            unset($attributes['area']);
        }
        if (isset($attributes['activated'])) {
            $sys['$activated'] = $attributes['activated'];
            unset($attributes['activated']);
        }
        $this->enqueue('track', [...$attributes, ...$sys], $timestamp);
    }

    /**
     * Track a subscription state change for MRR calculation.
     *
     * Call when a subscription is created, upgraded/downgraded, or canceled.
     * For Stripe/Paddle users with a native integration, MRR is tracked automatically
     * — only use this method for other payment providers.
     *
     * @param string $subscriptionId  Your subscription ID (must be stable across updates)
     * @param string $userId          The user who owns the subscription
     * @param string $status          active, canceled, past_due, trialing, paused
     * @param float  $amount          Amount per billing cycle (e.g. 29.00), in your currency
     * @param string $interval        Billing interval: month, year, week, day
     * @param array  $attributes      Optional: currency, name/plan, interval_count
     * @param ?\DateTimeInterface $timestamp  Override event time for backfilling history
     */
    public function subscription(
        string $subscriptionId,
        string $userId,
        string $status,
        float $amount,
        string $interval = 'month',
        array $attributes = [],
        ?\DateTimeInterface $timestamp = null,
    ): void {
        $name = $attributes['name'] ?? $attributes['plan'] ?? null;
        $this->enqueue('revenue', array_filter([
            '$amount' => 0,
            '$user_id' => $userId,
            'subscription_id' => $subscriptionId,
            'subscription_status' => $status,
            'subscription_amount' => (string) round($amount * 100),
            'billing_interval' => $interval,
            'billing_interval_count' => (string) ($attributes['interval_count'] ?? 1),
            'currency' => $attributes['currency'] ?? null,
            '$name' => $name,
        ], fn ($v) => $v !== null), $timestamp);
    }

    /**
     * Identify a user (set or update traits).
     *
     * Any extra keys beyond the known fields become custom properties automatically.
     *
     * Pass $timestamp to backdate the identify event; omit for "now".
     */
    public function identify(string $userId, array $attributes = [], ?\DateTimeInterface $timestamp = null): void
    {
        $this->enqueue('identify', [...$attributes, '$user_id' => $userId], $timestamp);
    }

    /**
     * Say which company a user works in, and set or update its traits.
     *
     * Call it at signup, when the user joins or switches company, and whenever
     * the company's traits change (plan, seats). From then on the user's
     * events count for that company, including every later track() and
     * identify() from your server: nothing has to be passed on each call.
     * A user can belong to several companies; their events count for the one
     * they were most recently active in.
     *
     * Traits merge into what the company already has: a key you send again is
     * overwritten, a key you leave out is kept. Put a `name` in them, it is
     * what the dashboard shows instead of the id.
     *
     *     $ripples->group($user->id, $team->id, ['name' => $team->name, 'plan' => 'business']);
     *
     * @param string|null $userId    The user who works in it; null to only update the company's traits.
     * @param string|int  $companyId Your own id for the company, never its name (names repeat).
     */
    public function group(?string $userId, string|int $companyId, array $traits = [], ?\DateTimeInterface $timestamp = null): void
    {
        $data = ['$company_id' => (string) $companyId, '$traits' => (object) $traits];
        if ($userId !== null && $userId !== '') {
            $data['$user_id'] = $userId;
        }

        $this->enqueue('group', $data, $timestamp);
    }

    /**
     * Pin the browser visitor that this client's events belong to.
     *
     * Under PHP-FPM the visitor is picked up from the tracker's cookie with no
     * configuration at all, so you only need this where $_COOKIE is not the
     * current request's cookie jar: Octane, Swoole, RoadRunner, queue workers,
     * or when you keep the visitor id somewhere other than the cookie (a session,
     * a hidden form field posted from a cross-site frontend).
     *
     * Pass null to clear it and fall back to the cookie.
     */
    public function setVisitorId(?string $visitorId): void
    {
        $this->visitorId = self::normalizeVisitorId($visitorId);
    }

    /**
     * Send all queued events to the Ripples API as a single batch request.
     *
     * Called automatically on PHP shutdown (after response is sent in FPM).
     * Call explicitly in CLI scripts or before process exit when you need to
     * guarantee delivery.
     */
    public function flush(): void
    {
        if ($this->queue === []) {
            return;
        }

        $batch       = $this->queue;
        $this->queue = [];

        $this->send('/v1/ingest/batch', ['events' => $batch]);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function enqueue(string $type, array $data, ?\DateTimeInterface $timestamp = null): void
    {
        $sentAt = $timestamp !== null
            ? (new \DateTimeImmutable('@' . $timestamp->getTimestamp()))->format('Y-m-d\TH:i:s\Z')
            : gmdate('Y-m-d\TH:i:s\Z');

        // An explicit visitor_id on the call beats the pinned one, which beats
        // the tracker cookie.
        $explicit = $data['$visitor_id'] ?? $data['visitor_id'] ?? null;
        unset($data['visitor_id'], $data['$visitor_id']);

        $visitorId = self::normalizeVisitorId($explicit ?? $this->visitorId ?? $this->visitorIdFromCookie());

        $event = [
            ...$data,
            '$type'        => $type,
            '$sent_at'     => $sentAt,
            '$sdk_name'    => self::SDK_NAME,
            '$sdk_version' => $this->sdkVersion,
            '$platform'    => 'server',
        ];

        // Omit the key entirely when there is no visitor — the API then mints a
        // stable per-user id of its own, exactly as it did before this existed.
        // Sending '' instead would be read as a real id and break that fallback.
        if ($visitorId !== null) {
            $event['$visitor_id'] = $visitorId;
        }

        $this->queue[] = $event;

        if (\count($this->queue) >= $this->maxQueueSize) {
            $this->flush();
        }
    }

    /**
     * The browser tracker keeps its visitor id in a first-party, root-domain
     * cookie, so it rides along on every same-site request — including the one
     * that creates the account. Picking it up here is what ties a server-side
     * signup back to the browsing session that produced it; without it the event
     * lands on a synthetic per-user id whose acquisition channel is unknowable.
     *
     * Read per event rather than once in the constructor: a client that outlives
     * a request would otherwise stamp the first visitor it ever saw onto every
     * later user's events.
     *
     * Skipped under the CLI SAPI — queue workers, Octane, Swoole and RoadRunner
     * all run there, and $_COOKIE is either empty or left over from an unrelated
     * request. Those runtimes should call setVisitorId() instead.
     *
     * Returns the raw cookie value; the caller validates it. Override to read
     * the cookie off your framework's request object rather than the superglobal.
     */
    protected function visitorIdFromCookie(): ?string
    {
        if (\PHP_SAPI === 'cli' || \PHP_SAPI === 'phpdbg') {
            return null;
        }

        $visitorId = $_COOKIE[self::VISITOR_COOKIE] ?? null;

        return \is_string($visitorId) ? $visitorId : null;
    }

    /**
     * Cookies are user-controlled and `visitor_id` is a UUID column at the other
     * end, so a hand-edited value would fail the insert for the whole batch.
     * Anything that isn't a well-formed UUID is dropped rather than forwarded.
     */
    private static function normalizeVisitorId(mixed $visitorId): ?string
    {
        if (! \is_string($visitorId)) {
            return null;
        }

        $visitorId = \trim($visitorId);

        return \preg_match('/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i', $visitorId) === 1
            ? \strtolower($visitorId)
            : null;
    }

    /**
     * Read the installed package version from Composer's runtime metadata,
     * falling back to "0.0.0" when the SDK is loaded outside Composer.
     */
    private function resolveSdkVersion(): string
    {
        if (class_exists(\Composer\InstalledVersions::class)) {
            try {
                $v = \Composer\InstalledVersions::getPrettyVersion('ripplesanalytics/ripples-php');
                if (\is_string($v) && $v !== '') {
                    return \ltrim($v, 'v');
                }
            } catch (\Throwable) {
                // fall through
            }
        }
        return '0.0.0';
    }

    /**
     * Dispatch a request, swallowing any network or API error so the host
     * application is never disrupted by a Ripples outage or slow response.
     * If an on_error callback is configured it receives the Throwable for logging.
     */
    private function send(string $path, array $data): void
    {
        try {
            $this->post($path, $data);
        } catch (\Throwable $e) {
            if ($this->onError !== null) {
                ($this->onError)($e);
            }
        }
    }

    /**
     * Send a POST request to the Ripples API.
     *
     * Override this method to use Guzzle, Symfony HTTP, or any other client.
     */
    protected function post(string $path, array $data): void
    {
        $url  = "{$this->baseUrl}{$path}";
        $json = json_encode($data);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                "Authorization: Bearer {$this->secretKey}",
            ],
        ]);

        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);

        if ($error) {
            throw new RipplesException("HTTP request failed: {$error}");
        }

        if ($status >= 400) {
            $decoded = json_decode($body, true) ?? [];
            $message = $decoded['error'] ?? "HTTP {$status}";
            throw new RipplesException($message, $status);
        }
    }
}
