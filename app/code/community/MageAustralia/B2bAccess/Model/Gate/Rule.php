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
 * An immutable access rule as consumed by the enforcement layer. Both modes
 * yield these: basic mode synthesises one or two from system config; rules mode
 * builds one per row of `b2baccess_rule`. Enforcement (observers, Meilisearch
 * contribution, checkout guard) only ever talks to this value object, so the
 * two config sources never leak into the behaviour.
 *
 * Scope dimensions are AND-ed: a rule applies when the group AND store AND
 * country AND product all match. An empty scope list means "any" for that
 * dimension (e.g. empty groupIds = applies to every customer group).
 *
 * Product coverage is special: a rule with neither categories nor products is
 * "catalog-wide" and covers every product (this is how "hide all prices from
 * group X" is expressed). A rule with categories/products covers only the
 * union of those.
 */
class MageAustralia_B2bAccess_Model_Gate_Rule
{
    public const ENFORCE_VISIBILITY = 'visibility';
    public const ENFORCE_CHECKOUT   = 'checkout';
    public const ENFORCE_BOTH       = 'both';

    /**
     * @param int|string $id             identifier for logging (row id, or a "basic:*" token)
     * @param list<int>    $groupIds      customer groups in scope; [] = any group
     * @param list<int>    $storeIds      store ids in scope; [] or [0] = all stores
     * @param list<string> $countryCodes  ISO-3166-1 alpha-2 (upper); [] = any country
     * @param list<int>    $categoryIds   gated categories (already expanded to include descendants)
     * @param list<int>    $productIds    directly gated products
     * @param MageAustralia_B2bAccess_Model_Rule|null $ruleModel  live model (rules mode)
     *   carrying the condition tree. Basic-mode synthesised rules pass null and
     *   fall back to the flat categoryIds/productIds match.
     */
    public function __construct(
        public readonly int|string $id,
        public readonly string $name,
        public readonly array $groupIds,
        public readonly array $storeIds,
        public readonly array $countryCodes,
        public readonly array $categoryIds,
        public readonly array $productIds,
        public readonly bool $hideListing,
        public readonly bool $hidePrice,
        public readonly bool $blockPurchase,
        public readonly ?string $redirectCms,
        public readonly string $enforcement,
        public readonly ?string $message,
        public readonly int $priority = 0,
        public readonly ?MageAustralia_B2bAccess_Model_Rule $ruleModel = null,
    ) {}

    /**
     * True when this rule has no product/category scope and no condition tree,
     * so it covers every product. The condition-tree check reads from the live
     * model - a rule with only a tree ("brand = Head") is NOT catalog-wide.
     */
    public function isCatalogWide(): bool
    {
        if ($this->categoryIds !== [] || $this->productIds !== []) {
            return false;
        }
        return $this->ruleModel === null || !$this->ruleModel->hasConditions();
    }

    /**
     * True when this rule matches an individual product's attributes / SKU /
     * price / etc. via its condition tree. Rules without a tree return true
     * (they match every product; the fast path in {@see coversProduct()}
     * handles the flat category/product ID case).
     */
    public function matchesConditions(Mage_Catalog_Model_Product $product): bool
    {
        if ($this->ruleModel === null || !$this->ruleModel->hasConditions()) {
            return true;
        }
        return $this->ruleModel->matchesProduct($product);
    }

    public function matchesGroup(int $groupId): bool
    {
        return $this->groupIds === [] || in_array($groupId, $this->groupIds, true);
    }

    public function matchesStore(int $storeId): bool
    {
        // [] means any; [0] is the "all stores" sentinel used by the admin form.
        return $this->storeIds === []
            || in_array(0, $this->storeIds, true)
            || in_array($storeId, $this->storeIds, true);
    }

    /**
     * A null country means "unknown" (e.g. a guest we haven't geolocated). An
     * unscoped rule still matches unknown; a country-scoped rule does not, so we
     * never over-block on missing geo data at the visibility layer - the
     * checkout guard, which always has a shipping country, is the backstop.
     */
    public function matchesCountry(?string $code): bool
    {
        if ($this->countryCodes === []) {
            return true;
        }
        if ($code === null || $code === '') {
            return false;
        }
        return in_array(strtoupper($code), $this->countryCodes, true);
    }

    /**
     * Does this rule cover the given product?
     *
     * Three ways to cover:
     *   1. Catalog-wide (no flat scope, no condition tree) - matches everything.
     *   2. Flat listing - product id in productIds OR one of its categories in
     *      categoryIds.
     *   3. Condition tree - the rule model's tree evaluates true for this
     *      product (see {@see matchesConditions()}).
     *
     * Any of the three is enough. Callers that want a full match should also
     * call matchesConditions() when a Product object is available, because
     * this method receives only IDs and cannot evaluate attribute conditions.
     * The Gate calls both in sequence so the tree ANDs with the flat scope.
     *
     * @param list<int> $productCategoryIds the product's category ids
     */
    public function coversProduct(int $productId, array $productCategoryIds): bool
    {
        if ($this->isCatalogWide()) {
            return true;
        }
        // A rule with ONLY a condition tree (no flat products/categories) should
        // pass this fast-path check - the tree is evaluated separately by the
        // Gate. Otherwise a "brand = Head" rule would be rejected because it
        // has no flat scope.
        $hasFlatScope = $this->categoryIds !== [] || $this->productIds !== [];
        if (!$hasFlatScope) {
            return true;
        }
        if (in_array($productId, $this->productIds, true)) {
            return true;
        }
        return array_intersect($productCategoryIds, $this->categoryIds) !== [];
    }

    /** Rule participates in storefront/search visibility hiding. */
    public function enforcesVisibility(): bool
    {
        return $this->enforcement === self::ENFORCE_VISIBILITY
            || $this->enforcement === self::ENFORCE_BOTH;
    }

    /** Rule participates in the add-to-cart / checkout guard. */
    public function enforcesCheckout(): bool
    {
        return $this->enforcement === self::ENFORCE_CHECKOUT
            || $this->enforcement === self::ENFORCE_BOTH;
    }
}
