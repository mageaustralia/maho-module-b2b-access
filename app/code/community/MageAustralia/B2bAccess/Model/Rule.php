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
 * A persisted B2B access rule (rules mode). Scope lists are stored as JSON in
 * single columns except products, which live in the b2baccess_rule_product join
 * table. {@see toGateRule()} converts a loaded row into the immutable value
 * object the enforcement layer consumes.
 *
 * @method string getName()
 * @method int getIsActive()
 * @method int getPriority()
 * @method string|null getScopeGroupIds()
 * @method string|null getScopeStoreIds()
 * @method string|null getScopeCountryCodes()
 * @method string|null getScopeCategoryIds()
 * @method int getActionHideListing()
 * @method int getActionHidePrice()
 * @method int getActionBlockPurchase()
 * @method string|null getActionRedirectCms()
 * @method string getEnforcement()
 * @method string|null getMessage()
 * @method $this setName(string $v)
 * @method $this setIsActive(int $v)
 * @method $this setPriority(int $v)
 */
class MageAustralia_B2bAccess_Model_Rule extends Mage_Core_Model_Abstract
{
    #[\Override]
    protected function _construct(): void
    {
        $this->_init('b2baccess/rule');
    }

    public function getRuleId(): ?int
    {
        $id = $this->getData('rule_id');
        return $id === null ? null : (int) $id;
    }

    /** @return list<int> */
    public function getGroupIdsArray(): array
    {
        return $this->jsonInts($this->getScopeGroupIds());
    }

    /** @return list<int> */
    public function getStoreIdsArray(): array
    {
        return $this->jsonInts($this->getScopeStoreIds());
    }

    /** @return list<string> */
    public function getCountryCodesArray(): array
    {
        $decoded = json_decode((string) ($this->getScopeCountryCodes() ?? '[]'), true);
        if (!is_array($decoded)) {
            return [];
        }
        $codes = [];
        foreach ($decoded as $c) {
            $c = strtoupper(trim((string) $c));
            if ($c !== '') {
                $codes[$c] = $c;
            }
        }
        return array_values($codes);
    }

    /** @return list<int> */
    public function getCategoryIdsArray(): array
    {
        return $this->jsonInts($this->getScopeCategoryIds());
    }

    /**
     * Product ids linked via the join table. Set in bulk by the collection when
     * loading many rules; otherwise loaded on demand for a single rule.
     *
     * @return list<int>
     */
    public function getProductIdsArray(): array
    {
        if ($this->hasData('product_ids_array')) {
            return array_map('intval', (array) $this->getData('product_ids_array'));
        }
        $ids = $this->getRuleId() !== null
            ? $this->_getResource()->getProductIds($this->getRuleId())
            : [];
        $this->setData('product_ids_array', $ids);
        return $ids;
    }

    /**
     * @param list<int> $ids
     */
    public function setProductIdsArray(array $ids): self
    {
        $this->setData('product_ids_array', array_values(array_unique(array_map('intval', $ids))));
        return $this;
    }

    public function getEnforcementValue(): string
    {
        return in_array($this->getEnforcement(), [
            MageAustralia_B2bAccess_Model_Gate_Rule::ENFORCE_VISIBILITY,
            MageAustralia_B2bAccess_Model_Gate_Rule::ENFORCE_CHECKOUT,
            MageAustralia_B2bAccess_Model_Gate_Rule::ENFORCE_BOTH,
        ], true)
            ? (string) $this->getEnforcement()
            : MageAustralia_B2bAccess_Model_Gate_Rule::ENFORCE_BOTH;
    }

    /**
     * Convert to the immutable enforcement rule. Categories are expanded to
     * include descendants (via the gate, which memoises the tree walk).
     */
    public function toGateRule(MageAustralia_B2bAccess_Model_Gate $gate): MageAustralia_B2bAccess_Model_Gate_Rule
    {
        $redirect = trim((string) ($this->getActionRedirectCms() ?? ''));
        $message = trim((string) ($this->getMessage() ?? ''));

        return new MageAustralia_B2bAccess_Model_Gate_Rule(
            id: $this->getRuleId() ?? 'unsaved',
            name: (string) $this->getName(),
            groupIds: $this->getGroupIdsArray(),
            storeIds: $this->getStoreIdsArray(),
            countryCodes: $this->getCountryCodesArray(),
            categoryIds: $gate->expandCategoryIds($this->getCategoryIdsArray()),
            productIds: $this->getProductIdsArray(),
            hideListing: (bool) $this->getActionHideListing(),
            hidePrice: (bool) $this->getActionHidePrice(),
            blockPurchase: (bool) $this->getActionBlockPurchase(),
            redirectCms: $redirect !== '' ? $redirect : null,
            enforcement: $this->getEnforcementValue(),
            message: $message !== '' ? $message : null,
            priority: (int) $this->getPriority(),
        );
    }

    /**
     * @param string|null $json
     * @return list<int>
     */
    private function jsonInts(?string $json): array
    {
        $decoded = json_decode((string) ($json ?? '[]'), true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $v) {
            $v = (int) $v;
            $out[$v] = $v;
        }
        return array_values($out);
    }

    /** @return MageAustralia_B2bAccess_Model_Resource_Rule */
    protected function _getResource()
    {
        /** @var MageAustralia_B2bAccess_Model_Resource_Rule $resource */
        $resource = parent::_getResource();
        return $resource;
    }
}
