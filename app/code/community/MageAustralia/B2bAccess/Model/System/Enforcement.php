<?php

declare(strict_types=1);

/**
 * Maho
 *
 * @package    MageAustralia_B2bAccess
 * @copyright  Copyright (c) 2026 Mage Australia
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

/**
 * Source model for a rule's enforcement level: where the rule bites.
 */
class MageAustralia_B2bAccess_Model_System_Enforcement
{
    /**
     * @return list<array{value:string,label:string}>
     */
    public function toOptionArray(): array
    {
        $h = Mage::helper('b2baccess');
        // "Visibility" reads as a synonym for the hide-listing action to a
        // merchant scanning the form. Use "Storefront" so the enforcement
        // choice reads as "where the rule fires" (storefront pages vs the
        // checkout guard) rather than "what it does to visibility".
        return [
            ['value' => MageAustralia_B2bAccess_Model_Gate_Rule::ENFORCE_BOTH,       'label' => $h->__('Storefront and checkout (recommended)')],
            ['value' => MageAustralia_B2bAccess_Model_Gate_Rule::ENFORCE_VISIBILITY, 'label' => $h->__('Storefront only (hide the price on the site; still allow the order if it reaches checkout)')],
            ['value' => MageAustralia_B2bAccess_Model_Gate_Rule::ENFORCE_CHECKOUT,   'label' => $h->__('Checkout only (let people browse freely; refuse the order at add-to-cart)')],
        ];
    }
}
