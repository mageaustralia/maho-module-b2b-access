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
 * Source model for the "Customer groups" multiselect. All real groups including
 * NOT LOGGED IN (guests), excluding the synthetic ALL group.
 */
class MageAustralia_B2bAccess_Model_System_CustomerGroups
{
    /**
     * @return list<array{value:int,label:string}>
     */
    public function toOptionArray(): array
    {
        $options = [];
        /** @var Mage_Customer_Model_Resource_Group_Collection $groups */
        $groups = Mage::getResourceModel('customer/group_collection')->setRealGroupsFilter();
        // setRealGroupsFilter() already drops the ALL (32000) group; add guest.
        $options[] = ['value' => Mage_Customer_Model_Group::NOT_LOGGED_IN_ID, 'label' => Mage::helper('customer')->__('NOT LOGGED IN')];
        foreach ($groups as $group) {
            $options[] = ['value' => (int) $group->getId(), 'label' => (string) $group->getCustomerGroupCode()];
        }
        return $options;
    }
}
