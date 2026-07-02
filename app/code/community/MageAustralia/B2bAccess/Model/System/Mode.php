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
 * Source model for the mode switch: Basic (config-driven single gate) vs Rules
 * (the b2baccess_rule table, managed in its own admin grid).
 */
class MageAustralia_B2bAccess_Model_System_Mode
{
    /**
     * @return list<array{value:string,label:string}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => MageAustralia_B2bAccess_Model_Gate::MODE_BASIC, 'label' => Mage::helper('b2baccess')->__('Basic (single gate)')],
            ['value' => MageAustralia_B2bAccess_Model_Gate::MODE_RULES, 'label' => Mage::helper('b2baccess')->__('Rules (multiple rules)')],
        ];
    }
}
