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

namespace MetaConversionsApi;

use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Thelia\Module\BaseModule;

class MetaConversionsApi extends BaseModule
{
    public const DOMAIN_NAME = 'metaconversionsapi';

    /** HTTP client of the Conversions API, built without a logger: the access token must never reach a log. */
    public const HTTP_CLIENT_SERVICE = 'meta_conversions_api.http_client';

    /** Keys of the 2.x line, kept so that an upgrade keeps its settings. */
    public const META_TRACKER_TOKEN = 'meta_tracker_token';
    public const META_TRACKER_PIXEL_ID = 'meta_tracker_pixel_id';
    public const META_TRACKER_ACTIVE = 'meta_tracker_active';
    public const META_TRACKER_TEST_EVENT_CODE = 'meta_tracker_test_event_code';
    public const META_TRACKER_TEST_MODE = 'meta_tracker_test_mode';
    public const META_TRACKER_TRACK_PERSONAL_DATA = 'track_personal_data';

    public const META_TRACKER_CONSENT_REQUIRED = 'meta_tracker_consent_required';
    public const META_TRACKER_CONSENT_VENDOR = 'meta_tracker_consent_vendor';
    public const DEFAULT_CONSENT_VENDOR = 'facebook_pixel';

    /** Days the visitor details frozen on an unpaid order are kept, purged by `thelia maintenance:purge`. */
    public const META_TRACKER_SNAPSHOT_RETENTION_DAYS = 'meta_tracker_snapshot_retention_days';
    public const DEFAULT_SNAPSHOT_RETENTION_DAYS = 30;

    public static function configureServices(ServicesConfigurator $servicesConfigurator): void
    {
        $servicesConfigurator->load(self::getModuleCode().'\\', __DIR__)
            ->exclude([
                __DIR__.'/I18n/*',
                __DIR__.'/Tests/*',
                __DIR__.'/templates/*',
                __DIR__.'/Service/ConversionEvent.php',
                __DIR__.'/Service/VisitorSnapshot.php',
                __DIR__.'/Service/CountryCallingCodes.php',
            ])
            ->autowire(true)
            ->autoconfigure(true);

        $servicesConfigurator->set(self::HTTP_CLIENT_SERVICE, HttpClientInterface::class)
            ->factory([HttpClient::class, 'create']);
    }
}
