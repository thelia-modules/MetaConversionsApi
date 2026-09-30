<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace MetaConversionsApi\Service;

use MetaConversionsApi\MetaConversionsApi;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Sends a batch of events to the Conversions API in a single call, with a short timeout. A failure is logged and
 * swallowed: neither a page nor an order waits for Meta. The access token travels in the body, never in the URL,
 * through the client of the module that has no logger, and it is masked in anything logged.
 *
 * Circuit breaker: after FAILURES_BEFORE_SUSPENSION failures in a row, nothing is sent for SUSPENSION_SECONDS, so that
 * an unreachable Meta does not cost a timeout to every PHP worker. The events of that window are dropped.
 */
final readonly class ConversionsApiClient
{
    public const GRAPH_API_URL = 'https://graph.facebook.com';
    public const GRAPH_API_VERSION = 'v24.0';
    public const TIMEOUT_SECONDS = 2.0;
    public const MAX_DURATION_SECONDS = 3.0;

    public const FAILURES_BEFORE_SUSPENSION = 5;
    public const SUSPENSION_SECONDS = 600;
    public const FAILURES_CACHE_KEY = 'meta_conversions_api.consecutive_failures';
    public const SUSPENDED_CACHE_KEY = 'meta_conversions_api.suspended';

    public function __construct(
        private Settings $settings,
        #[Autowire(service: MetaConversionsApi::HTTP_CLIENT_SERVICE)]
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        #[Autowire(service: 'cache.app')]
        private CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * @param list<ConversionEvent> $events
     */
    public function send(array $events): void
    {
        if ([] === $events || !$this->settings->isSendingEnabled() || $this->isSuspended()) {
            return;
        }

        $accessToken = $this->settings->accessToken();
        $payload = [
            'data' => array_map(static fn (ConversionEvent $event): array => $event->toArray(), $events),
            'access_token' => $accessToken,
        ];

        $testEventCode = $this->settings->testEventCode();
        if (null !== $testEventCode) {
            $payload['test_event_code'] = $testEventCode;
        }

        try {
            $response = $this->httpClient->request(
                'POST',
                \sprintf('%s/%s/%s/events', self::GRAPH_API_URL, self::GRAPH_API_VERSION, rawurlencode($this->settings->pixelId())),
                [
                    'headers' => ['Content-Type' => 'application/json'],
                    'body' => json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_UNESCAPED_SLASHES),
                    'timeout' => self::TIMEOUT_SECONDS,
                    'max_duration' => self::MAX_DURATION_SECONDS,
                ],
            );

            $statusCode = $response->getStatusCode();
            if ($statusCode >= 200 && $statusCode < 300) {
                $this->cache->deleteItem(self::FAILURES_CACHE_KEY);

                return;
            }

            $this->fail(\count($events), \sprintf('HTTP %d %s', $statusCode, $this->errorMessage($response->getContent(false))), $accessToken);
        } catch (ExceptionInterface|\JsonException $exception) {
            $this->fail(\count($events), $exception->getMessage(), $accessToken);
        }
    }

    public function isSuspended(): bool
    {
        return $this->cache->hasItem(self::SUSPENDED_CACHE_KEY);
    }

    private function fail(int $eventCount, string $reason, string $accessToken): void
    {
        $this->logger->error(\sprintf(
            'Meta Conversions API: %d event(s) not sent (%s)',
            $eventCount,
            '' === $accessToken ? $reason : str_replace($accessToken, '***', $reason),
        ));

        $failures = $this->cache->getItem(self::FAILURES_CACHE_KEY);
        $count = (\is_int($failures->get()) ? $failures->get() : 0) + 1;

        if ($count < self::FAILURES_BEFORE_SUSPENSION) {
            $this->cache->save($failures->set($count)->expiresAfter(self::SUSPENSION_SECONDS));

            return;
        }

        $this->cache->deleteItem(self::FAILURES_CACHE_KEY);
        $this->cache->save($this->cache->getItem(self::SUSPENDED_CACHE_KEY)->set(true)->expiresAfter(self::SUSPENSION_SECONDS));
        $this->logger->error(\sprintf(
            'Meta Conversions API: %d failures in a row, nothing is sent for %d minutes',
            self::FAILURES_BEFORE_SUSPENSION,
            intdiv(self::SUSPENSION_SECONDS, 60),
        ));
    }

    private function errorMessage(string $content): string
    {
        try {
            $decoded = json_decode($content, true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return '';
        }

        $message = \is_array($decoded) && \is_array($decoded['error'] ?? null) ? ($decoded['error']['message'] ?? '') : '';

        return \is_string($message) ? $message : '';
    }
}
