<?php

namespace MetaConversionsApi\Form;

use MetaConversionsApi\MetaConversionsApi;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Thelia\Core\Translation\Translator;
use Thelia\Form\BaseForm;

class ConfigurationForm extends BaseForm
{
    protected function buildForm(): void
    {
        $this->formBuilder
            ->add(
                'tracker_pixel_id',
                TextType::class, [
                    'required' => true,
                    'label' => Translator::getInstance()->trans('Pixel Id', [], MetaConversionsApi::DOMAIN_NAME),
                    'data' => MetaConversionsApi::getConfigValue(MetaConversionsApi::META_TRACKER_PIXEL_ID)
                ]
            )
            ->add(
                'tracker_token',
                TextType::class, [
                    'required' => true,
                    'label' => Translator::getInstance()->trans('Token', [], MetaConversionsApi::DOMAIN_NAME),
                    'data' => MetaConversionsApi::getConfigValue(MetaConversionsApi::META_TRACKER_TOKEN)
                ]
            )
            ->add(
                'tracker_active',
                CheckboxType::class, [
                    'required' => false,
                    'label' => Translator::getInstance()->trans('Active Tracker ?', [], MetaConversionsApi::DOMAIN_NAME),
                    'data' => (bool)MetaConversionsApi::getConfigValue(MetaConversionsApi::META_TRACKER_ACTIVE)
                ]
            )
            ->add(
                'tracker_test_event_code',
                TextType::class, [
                    'required' => false,
                    'label' => Translator::getInstance()->trans('Test Event Code', [], MetaConversionsApi::DOMAIN_NAME),
                    'attr' => [
                        'placeholder' => Translator::getInstance()->trans("TESTXXXXX", [], MetaConversionsApi::DOMAIN_NAME),
                    ],
                    'data' => MetaConversionsApi::getConfigValue(MetaConversionsApi::META_TRACKER_TEST_EVENT_CODE)

                ]
            )
            ->add(
                'tracker_test_mode',
                CheckboxType::class, [
                    'required' => false,
                    'label' => Translator::getInstance()->trans('Test Mode ?', [], MetaConversionsApi::DOMAIN_NAME),
                    'data' => (bool)MetaConversionsApi::getConfigValue(MetaConversionsApi::META_TRACKER_TEST_MODE)
                ]
            );
    }
}