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
 * Source model for a rule's store-view multiselect. A leading "All Store Views"
 * option carries value 0 (the "all stores" sentinel the gate understands);
 * selecting nothing means "any store" too.
 */
class MageAustralia_B2bAccess_Model_System_Stores
{
    /**
     * @return list<array{value:int,label:string}>
     */
    public function toOptionArray(): array
    {
        $options = [['value' => 0, 'label' => Mage::helper('b2baccess')->__('All Store Views')]];
        /** @var Mage_Core_Model_Resource_Store_Collection $stores */
        $stores = Mage::getResourceModel('core/store_collection')
            ->addFieldToFilter('store_id', ['gt' => 0])
            ->setOrder('store_id', 'ASC');
        foreach ($stores as $store) {
            $options[] = [
                'value' => (int) $store->getId(),
                'label' => sprintf('%s (%s)', (string) $store->getName(), (string) $store->getCode()),
            ];
        }
        return $options;
    }
}
