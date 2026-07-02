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
        return [
            ['value' => MageAustralia_B2bAccess_Model_Gate_Rule::ENFORCE_BOTH, 'label' => $h->__('Visibility and checkout')],
            ['value' => MageAustralia_B2bAccess_Model_Gate_Rule::ENFORCE_VISIBILITY, 'label' => $h->__('Visibility only (hide, but allow checkout)')],
            ['value' => MageAustralia_B2bAccess_Model_Gate_Rule::ENFORCE_CHECKOUT, 'label' => $h->__('Checkout only (allow browsing, block ordering)')],
        ];
    }
}
