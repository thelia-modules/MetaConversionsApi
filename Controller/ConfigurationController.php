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

namespace MetaConversionsApi\Controller;

use MetaConversionsApi\Form\ConfigurationForm;
use MetaConversionsApi\MetaConversionsApi;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Thelia\Controller\Admin\BaseAdminController;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Form\Exception\FormValidationException;

class ConfigurationController extends BaseAdminController
{
    #[Route('/admin/module/MetaConversionsApi/configuration', name: 'meta_conversions_api.configuration', methods: ['POST'])]
    public function saveConfiguration(): Response
    {
        if (null !== $response = $this->checkAuth(AdminResources::MODULE, MetaConversionsApi::DOMAIN_NAME, AccessManager::UPDATE)) {
            return $response;
        }

        $form = $this->createForm(ConfigurationForm::getName());

        try {
            $data = $this->validateForm($form)->getData();

            MetaConversionsApi::setConfigValue(MetaConversionsApi::META_TRACKER_PIXEL_ID, trim((string) $data['tracker_pixel_id']));
            MetaConversionsApi::setConfigValue(MetaConversionsApi::META_TRACKER_ACTIVE, $data['tracker_active'] ? '1' : '0');
            MetaConversionsApi::setConfigValue(MetaConversionsApi::META_TRACKER_TEST_EVENT_CODE, trim((string) $data['tracker_test_event_code']));
            MetaConversionsApi::setConfigValue(MetaConversionsApi::META_TRACKER_TEST_MODE, $data['tracker_test_mode'] ? '1' : '0');
            MetaConversionsApi::setConfigValue(MetaConversionsApi::META_TRACKER_TRACK_PERSONAL_DATA, $data['track_personal_data'] ? '1' : '0');
            MetaConversionsApi::setConfigValue(MetaConversionsApi::META_TRACKER_CONSENT_REQUIRED, $data['consent_required'] ? '1' : '0');
            MetaConversionsApi::setConfigValue(MetaConversionsApi::META_TRACKER_CONSENT_VENDOR, trim((string) $data['consent_vendor']));
            MetaConversionsApi::setConfigValue(MetaConversionsApi::META_TRACKER_SNAPSHOT_RETENTION_DAYS, (string) (int) $data['snapshot_retention_days']);

            $token = trim((string) $data['tracker_token']);
            if ('' !== $token) {
                MetaConversionsApi::setConfigValue(MetaConversionsApi::META_TRACKER_TOKEN, $token);
            }
        } catch (FormValidationException $exception) {
            $this->addFlash('danger', $this->createStandardFormValidationErrorMessage($exception));
        }

        return $this->generateRedirectFromRoute('admin.module.configure', [], ['module_code' => MetaConversionsApi::getModuleCode()]);
    }
}
