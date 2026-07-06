<?php

/**
 * Maho
 *
 * @package    MageAustralia_B2bAccess
 * @copyright  Copyright (c) 2026 Mage Australia
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 *
 * Declarative equivalent of the legacy
 *   sql/b2baccess_setup/install-1.1.0.php
 *   sql/b2baccess_setup/upgrade-1.1.0-1.2.0.php
 *
 * Reconciles on `./maho migrate`. Legacy scripts remain for BC on fresh
 * installs of older Maho cores.
 *
 * IMPORTANT: unique constraints declared as UNIQUE INDEXES via
 * addUniqueIndex() rather than addUniqueConstraint(). DBAL's diff engine
 * treats UniqueConstraint objects and unique indexes as distinct metadata -
 * when the DB was created by a legacy CREATE TABLE statement, MySQL records
 * UNIQUE as a "unique index" (Non_unique=0) and DBAL comparing that against
 * an addUniqueConstraint declaration decides they don't match and tries to
 * DROP the index. FKs on the same column then reject the drop and the
 * migrate fails. addUniqueIndex matches the DB's actual metadata cleanly.
 */

declare(strict_types=1);

use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

return function (Schema $schema): void {
    $rule = $schema->createTable('b2baccess_rule');
    $rule->addColumn('rule_id', Types::INTEGER, ['unsigned' => true, 'autoincrement' => true]);
    $rule->addColumn('name', Types::STRING, ['length' => 255, 'notnull' => true]);
    $rule->addColumn('is_active', Types::SMALLINT, ['notnull' => true, 'default' => 1]);
    $rule->addColumn('priority', Types::INTEGER, ['notnull' => true, 'default' => 0]);
    $rule->addColumn('scope_group_ids', Types::TEXT, ['notnull' => false, 'comment' => 'JSON list of customer group ids; empty = any']);
    $rule->addColumn('scope_store_ids', Types::TEXT, ['notnull' => false, 'comment' => 'JSON list of store ids; empty or [0] = all']);
    $rule->addColumn('scope_country_codes', Types::TEXT, ['notnull' => false, 'comment' => 'JSON list of ISO alpha-2 codes; empty = any']);
    $rule->addColumn('scope_category_ids', Types::TEXT, ['notnull' => false, 'comment' => 'JSON list of category ids (descendants expanded at match time)']);
    $rule->addColumn('action_hide_listing', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
    $rule->addColumn('action_hide_price', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
    $rule->addColumn('action_block_purchase', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
    $rule->addColumn('action_redirect_cms', Types::STRING, ['length' => 255, 'notnull' => false, 'comment' => 'CMS page identifier to redirect gated PDP hits to']);
    $rule->addColumn('enforcement', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => 'both', 'comment' => 'visibility | checkout | both']);
    $rule->addColumn('message', Types::TEXT, ['notnull' => false, 'comment' => 'Per-rule hidden-price / blocked message override']);
    $rule->addColumn('created_at', Types::DATETIME_MUTABLE, ['notnull' => true, 'default' => 'CURRENT_TIMESTAMP']);
    $rule->addColumn('updated_at', Types::DATETIME_MUTABLE, ['notnull' => true, 'default' => 'CURRENT_TIMESTAMP', 'columnDefinition' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP']);
    $rule->addColumn('conditions_serialized', Types::TEXT, ['notnull' => false, 'comment' => 'Serialized Mage_Rule_Model_Condition_Combine tree (catalog-time product match)']);
    $rule->addColumn('actions_serialized', Types::TEXT, ['notnull' => false, 'comment' => 'Serialized actions tree (unused; kept because Mage_Rule persists it)']);
    $rule->addPrimaryKeyConstraint(
        PrimaryKeyConstraint::editor()->setUnquotedColumnNames('rule_id')->create(),
    );
    $rule->addIndex(['is_active', 'priority'], 'IDX_B2BACCESS_RULE_IS_ACTIVE_PRIORITY');
    $rule->setComment('B2B access rules');

    $rp = $schema->createTable('b2baccess_rule_product');
    $rp->addColumn('id', Types::INTEGER, ['unsigned' => true, 'autoincrement' => true]);
    $rp->addColumn('rule_id', Types::INTEGER, ['unsigned' => true, 'notnull' => true]);
    $rp->addColumn('product_id', Types::INTEGER, ['unsigned' => true, 'notnull' => true]);
    $rp->addPrimaryKeyConstraint(
        PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create(),
    );
    $rp->addUniqueIndex(['rule_id', 'product_id'], 'UNQ_B2BACCESS_RULE_PRODUCT_RULE_ID_PRODUCT_ID');
    $rp->addIndex(['product_id'], 'IDX_B2BACCESS_RULE_PRODUCT_PRODUCT_ID');
    $rp->addForeignKeyConstraint(
        'b2baccess_rule',
        ['rule_id'],
        ['rule_id'],
        ['onDelete' => 'CASCADE', 'onUpdate' => 'CASCADE'],
        'FK_B2BACCESS_RULE_PRODUCT_RULE_ID_B2BACCESS_RULE_RULE_ID',
    );
    $rp->setComment('B2B access rule to product links');
};
