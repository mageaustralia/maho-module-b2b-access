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
 * One-shot importer from Amasty Customer Group Catalog (am_groupcat_rules /
 * am_groupcat_product) into b2baccess_rule rows, for merchants migrating a
 * Magento 1 store onto Maho. Idempotent: rules are named "Groupcat: <name>" and
 * an existing rule with that name is skipped, so a re-run only adds what is new.
 *
 * Mapping:
 *   rule_name            -> name ("Groupcat: <rule_name>")
 *   enable               -> is_active
 *   cust_groups (CSV)    -> scope_group_ids  (Amasty: which groups are blocked)
 *   stores (CSV, 0=all)  -> scope_store_ids
 *   categories (CSV)     -> scope_category_ids
 *   remove_product_links -> action_hide_listing
 *   hide_price           -> action_hide_price
 *   (always)             -> action_block_purchase = 1, enforcement = both
 *   am_groupcat_product  -> rule_product links
 */
class MageAustralia_B2bAccess_Model_Import_Groupcat
{
    public const RULE_TABLE    = 'am_groupcat_rules';
    public const PRODUCT_TABLE = 'am_groupcat_product';
    public const NAME_PREFIX   = 'Groupcat: ';

    /**
     * @return array{available:bool,imported:int,skipped:int,products:int,rules:list<string>}
     */
    public function import(bool $dryRun = false): array
    {
        $result = ['available' => false, 'imported' => 0, 'skipped' => 0, 'products' => 0, 'rules' => []];

        /** @var Mage_Core_Model_Resource $resource */
        $resource = Mage::getSingleton('core/resource');
        $read = $resource->getConnection('core_read');

        if (!$read->isTableExists(self::RULE_TABLE)) {
            return $result; // Amasty tables not present; nothing to import.
        }
        $result['available'] = true;

        $rows = $read->fetchAll($read->select()->from(self::RULE_TABLE)->order('rule_id ASC'));

        $hasProductTable = $read->isTableExists(self::PRODUCT_TABLE);

        foreach ($rows as $priority => $row) {
            $name = self::NAME_PREFIX . (string) ($row['rule_name'] ?? ('rule ' . ($row['rule_id'] ?? '?')));

            if ($this->ruleExists($name)) {
                $result['skipped']++;
                continue;
            }

            $productIds = [];
            if ($hasProductTable && isset($row['rule_id'])) {
                $productIds = array_map('intval', $read->fetchCol(
                    $read->select()
                        ->from(self::PRODUCT_TABLE, ['product_id'])
                        ->where('rule_id = ?', (int) $row['rule_id']),
                ));
            }

            $data = [
                'name' => $name,
                'is_active' => (int) (bool) ($row['enable'] ?? 0),
                'priority' => $priority,
                'scope_group_ids' => json_encode($this->csvInts((string) ($row['cust_groups'] ?? ''))),
                'scope_store_ids' => json_encode($this->csvInts((string) ($row['stores'] ?? ''))),
                'scope_country_codes' => json_encode([]),
                'scope_category_ids' => json_encode($this->csvInts((string) ($row['categories'] ?? ''))),
                'action_hide_listing' => (int) (bool) ($row['remove_product_links'] ?? 0),
                'action_hide_price' => (int) (bool) ($row['hide_price'] ?? 0),
                'action_block_purchase' => 1,
                'action_redirect_cms' => null,
                'enforcement' => MageAustralia_B2bAccess_Model_Gate_Rule::ENFORCE_BOTH,
                'message' => null,
            ];

            $result['rules'][] = $name;
            $result['products'] += count($productIds);

            if (!$dryRun) {
                /** @var MageAustralia_B2bAccess_Model_Rule $rule */
                $rule = Mage::getModel('b2baccess/rule');
                $rule->addData($data);
                $rule->setProductIdsArray($productIds);
                $rule->save();
            }
            $result['imported']++;
        }

        return $result;
    }

    private function ruleExists(string $name): bool
    {
        $collection = Mage::getResourceModel('b2baccess/rule_collection')
            ->addFieldToFilter('name', $name)
            ->setPageSize(1);
        return $collection->getSize() > 0;
    }

    /**
     * Amasty stores CSV as ",0,1,14," with surrounding commas.
     *
     * @return list<int>
     */
    private function csvInts(string $csv): array
    {
        $out = [];
        foreach (explode(',', $csv) as $token) {
            $token = trim($token);
            if ($token !== '' && ctype_digit($token)) {
                $out[(int) $token] = (int) $token;
            }
        }
        return array_values($out);
    }
}
