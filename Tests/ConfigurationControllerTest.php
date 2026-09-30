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

use MetaConversionsApi\Form\ConfigurationForm;
use MetaConversionsApi\MetaConversionsApi;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Thelia\Core\HttpFoundation\Session\Session;

/**
 * The request goes through the kernel itself: symfony/browser-kit is not required by the module.
 */
final class ConfigurationControllerTest extends ConversionTestCase
{
    public function testSavingKeepsTheTokenWhenItsFieldIsLeftEmpty(): void
    {
        $response = $this->save([
            'tracker_pixel_id' => '987654321',
            'tracker_token' => '',
            'tracker_active' => '1',
            'tracker_test_event_code' => ' TEST999 ',
            'consent_vendor' => 'meta_pixel',
            'snapshot_retention_days' => '45',
        ]);

        self::assertSame(302, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('987654321', MetaConversionsApi::getConfigValue(MetaConversionsApi::META_TRACKER_PIXEL_ID));
        self::assertSame(self::ACCESS_TOKEN, MetaConversionsApi::getConfigValue(MetaConversionsApi::META_TRACKER_TOKEN));
        self::assertSame('1', MetaConversionsApi::getConfigValue(MetaConversionsApi::META_TRACKER_ACTIVE));
        self::assertSame('TEST999', MetaConversionsApi::getConfigValue(MetaConversionsApi::META_TRACKER_TEST_EVENT_CODE));
        self::assertSame('0', MetaConversionsApi::getConfigValue(MetaConversionsApi::META_TRACKER_CONSENT_REQUIRED), 'An unticked box is saved as off.');
        self::assertSame('meta_pixel', MetaConversionsApi::getConfigValue(MetaConversionsApi::META_TRACKER_CONSENT_VENDOR));
        self::assertSame('45', MetaConversionsApi::getConfigValue(MetaConversionsApi::META_TRACKER_SNAPSHOT_RETENTION_DAYS));
    }

    public function testANewTokenReplacesTheSavedOne(): void
    {
        $this->save(['tracker_pixel_id' => self::PIXEL_ID, 'tracker_token' => 'a-new-token']);

        self::assertSame('a-new-token', MetaConversionsApi::getConfigValue(MetaConversionsApi::META_TRACKER_TOKEN));
    }

    public function testAPixelIdThatIsNotANumberIsRefused(): void
    {
        $this->save(['tracker_pixel_id' => '12<script>', 'tracker_token' => 'a-new-token']);

        self::assertSame(self::PIXEL_ID, MetaConversionsApi::getConfigValue(MetaConversionsApi::META_TRACKER_PIXEL_ID));
        self::assertSame(self::ACCESS_TOKEN, MetaConversionsApi::getConfigValue(MetaConversionsApi::META_TRACKER_TOKEN));
    }

    public function testTheConfigurationPageShowsTheSettingsButNeverTheToken(): void
    {
        $request = Request::create('/admin/module/MetaConversionsApi', 'GET');
        $request->setSession($this->adminSession());

        $response = $this->handleAsMainRequest($request);
        $content = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('metaconversionsapi_form_configuration_form[tracker_pixel_id]', $content);
        self::assertStringContainsString('value="'.self::PIXEL_ID.'"', $content);
        self::assertStringContainsString('metaconversionsapi_form_configuration_form[consent_vendor]', $content);
        self::assertStringContainsString('META_CONVERSION_ENV', $content, 'The page warns that nothing is sent outside production.');
        self::assertStringNotContainsString(self::ACCESS_TOKEN, $content);
    }

    /**
     * @param array<string, string> $fields
     */
    private function save(array $fields): Response
    {
        $request = Request::create('/admin/module/MetaConversionsApi/configuration', 'POST');
        $request->setSession($this->adminSession());
        $request->request->set(ConfigurationForm::getName(), $fields + [
            'snapshot_retention_days' => '30',
            '_token' => $this->csrfToken($request, ConfigurationForm::getName()),
        ]);

        return $this->handleAsMainRequest($request);
    }

    private function adminSession(): Session
    {
        $session = new Session(new MockArraySessionStorage());
        $session->setAdminUser($this->fixtures->admin());

        return $session;
    }

    /**
     * IntegrationTestCase pushes a synthetic request: it is taken off the stack while the
     * kernel handles this one, so that this request is the main request the controller reads.
     */
    private function handleAsMainRequest(Request $request): Response
    {
        $requestStack = $this->containerRequestStack();
        $pushedRequests = [];
        while (null !== $pushedRequest = $requestStack->pop()) {
            $pushedRequests[] = $pushedRequest;
        }

        try {
            return self::$kernel->handle($request);
        } finally {
            while (null !== $requestStack->getCurrentRequest()) {
                $requestStack->pop();
            }
            foreach (array_reverse($pushedRequests) as $pushedRequest) {
                $requestStack->push($pushedRequest);
            }
        }
    }

    private function containerRequestStack(): RequestStack
    {
        $requestStack = static::getContainer()->get('request_stack');
        self::assertInstanceOf(RequestStack::class, $requestStack);

        return $requestStack;
    }

    private function csrfToken(Request $request, string $tokenId): string
    {
        $requestStack = $this->containerRequestStack();
        $tokenManager = static::getContainer()->get('security.csrf.token_manager');
        self::assertInstanceOf(CsrfTokenManagerInterface::class, $tokenManager);

        $requestStack->push($request);
        try {
            return $tokenManager->getToken($tokenId)->getValue();
        } finally {
            $requestStack->pop();
        }
    }
}
