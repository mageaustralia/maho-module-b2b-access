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
 * Collection of access rules. Bulk-loads product links after load so building a
 * full rule set is a fixed number of queries regardless of rule count.
 */
class MageAustralia_B2bAccess_Model_Resource_Rule_Collection extends Mage_Core_Model_Resource_Db_Collection_Abstract
{
    #[\Override]
    protected function _construct(): void
    {
        $this->_init('b2baccess/rule');
    }

    public function addActiveFilter(): self
    {
        $this->addFieldToFilter('is_active', 1);
        return $this;
    }

    public function setPriorityOrder(): self
    {
        $this->setOrder('priority', self::SORT_ORDER_ASC);
        return $this;
    }

    /**
     * Attach each rule's product ids in one query rather than per-rule lazy loads.
     */
    #[\Override]
    protected function _afterLoad()
    {
        parent::_afterLoad();

        // Iterate the loaded items to collect IDs. Varien had a getLoadedIds()
        // helper that Maho dropped; iterating $this is the drop-in replacement
        // (items are already in memory at _afterLoad time, so no extra query).
        $ruleIds = [];
        foreach ($this as $rule) {
            $ruleIds[] = (int) $rule->getId();
        }
        if ($ruleIds === []) {
            return $this;
        }

        $byRule = [];
        foreach ($ruleIds as $id) {
            $byRule[$id] = [];
        }

        $read = $this->getConnection();
        $select = $read->select()
            ->from($this->getTable('b2baccess/rule_product'), ['rule_id', 'product_id'])
            ->where('rule_id IN (?)', $ruleIds);
        foreach ($read->fetchAll($select) as $row) {
            $byRule[(int) $row['rule_id']][] = (int) $row['product_id'];
        }

        foreach ($this as $rule) {
            /** @var MageAustralia_B2bAccess_Model_Rule $rule */
            $rule->setData('product_ids_array', $byRule[(int) $rule->getId()] ?? []);
        }

        return $this;
    }
}
