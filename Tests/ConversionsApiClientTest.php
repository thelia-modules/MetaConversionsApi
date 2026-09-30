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

namespace MetaConversionsApi\Tests;

use MetaConversionsApi\EventListener\SendConversionsListener;
use MetaConversionsApi\MetaConversionsApi;
use MetaConversionsApi\Service\ConversionEvent;
use MetaConversionsApi\Service\ConversionsApiClient;
use MetaConversionsApi\Service\Settings;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\NativeHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ConversionsApiClientTest extends ConversionTestCase
{
    public function testTheQueuedEventsLeaveInOneCallWithTheTokenInTheBodyOnly(): void
    {
        $requests = [];
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse('{"events_received":2}');
        });
        $this->queue->add($this->event('PageView'));
        $this->queue->add($this->event('AddToCart', 'shared-id'));

        (new SendConversionsListener($this->queue, $this->client($httpClient)))->send();

        self::assertCount(1, $requests);
        self::assertSame('POST', $requests[0]['method']);
        self::assertSame('https://graph.facebook.com/'.ConversionsApiClient::GRAPH_API_VERSION.'/'.self::PIXEL_ID.'/events', $requests[0]['url']);
        self::assertStringNotContainsString(self::ACCESS_TOKEN, $requests[0]['url']);
        self::assertLessThanOrEqual(ConversionsApiClient::TIMEOUT_SECONDS, $requests[0]['options']['timeout']);

        $body = json_decode((string) $requests[0]['options']['body'], true, 16, \JSON_THROW_ON_ERROR);
        self::assertSame(self::ACCESS_TOKEN, $body['access_token']);
        self::assertArrayNotHasKey('test_event_code', $body);
        self::assertSame(['PageView', 'AddToCart'], array_column($body['data'], 'event_name'));
        self::assertSame('shared-id', $body['data'][1]['event_id']);
        self::assertSame('website', $body['data'][0]['action_source']);
        self::assertSame([], $this->queue->events(), 'The queue is emptied once sent.');
    }

    public function testTheTestEventCodeIsSentInTestModeOnly(): void
    {
        MetaConversionsApi::setConfigValue(MetaConversionsApi::META_TRACKER_TEST_EVENT_CODE, 'TEST12345');
        MetaConversionsApi::setConfigValue(MetaConversionsApi::META_TRACKER_TEST_MODE, '1');
        $body = null;
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$body): MockResponse {
            $body = json_decode((string) $options['body'], true, 16, \JSON_THROW_ON_ERROR);

            return new MockResponse('{}');
        });

        $this->client($httpClient)->send([$this->event('PageView')]);

        self::assertSame('TEST12345', $body['test_event_code'] ?? null);
    }

    public function testNothingIsSentOutsideProductionOrWhenInactive(): void
    {
        $httpClient = new MockHttpClient(static fn (): MockResponse => throw new \LogicException('No call expected'));

        $this->client($httpClient, 'dev')->send([$this->event('PageView')]);
        MetaConversionsApi::setConfigValue(MetaConversionsApi::META_TRACKER_ACTIVE, '0');
        $this->client($httpClient)->send([$this->event('PageView')]);

        self::assertSame(0, $httpClient->getRequestsCount());
    }

    public function testAFailureIsLoggedWithoutTheTokenAndNeverThrown(): void
    {
        $handler = new TestHandler();
        $unreachable = new MockHttpClient(static fn (): MockResponse => throw new TransportException('Could not resolve host for token '.self::ACCESS_TOKEN));
        $refused = new MockHttpClient(new MockResponse('{"error":{"message":"Invalid OAuth access token '.self::ACCESS_TOKEN.'"}}', ['http_code' => 400]));

        $this->client($unreachable, logger: new Logger('test', [$handler]))->send([$this->event('PageView')]);
        $this->client($refused, logger: new Logger('test', [$handler]))->send([$this->event('Purchase')]);

        self::assertCount(2, $handler->getRecords());
        self::assertTrue($handler->hasErrorThatContains('HTTP 400 Invalid OAuth access token'));
        foreach ($handler->getRecords() as $record) {
            self::assertStringNotContainsString(self::ACCESS_TOKEN, $record['message']);
        }
    }

    public function testRepeatedFailuresSuspendTheSendingForAWhile(): void
    {
        $cache = new ArrayAdapter();
        $unreachable = new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['error' => 'timeout']));
        $client = $this->client($unreachable, cache: $cache);

        for ($attempt = 0; $attempt < ConversionsApiClient::FAILURES_BEFORE_SUSPENSION + 3; ++$attempt) {
            $client->send([$this->event('PageView')]);
        }

        self::assertSame(ConversionsApiClient::FAILURES_BEFORE_SUSPENSION, $unreachable->getRequestsCount(), 'No call while suspended.');
        self::assertTrue($client->isSuspended());
    }

    public function testASuccessResetsTheFailureCount(): void
    {
        $cache = new ArrayAdapter();
        $calls = 0;
        $flaky = new MockHttpClient(static function () use (&$calls): MockResponse {
            ++$calls;

            return 0 === $calls % ConversionsApiClient::FAILURES_BEFORE_SUSPENSION ? new MockResponse('{}') : new MockResponse('', ['error' => 'timeout']);
        });
        $client = $this->client($flaky, cache: $cache);

        for ($attempt = 0; $attempt < 3 * ConversionsApiClient::FAILURES_BEFORE_SUSPENSION; ++$attempt) {
            $client->send([$this->event('PageView')]);
        }

        self::assertFalse($client->isSuspended());
        self::assertSame(3 * ConversionsApiClient::FAILURES_BEFORE_SUSPENSION, $flaky->getRequestsCount());
    }

    public function testAnInvalidUtf8ValueDoesNotLoseTheBatch(): void
    {
        $body = null;
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$body): MockResponse {
            $body = (string) $options['body'];

            return new MockResponse('{}');
        });

        $this->client($httpClient)->send([new ConversionEvent('PageView', time(), ['client_user_agent' => "Broken\xC3\x28"])]);

        self::assertStringContainsString('Broken\ufffd(', (string) $body);
    }

    public function testTheResponseAndTheCommandEndsSendTheQueue(): void
    {
        $dispatcher = static::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        foreach ([KernelEvents::TERMINATE, ConsoleEvents::TERMINATE] as $eventName) {
            $listened = array_filter(
                $dispatcher->getListeners($eventName),
                static fn (mixed $listener): bool => \is_array($listener) && $listener[0] instanceof SendConversionsListener,
            );
            self::assertCount(1, $listened, $eventName);
        }
    }

    /**
     * The framework client logs every URL it calls; the module has its own client, without logger.
     * The request goes to a closed local port.
     */
    public function testTheHttpClientOfTheModuleLogsNothing(): void
    {
        $handler = new TestHandler();
        $container = static::getContainer();
        foreach (['logger', 'monolog.logger.http_client'] as $serviceId) {
            if ($container->has($serviceId)) {
                $logger = $container->get($serviceId);
                self::assertInstanceOf(Logger::class, $logger);
                $logger->pushHandler($handler);
            }
        }

        $client = $container->get(ConversionsApiClient::class);
        $httpClient = (new \ReflectionProperty($client, 'httpClient'))->getValue($client);
        self::assertInstanceOf(HttpClientInterface::class, $httpClient);
        $this->callAClosedPort($httpClient);
        self::assertSame([], $handler->getRecords());

        // Control: the same call through a client with a logger is seen by the spy.
        $loggingClient = new NativeHttpClient();
        $loggingClient->setLogger(new Logger('http_client', [$handler]));
        $this->callAClosedPort($loggingClient);
        self::assertNotSame([], $handler->getRecords());
    }

    private function callAClosedPort(HttpClientInterface $httpClient): void
    {
        try {
            $httpClient->request('GET', 'http://127.0.0.1:9/v24.0/'.self::PIXEL_ID.'/events', ['timeout' => 1])->getContent(false);
        } catch (ExceptionInterface) {
            // Closed on purpose: only what the client logs matters.
        }
    }

    private function client(HttpClientInterface $httpClient, string $environment = Settings::PRODUCTION_ENVIRONMENT, ?Logger $logger = null, ?ArrayAdapter $cache = null): ConversionsApiClient
    {
        return new ConversionsApiClient(new Settings($environment), $httpClient, $logger ?? new Logger('test', [new TestHandler()]), $cache ?? new ArrayAdapter());
    }

    private function event(string $name, ?string $eventId = null): ConversionEvent
    {
        return new ConversionEvent($name, time(), ['client_user_agent' => self::VISITOR_USER_AGENT], $eventId, 'https://shop.test/');
    }
}
