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
 * Resource for the access rule. Owns the b2baccess_rule_product join table:
 * loads a rule's product ids on read and rewrites them on save.
 */
class MageAustralia_B2bAccess_Model_Resource_Rule extends Mage_Core_Model_Resource_Db_Abstract
{
    #[\Override]
    protected function _construct(): void
    {
        $this->_init('b2baccess/rule', 'rule_id');
    }

    /**
     * @return list<int>
     */
    public function getProductIds(int $ruleId): array
    {
        $read = $this->_getReadAdapter();
        $select = $read->select()
            ->from($this->getTable('b2baccess/rule_product'), ['product_id'])
            ->where('rule_id = ?', $ruleId);
        return array_map('intval', $read->fetchCol($select));
    }

    /**
     * Populate the loaded model's product-id array from the join table.
     */
    #[\Override]
    protected function _afterLoad(Mage_Core_Model_Abstract $object)
    {
        if ($object->getId()) {
            /** @var MageAustralia_B2bAccess_Model_Rule $object */
            $object->setData('product_ids_array', $this->getProductIds((int) $object->getId()));
        }
        return parent::_afterLoad($object);
    }

    /**
     * Rewrite the rule's product links when it has an explicit product-id array.
     * A rule saved without touching products (no product_ids_array key) keeps its
     * existing links untouched.
     */
    #[\Override]
    protected function _afterSave(Mage_Core_Model_Abstract $object)
    {
        /** @var MageAustralia_B2bAccess_Model_Rule $object */
        if ($object->hasData('product_ids_array')) {
            $this->saveProductLinks((int) $object->getId(), $object->getProductIdsArray());
        }
        return parent::_afterSave($object);
    }

    /**
     * @param list<int> $productIds
     */
    public function saveProductLinks(int $ruleId, array $productIds): void
    {
        $write = $this->_getWriteAdapter();
        $table = $this->getTable('b2baccess/rule_product');
        $write->delete($table, ['rule_id = ?' => $ruleId]);

        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if ($productIds === []) {
            return;
        }
        $rows = [];
        foreach ($productIds as $pid) {
            $rows[] = ['rule_id' => $ruleId, 'product_id' => $pid];
        }
        $write->insertMultiple($table, $rows);
    }
}
