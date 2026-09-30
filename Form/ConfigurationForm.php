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

namespace MetaConversionsApi\Form;

use MetaConversionsApi\MetaConversionsApi;
use MetaConversionsApi\Service\Settings;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Constraints\Regex;
use Thelia\Core\Translation\Translator;
use Thelia\Form\BaseForm;

/**
 * The access token is never written back into the page: the field stays empty and an empty field keeps the saved token.
 */
class ConfigurationForm extends BaseForm
{
    public static function getName(): string
    {
        return 'metaconversionsapi_form_configuration_form';
    }

    protected function buildForm(): void
    {
        $translator = Translator::getInstance();
        $settings = new Settings();

        $this->formBuilder
            ->add('tracker_pixel_id', TextType::class, [
                'required' => true,
                'label' => $translator->trans('Pixel Id', [], MetaConversionsApi::DOMAIN_NAME),
                'constraints' => [
                    new NotBlank(),
                    new Regex(pattern: '/^\d+$/', message: $translator->trans('The pixel id is made of digits only', [], MetaConversionsApi::DOMAIN_NAME)),
                ],
                'data' => $settings->pixelId(),
            ])
            ->add('tracker_token', PasswordType::class, [
                'required' => false,
                'always_empty' => true,
                'label' => $translator->trans('Token', [], MetaConversionsApi::DOMAIN_NAME),
                'help' => $settings->hasAccessToken()
                    ? $translator->trans('A token is saved: leave empty to keep it', [], MetaConversionsApi::DOMAIN_NAME)
                    : $translator->trans('No token saved yet', [], MetaConversionsApi::DOMAIN_NAME),
            ])
            ->add('tracker_active', CheckboxType::class, [
                'required' => false,
                'label' => $translator->trans('Active Tracker ?', [], MetaConversionsApi::DOMAIN_NAME),
                'data' => $settings->isActive(),
            ])
            ->add('tracker_test_event_code', TextType::class, [
                'required' => false,
                'label' => $translator->trans('Test Event Code', [], MetaConversionsApi::DOMAIN_NAME),
                'attr' => ['placeholder' => 'TESTXXXXX'],
                'data' => (string) MetaConversionsApi::getConfigValue(MetaConversionsApi::META_TRACKER_TEST_EVENT_CODE),
            ])
            ->add('tracker_test_mode', CheckboxType::class, [
                'required' => false,
                'label' => $translator->trans('Test Mode ?', [], MetaConversionsApi::DOMAIN_NAME),
                'data' => (bool) MetaConversionsApi::getConfigValue(MetaConversionsApi::META_TRACKER_TEST_MODE),
            ])
            ->add('track_personal_data', CheckboxType::class, [
                'required' => false,
                'label' => $translator->trans('Send the customer personal data (hashed)', [], MetaConversionsApi::DOMAIN_NAME),
                'data' => $settings->sendsPersonalData(),
            ])
            ->add('consent_required', CheckboxType::class, [
                'required' => false,
                'label' => $translator->trans('Send only with the visitor consent', [], MetaConversionsApi::DOMAIN_NAME),
                'data' => $settings->requiresConsent(),
            ])
            ->add('consent_vendor', TextType::class, [
                'required' => false,
                'label' => $translator->trans('Meta vendor name in the consent tool', [], MetaConversionsApi::DOMAIN_NAME),
                'attr' => ['placeholder' => MetaConversionsApi::DEFAULT_CONSENT_VENDOR],
                'constraints' => [
                    new Regex(pattern: '/^[A-Za-z0-9_\-]*$/', message: $translator->trans('Letters, digits, dashes and underscores only', [], MetaConversionsApi::DOMAIN_NAME)),
                ],
                'data' => $settings->consentVendor(),
            ])
            ->add('snapshot_retention_days', IntegerType::class, [
                'required' => true,
                'label' => $translator->trans('Days the visitor details of an unpaid order are kept', [], MetaConversionsApi::DOMAIN_NAME),
                'constraints' => [new NotBlank(), new Range(min: 1, max: 365)],
                'data' => $settings->snapshotRetentionDays(),
            ]);
    }
}
