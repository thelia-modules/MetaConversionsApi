<?php

namespace MetaConversionsApi\Hook;

use MetaConversionsApi\Service\MetaOrderService;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\Event\Hook\HookRenderEvent;
use Thelia\Core\Hook\BaseHook;
use Thelia\Core\Template\Assets\AssetResolverInterface;
use TheliaSmarty\Template\SmartyParser;

class FrontHook extends BaseHook
{
    public function __construct(
        SmartyParser $parser = null,
        AssetResolverInterface $resolver = null,
        EventDispatcherInterface $eventDispatcher = null,
        private readonly MetaOrderService $metaOrderService,
        private RequestStack $requestStack
    )
    {
        parent::__construct($parser, $resolver, $eventDispatcher);
    }

    public static function getSubscribedHooks(): array
    {
        return [
            "main.head-top" => [
                [
                    "type" => "front",
                    "method" => "onMainHeadTop"
                ],
            ]
        ];
    }

    public function onMainHeadTop(HookRenderEvent $event)
    {
        $customer = $this->requestStack->getSession()->get('thelia.customer_user');

        $this->metaOrderService->sendData('PageView', null, $customer);
    }

}