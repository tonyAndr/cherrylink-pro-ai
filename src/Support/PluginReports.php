<?php

declare(strict_types=1);

namespace CherryLink\Support;

use CherryLink\Api\Client;
use CherryLink\Api\ReportClient;
use CherryLink\Contracts\Registerable;

/**
 * Durable, low-frequency plugin lifecycle reports.
 *
 * Activation/update handling only writes a bounded pending event. The HTTP call
 * is owned by a single WP-Cron event, never by activation, rendering, AJAX or a
 * post save. The existing five-minute capability tick performs a local recovery
 * check and schedules a send only when work is actually pending.
 *
 * Request isolation contract: zero external HTTP on ordinary requests; at most
 * one batched POST per bounded retry attempt, with no client-side sleep loop.
 */
final class PluginReports implements Registerable
{
    public const OPTION       = 'cherrylink_plugin_reports';
    public const VERSION_OPTION = 'cherrylink_last_seen_version';
    public const WEB_ENV_OPTION = 'cherrylink_web_environment';
    public const WEB_ENV_VERSION_OPTION = 'cherrylink_web_environment_version';
    public const SEND_HOOK    = 'cherrylink_report_send';
    public const MAX_EVENTS    = 10;
    public const MAX_ATTEMPTS  = 5;
    private const LOCK         = 'cl_plugin_report_lock';

    /** @var License */
    private $license;

    /** @var callable():ReportClient */
    private $client_factory;

    /** @param callable():ReportClient|null $client_factory Test seam for the send path. */
    public function __construct(License $license, ?callable $client_factory = null)
    {
        $this->license        = $license;
        $this->client_factory = $client_factory ?? static function (): Client {
            return new Client(null, Client::CONTEXT_BACKGROUND);
        };
    }

    public function register(): void
    {
        \add_action(self::SEND_HOOK, [$this, 'send']);
        // Capture the real web-server environment once per plugin version. Cron and
        // WP-CLI report `cli`, so their events reuse this snapshot when available.
        \add_action('admin_init', [$this, 'captureWebEnvironment']);
        // Reuse the existing five-minute tick; this listener performs no HTTP.
        \add_action(Capabilities::REFRESH_HOOK, [$this, 'sweep'], 20);
    }

    /**
     * Store the web-request environment once per version. admin_init is a local
     * option read on admin requests only; no network, no front-end work.
     */
    public function captureWebEnvironment(): void
    {
        if (PHP_SAPI === 'cli' || (\function_exists('wp_doing_cron') && \wp_doing_cron())) {
            return;
        }
        $version = PluginMeta::version();
        if ((string) \get_option(self::WEB_ENV_VERSION_OPTION, '') === $version) {
            return;
        }
        \update_option(self::WEB_ENV_OPTION, Environment::collect(), false);
        \update_option(self::WEB_ENV_VERSION_OPTION, $version, true);
    }

    /** Called from the activation hook after the install routine has finished. */
    public function recordActivation(): void
    {
        $version = PluginMeta::version();
        $last    = (string) \get_option(self::VERSION_OPTION, '');

        if ($last !== '' && $last !== $version) {
            $this->enqueue('plugin.upgraded', $last, $version);
        } else {
            $this->enqueue('plugin.activated', null, $version);
        }

        \update_option(self::VERSION_OPTION, $version, false);
        $this->schedule();
    }

    /**
     * Local recovery/detection tick on the existing five-minute cron. It never
     * makes an HTTP request: it only records a version change and schedules one
     * send event when a report is pending and the site is licensed.
     */
    public function sweep(): void
    {
        $version = PluginMeta::version();
        $last    = (string) \get_option(self::VERSION_OPTION, '');

        if ($last === '') {
            // First run of the reporter on an already-active install: queue a
            // one-time activation milestone so the panel gets a version/runtime
            // snapshot, then normal upgrades are tracked from here.
            $this->enqueue('plugin.activated', null, $version);
            \update_option(self::VERSION_OPTION, $version, false);
        } elseif ($last !== $version) {
            $this->enqueue('plugin.upgraded', $last, $version);
            \update_option(self::VERSION_OPTION, $version, false);
        }

        if ($this->pending() !== [] && $this->license->isLicensed()) {
            $this->schedule();
        }
    }

    /** Cron callback: send one bounded batch, then reschedule on failure. */
    public function send(): void
    {
        if (! $this->license->isLicensed()) {
            return;
        }

        $pending = $this->pending();
        if ($pending === []) {
            return;
        }

        $now = \time();
        $due = \array_values(\array_filter($pending, static function (array $event) use ($now): bool {
            return (int) ($event['next_attempt'] ?? 0) <= $now;
        }));
        if ($due === []) {
            $this->schedule($this->nextDelay($pending));
            return;
        }

        $batch = \array_slice($due, 0, self::MAX_EVENTS);
        $token = RequestLock::acquire(self::LOCK, 120);
        if ($token === null) {
            // Another cron turn owns the attempt; let the recovery tick reschedule.
            return;
        }

        try {
            $client = \call_user_func($this->client_factory);
            $result = $client->report_plugin_events($batch);

            if ($result !== null && ! empty($result['ok'])) {
                $sent      = \array_column($batch, 'event_key');
                $remaining = \array_values(\array_filter($pending, static function (array $event) use ($sent): bool {
                    return ! \in_array((string) ($event['event_key'] ?? ''), $sent, true);
                }));
                \update_option(self::OPTION, $remaining, false);
                if ($remaining !== []) {
                    $this->schedule(60);
                }
                return;
            }

            $failed = \array_column($batch, 'event_key');
            $kept   = [];
            foreach ($pending as $event) {
                if (! \in_array((string) ($event['event_key'] ?? ''), $failed, true)) {
                    $kept[] = $event;
                    continue;
                }

                $attempts = (int) ($event['attempts'] ?? 0) + 1;
                if ($attempts >= self::MAX_ATTEMPTS) {
                    // Drop a permanently failing event after bounded attempts. The
                    // install/version marker is already advanced, so it will not be
                    // recreated forever.
                    continue;
                }

                $event['attempts']     = $attempts;
                $event['next_attempt'] = \time() + $this->backoff($attempts);
                $kept[]                = $event;
            }

            \update_option(self::OPTION, $kept, false);
            if ($kept !== []) {
                $this->schedule(\max(1, $this->nextDelay($kept)));
            }
        } catch (\Throwable $error) {
            // Transport/client failures are retried by the next cron turn; never
            // let a reporting error escape into a page or activation request.
            $this->schedule(300);
        } finally {
            RequestLock::release(self::LOCK, $token);
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function pending(): array
    {
        $value = \get_option(self::OPTION, []);

        return \is_array($value) ? \array_values($value) : [];
    }

    /** Prefer the last real web-request snapshot when running in cron/WP-CLI. */
    private function environment(): array
    {
        $background = PHP_SAPI === 'cli'
            || (\function_exists('wp_doing_cron') && \wp_doing_cron());
        if ($background) {
            $stored = \get_option(self::WEB_ENV_OPTION, []);
            if (\is_array($stored) && ! empty($stored['wp_version'])) {
                return Environment::normalize($stored);
            }
        }

        return Environment::collect();
    }

    private function enqueue(string $action, ?string $from, string $to): void
    {
        $pending = $this->pending();
        foreach ($pending as $event) {
            if (
                ($event['action'] ?? '') === $action
                && ($event['from_version'] ?? null) === $from
                && ($event['plugin_version'] ?? '') === $to
            ) {
                return; // already queued; retries reuse the same event key
            }
        }

        $pending[] = [
            'event_key'     => \wp_generate_uuid4(),
            'action'        => $action,
            'plugin_version' => $to,
            'from_version'  => $from,
            'occurred_at'   => \gmdate('c'),
            'environment'   => $this->environment(),
            'attempts'      => 0,
            'next_attempt'  => 0,
        ];

        if (\count($pending) > self::MAX_EVENTS) {
            $pending = \array_slice($pending, -self::MAX_EVENTS);
        }

        \update_option(self::OPTION, \array_values($pending), false);
    }

    private function schedule(int $delay = 60): void
    {
        if (! \function_exists('wp_next_scheduled') || ! \function_exists('wp_schedule_single_event')) {
            return;
        }
        if (\wp_next_scheduled(self::SEND_HOOK)) {
            return;
        }

        \wp_schedule_single_event(\time() + \max(1, $delay), self::SEND_HOOK);
    }

    /** @param array<int,array<string,mixed>> $events */
    private function nextDelay(array $events): int
    {
        $next = null;
        foreach ($events as $event) {
            $at = (int) ($event['next_attempt'] ?? 0);
            if ($at <= 0) {
                return 60;
            }
            $next = $next === null ? $at : \min($next, $at);
        }

        return $next === null ? 60 : \max(1, $next - \time());
    }

    private function backoff(int $attempt): int
    {
        switch ($attempt) {
            case 1:
                return 300;      // 5 minutes
            case 2:
                return 1800;     // 30 minutes
            case 3:
                return 7200;     // 2 hours
            default:
                return 21600;    // 6 hours
        }
    }
}
