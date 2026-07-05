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
 * Rules-mode schema. Runs for every install the first time the b2baccess_setup
 * resource is seen (v1.0.0 shipped without a setup resource, so existing
 * installs pick this up as a fresh resource install). Basic mode does not use
 * these tables; they are read only when Mode = Rules.
 *
 * @var Mage_Core_Model_Resource_Setup $this
 */
$installer = $this;
$installer->startSetup();
$conn = $installer->getConnection();

$rule = $installer->getTable('b2baccess/rule');
if (!$conn->isTableExists($rule)) {
    $t = $conn->newTable($rule)
        ->addColumn('rule_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, ['identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true])
        ->addColumn('name', Varien_Db_Ddl_Table::TYPE_TEXT, 255, ['nullable' => false])
        ->addColumn('is_active', Varien_Db_Ddl_Table::TYPE_SMALLINT, null, ['nullable' => false, 'default' => 1])
        ->addColumn('priority', Varien_Db_Ddl_Table::TYPE_INTEGER, null, ['nullable' => false, 'default' => 0])
        ->addColumn('scope_group_ids', Varien_Db_Ddl_Table::TYPE_TEXT, '16k', ['nullable' => true], 'JSON list of customer group ids; empty = any')
        ->addColumn('scope_store_ids', Varien_Db_Ddl_Table::TYPE_TEXT, '16k', ['nullable' => true], 'JSON list of store ids; empty or [0] = all')
        ->addColumn('scope_country_codes', Varien_Db_Ddl_Table::TYPE_TEXT, '16k', ['nullable' => true], 'JSON list of ISO alpha-2 codes; empty = any')
        ->addColumn('scope_category_ids', Varien_Db_Ddl_Table::TYPE_TEXT, '64k', ['nullable' => true], 'JSON list of category ids (descendants expanded at match time)')
        ->addColumn('action_hide_listing', Varien_Db_Ddl_Table::TYPE_SMALLINT, null, ['nullable' => false, 'default' => 0])
        ->addColumn('action_hide_price', Varien_Db_Ddl_Table::TYPE_SMALLINT, null, ['nullable' => false, 'default' => 0])
        ->addColumn('action_block_purchase', Varien_Db_Ddl_Table::TYPE_SMALLINT, null, ['nullable' => false, 'default' => 0])
        ->addColumn('action_redirect_cms', Varien_Db_Ddl_Table::TYPE_TEXT, 255, ['nullable' => true], 'CMS page identifier to redirect gated PDP hits to')
        ->addColumn('enforcement', Varien_Db_Ddl_Table::TYPE_TEXT, 16, ['nullable' => false, 'default' => 'both'], 'visibility|checkout|both')
        ->addColumn('message', Varien_Db_Ddl_Table::TYPE_TEXT, '16k', ['nullable' => true], 'Per-rule hidden-price / blocked message override')
        ->addColumn('created_at', Varien_Db_Ddl_Table::TYPE_TIMESTAMP, null, ['nullable' => false, 'default' => Varien_Db_Ddl_Table::TIMESTAMP_INIT])
        // MySQL fires ON UPDATE on this column automatically; the Rule model's
        // _beforeSave() also stamps it explicitly so PgSQL and SQLite (which
        // downgrade TIMESTAMP_INIT_UPDATE to plain CURRENT_TIMESTAMP with no
        // on-update semantics) stay in sync with the MySQL behaviour.
        // @phpstan-ignore classConstant.deprecated
        ->addColumn('updated_at', Varien_Db_Ddl_Table::TYPE_TIMESTAMP, null, ['nullable' => false, 'default' => Varien_Db_Ddl_Table::TIMESTAMP_INIT_UPDATE])
        ->addIndex($installer->getIdxName($rule, ['is_active', 'priority']), ['is_active', 'priority'])
        ->setComment('B2B access rules');
    $conn->createTable($t);
}

$ruleProduct = $installer->getTable('b2baccess/rule_product');
if (!$conn->isTableExists($ruleProduct)) {
    $t = $conn->newTable($ruleProduct)
        ->addColumn('id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, ['identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true])
        ->addColumn('rule_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, ['unsigned' => true, 'nullable' => false])
        ->addColumn('product_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, ['unsigned' => true, 'nullable' => false])
        ->addIndex(
            $installer->getIdxName($ruleProduct, ['rule_id', 'product_id'], Varien_Db_Adapter_Interface::INDEX_TYPE_UNIQUE),
            ['rule_id', 'product_id'],
            ['type' => Varien_Db_Adapter_Interface::INDEX_TYPE_UNIQUE],
        )
        ->addIndex($installer->getIdxName($ruleProduct, ['product_id']), ['product_id'])
        ->addForeignKey(
            $installer->getFkName($ruleProduct, 'rule_id', $rule, 'rule_id'),
            'rule_id',
            $rule,
            'rule_id',
            Varien_Db_Ddl_Table::ACTION_CASCADE,
            Varien_Db_Ddl_Table::ACTION_CASCADE,
        )
        ->setComment('B2B access rule to product links');
    $conn->createTable($t);
}

$installer->endSetup();
