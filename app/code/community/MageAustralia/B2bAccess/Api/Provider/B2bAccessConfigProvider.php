<?php

/**
 * Maho
 *
 * @package    MageAustralia_B2bAccess
 * @copyright  Copyright (c) 2026 Mage Australia
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace MageAustralia\B2bAccess\Api\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use MageAustralia\B2bAccess\Api\Resource\B2bAccessConfig;

/**
 * Reads the module's effective admin config and returns the storefront-facing
 * subset. The values come from the standard `b2baccess/general/*` config
 * paths through the module's helper (which handles inheritance + scope).
 *
 * When the module is disabled at the current store scope, we still return a
 * populated DTO (with `enabled: false`) rather than a 404; that way the
 * storefront can differentiate "not installed" (404) from "installed but off"
 * (200 with `enabled: false`) and render the right UX.
 */
final class B2bAccessConfigProvider implements ProviderInterface
{
    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): B2bAccessConfig
    {
        /** @var \MageAustralia_B2bAccess_Helper_Data $helper */
        $helper = \Mage::helper('b2baccess');

        $dto = new B2bAccessConfig();
        $dto->enabled = $helper->isEnabled();
        if (!$dto->enabled) {
            // Fields default to false/null; nothing else to fill.
            return $dto;
        }

        $storeId = (int) \Mage::app()->getStore()->getId();
        $dto->requireLogin = $helper->isLoginWallActive($storeId);
        // shouldHidePrice with no product = "is the price gate active at all"
        $dto->hidePrice = $helper->shouldHidePrice();
        $dto->blockPurchase = $helper->shouldBlockPurchase();
        $dto->loginMessage = $helper->getLoginMessage();
        $dto->hiddenPriceMessage = $helper->getPriceMessage();
        $dto->hiddenPriceCtaHref = $helper->getPriceCtaHref();
        $dto->hiddenPriceMessageForCustomer = $helper->getPriceMessageForCustomer();
        $dto->hiddenPriceCtaLabelForCustomer = $helper->getPriceCtaLabelForCustomer();
        $dto->hiddenPriceCtaHrefForCustomer = $helper->getPriceCtaHrefForCustomer();
        $dto->loginRedirectUrl = $helper->getLoginRedirectUrl();

        return $dto;
    }
}
