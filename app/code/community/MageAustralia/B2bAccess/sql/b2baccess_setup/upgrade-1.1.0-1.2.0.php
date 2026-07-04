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
 * v1.2.0 - condition-tree matching.
 *
 * Adds the two serialized-conditions columns Maho's Mage_Rule_Model_Abstract
 * expects (conditions_serialized, actions_serialized), so B2B Access rules can
 * carry a Catalog-Rule-style product-matching tree (attribute = value, SKU in
 * (...), category in (...), price > N, custom attributes, whatever).
 *
 * The existing flat scope columns (scope_group_ids, scope_store_ids,
 * scope_country_codes, scope_category_ids) stay. Those are activation scope,
 * not product matching:
 *   - groups / stores / countries - where the rule fires (checkout guard reads
 *     scope_country_codes against the shipping address);
 *   - conditions_serialized - which products it fires on (catalog-time).
 *
 * scope_category_ids stays for back-compat with rules that predate this column;
 * new rules should express category filtering inside the condition tree
 * ("Category IN (...)") which composes with product attributes.
 *
 * The b2baccess_rule_product link table also stays. Rules created before this
 * upgrade will keep their product membership; the Gate evaluator falls back to
 * that table when a rule has no conditions_serialized.
 *
 * @var Mage_Core_Model_Resource_Setup $this
 */
$installer = $this;
$installer->startSetup();
$conn = $installer->getConnection();
$rule = $installer->getTable('b2baccess/rule');

if (!$conn->tableColumnExists($rule, 'conditions_serialized')) {
    $conn->addColumn($rule, 'conditions_serialized', [
        'type'     => Varien_Db_Ddl_Table::TYPE_TEXT,
        'length'   => '2M',
        'nullable' => true,
        'comment'  => 'Serialized Mage_Rule_Model_Condition_Combine tree (catalog-time product match)',
    ]);
}

if (!$conn->tableColumnExists($rule, 'actions_serialized')) {
    // Mage_Rule_Model_Abstract::_beforeSave() serializes both trees; store the
    // (unused) actions column too so we do not need to override that method.
    $conn->addColumn($rule, 'actions_serialized', [
        'type'     => Varien_Db_Ddl_Table::TYPE_TEXT,
        'length'   => '2M',
        'nullable' => true,
        'comment'  => 'Serialized actions tree (unused; kept because Mage_Rule persists it)',
    ]);
}

$installer->endSetup();
