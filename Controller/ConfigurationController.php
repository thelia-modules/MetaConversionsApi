<?php

namespace MetaConversionsApi\Controller;

use Exception;
use MetaConversionsApi\MetaConversionsApi;
use Symfony\Component\HttpFoundation\Response;
use Thelia\Controller\Admin\AdminController;
use Thelia\Core\Template\ParserContext;
use Thelia\Form\Exception\FormValidationException;

class ConfigurationController extends AdminController
{
    public function saveConfiguration(): ?Response
    {
        $form = $this->createForm("metaconversionsapi_form_configuration_form");
        try {
            $data = $this->validateForm($form)->getData();

            MetaConversionsApi::setConfigValue(MetaConversionsApi::META_TRACKER_PIXEL_ID, $data["tracker_pixel_id"]);
            MetaConversionsApi::setConfigValue(MetaConversionsApi::META_TRACKER_TOKEN, $data["tracker_token"]);
            MetaConversionsApi::setConfigValue(MetaConversionsApi::META_TRACKER_ACTIVE, $data["tracker_active"]);
            MetaConversionsApi::setConfigValue(MetaConversionsApi::META_TRACKER_TEST_EVENT_CODE, $data["tracker_test_event_code"]);
            MetaConversionsApi::setConfigValue(MetaConversionsApi::META_TRACKER_TEST_MODE, $data["tracker_test_mode"]);


            return $this->generateSuccessRedirect($form);
        } catch (FormValidationException $e) {
            $error_message = $this->createStandardFormValidationErrorMessage($e);
        } catch (Exception $e) {
            $error_message = $e->getMessage();
        }

        $form->setErrorMessage($error_message);

        $this->getParserContext()
            ->addForm($form)
            ->setGeneralError($error_message);

        return $this->generateErrorRedirect($form);
    }
}