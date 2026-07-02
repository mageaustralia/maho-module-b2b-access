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
 * B2B access gate - public API for the enforcement layer (observers, checkout
 * guard, Meilisearch subscriber). Two independent concerns:
 *
 *  - Login wall: when enabled, guests are redirected to a CMS page (or the login
 *    page) on every frontend action except the auth/CMS surfaces they need to
 *    actually log in. Config-driven, no rules involved.
 *  - Visibility / price / purchase gate: delegated to
 *    {@see MageAustralia_B2bAccess_Model_Gate}, which resolves rules from either
 *    system config (basic mode) or the rule table (rules mode). This helper only
 *    supplies request context (current group, store, country) and forwards
 *    decisions.
 */
class MageAustralia_B2bAccess_Helper_Data extends Mage_Core_Helper_Abstract
{
    protected $_moduleName = 'MageAustralia_B2bAccess';

    public const XML_ENABLED         = 'b2baccess/general/enabled';
    public const XML_MODE            = 'b2baccess/general/mode';
    public const XML_LOGIN_REQUIRED  = 'b2baccess/login/required';
    public const XML_LOGIN_REDIRECT  = 'b2baccess/login/redirect_cms';
    public const XML_LOGIN_MESSAGE   = 'b2baccess/login/message';
    public const XML_HIDE_PRICE      = 'b2baccess/price/hide';
    public const XML_HIDE_LISTING    = 'b2baccess/price/hide_listing';
    public const XML_PRICE_MESSAGE   = 'b2baccess/price/message';
    public const XML_BLOCK_PURCHASE  = 'b2baccess/price/block_purchase';
    public const XML_BY_CUSTOMER     = 'b2baccess/matrix/by_customer';
    public const XML_CUSTOMER_GROUPS = 'b2baccess/matrix/customer_groups';
    public const XML_BY_CATEGORY     = 'b2baccess/matrix/by_category';
    public const XML_CATEGORIES      = 'b2baccess/matrix/categories';

    /** @var list<int>|null memoised list of every real customer group id incl. guest */
    private ?array $_allGroupIds = null;

    public function gate(): MageAustralia_B2bAccess_Model_Gate
    {
        /** @var MageAustralia_B2bAccess_Model_Gate $gate */
        $gate = Mage::getSingleton('b2baccess/gate');
        return $gate;
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return Mage::getStoreConfigFlag(self::XML_ENABLED, $storeId);
    }

    public function isLoggedIn(): bool
    {
        return Mage::getSingleton('customer/session')->isLoggedIn();
    }

    public function getCustomerGroupId(): int
    {
        return (int) Mage::getSingleton('customer/session')->getCustomerGroupId();
    }

    public function getCurrentStoreId(): int
    {
        return (int) Mage::app()->getStore()->getId();
    }

    /**
     * Best-effort current country for visibility-layer matching. A logged-in
     * customer's default shipping country is authoritative; otherwise we return
     * null ("unknown") and let country-scoped rules fall through to the checkout
     * guard, which always has a real shipping address. GeoIP, when present, can
     * be layered on later without changing callers.
     */
    public function getCurrentCountryCode(): ?string
    {
        if ($this->isLoggedIn()) {
            $address = Mage::getSingleton('customer/session')->getCustomer()->getDefaultShippingAddress();
            if ($address && $address->getCountryId()) {
                return strtoupper((string) $address->getCountryId());
            }
        }
        return null;
    }

    /* ---------------- login wall ---------------- */

    /** Guests are walled out of the storefront when this is on. */
    public function isLoginWallActive(?int $storeId = null): bool
    {
        return $this->isEnabled($storeId)
            && Mage::getStoreConfigFlag(self::XML_LOGIN_REQUIRED, $storeId)
            && !$this->isLoggedIn();
    }

    /**
     * Routes a guest must still reach with the wall up: every CMS page (so the
     * redirect target / login-required landing / home render) plus the customer
     * auth surfaces (login, register, forgot password, confirm) and the captcha
     * / social-login endpoints those depend on.
     */
    public function isRequestExempt(Mage_Core_Controller_Request_Http $request): bool
    {
        $route = strtolower((string) $request->getRouteName());
        if (in_array($route, ['cms', 'captcha', 'sociallogin', 'persistent'], true)) {
            return true;
        }
        if ($route === 'customer') {
            $action = strtolower((string) $request->getControllerName() . '/' . (string) $request->getActionName());
            $allowed = [
                'account/login', 'account/loginpost',
                'account/create', 'account/createpost',
                'account/forgotpassword', 'account/forgotpasswordpost',
                'account/confirm', 'account/confirmation',
                'account/logoutsuccess',
            ];
            return in_array($action, $allowed, true);
        }
        return false;
    }

    /** Where to send a walled-out guest (a CMS page identifier, else the login page). */
    public function getLoginRedirectUrl(): string
    {
        $cms = trim((string) Mage::getStoreConfig(self::XML_LOGIN_REDIRECT));
        if ($cms !== '') {
            return Mage::getUrl('', ['_direct' => $cms]);
        }
        return Mage::getUrl('customer/account/login');
    }

    public function getLoginMessage(): string
    {
        return trim((string) Mage::getStoreConfig(self::XML_LOGIN_MESSAGE));
    }

    /* ---------------- visibility / price / purchase gate ---------------- */

    /**
     * True when at least one in-force rule with the given action covers the
     * product for the current customer/country context. With no product context
     * (e.g. the listing toolbar) it answers the group-only catalog-wide question.
     */
    private function ruleActionApplies(string $action, ?Mage_Catalog_Model_Product $product): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }
        $storeId = $this->getCurrentStoreId();
        $groupId = $this->getCustomerGroupId();

        if (!$product instanceof Mage_Catalog_Model_Product) {
            return $this->gate()->groupGateApplies($storeId, $groupId, $action);
        }

        foreach ($this->gate()->matchingRules($product, $storeId, $groupId, $this->getCurrentCountryCode()) as $rule) {
            // Visibility actions (hide price/listing) require visibility
            // enforcement; blocking add-to-cart is a checkout action. This keeps
            // the Enforcement selector meaningful: a "checkout only" rule must
            // not hide prices while browsing, and a "visibility only" rule must
            // not block purchasing.
            if (($action === 'hide_price' && $rule->hidePrice && $rule->enforcesVisibility())
                || ($action === 'hide_listing' && $rule->hideListing && $rule->enforcesVisibility())
                || ($action === 'block_purchase' && $rule->blockPurchase && $rule->enforcesCheckout())
            ) {
                return true;
            }
        }
        return false;
    }

    public function shouldHidePrice(?Mage_Catalog_Model_Product $product = null): bool
    {
        return $this->ruleActionApplies('hide_price', $product);
    }

    public function shouldHideListing(?Mage_Catalog_Model_Product $product = null): bool
    {
        return $this->ruleActionApplies('hide_listing', $product);
    }

    public function shouldBlockPurchase(?Mage_Catalog_Model_Product $product = null): bool
    {
        return $this->ruleActionApplies('block_purchase', $product);
    }

    /**
     * Customer-group ids this product must be hidden from in Meilisearch, for the
     * given store. Delegates to the gate; see its docblock for the "any group"
     * expansion and why country scope is excluded.
     *
     * @return list<int>
     */
    public function getRestrictedGroupIdsForProduct(Mage_Catalog_Model_Product $product, int $storeId): array
    {
        if (!$this->isEnabled($storeId)) {
            return [];
        }
        return $this->gate()->getRestrictedGroupIds($product, $storeId);
    }

    /**
     * Message to show in place of a hidden price. A matching rule's own message
     * wins; otherwise the store-level default.
     */
    public function getPriceMessage(?Mage_Catalog_Model_Product $product = null): string
    {
        if ($product instanceof Mage_Catalog_Model_Product && $this->isEnabled()) {
            foreach ($this->gate()->matchingRules(
                $product,
                $this->getCurrentStoreId(),
                $this->getCustomerGroupId(),
                $this->getCurrentCountryCode(),
            ) as $rule) {
                if ($rule->hidePrice && $rule->enforcesVisibility() && $rule->message !== null && $rule->message !== '') {
                    return $rule->message;
                }
            }
        }
        return trim((string) Mage::getStoreConfig(self::XML_PRICE_MESSAGE));
    }

    /* ---------------- checkout guard ---------------- */

    /**
     * The destination country for a quote: the shipping address for physical
     * carts, falling back to the billing address for virtual/downloadable ones.
     * Null when neither carries a country yet.
     */
    public function getQuoteCountryCode(Mage_Sales_Model_Quote $quote): ?string
    {
        $address = $quote->isVirtual() ? $quote->getBillingAddress() : $quote->getShippingAddress();
        $country = $address ? (string) $address->getCountryId() : '';
        if ($country === '') {
            $country = (string) $quote->getBillingAddress()?->getCountryId();
        }
        return $country !== '' ? strtoupper($country) : null;
    }

    /**
     * Names of cart items that may not be purchased to the quote's destination
     * for its customer group. Empty when the order is allowed. Used by the
     * checkout guard to abort submission with a clear, specific message.
     *
     * @return list<string>
     */
    public function getBlockedQuoteItemNames(Mage_Sales_Model_Quote $quote): array
    {
        if (!$this->isEnabled((int) $quote->getStoreId())) {
            return [];
        }
        $storeId = (int) $quote->getStoreId();
        $groupId = (int) $quote->getCustomerGroupId();
        $country = $this->getQuoteCountryCode($quote);
        $gate = $this->gate();

        // Evaluate every item, including the child simples of configurable/bundle
        // lines: a rule can be scoped by product id to the child, which the parent
        // (configurable) product would not match. Report the customer-facing name,
        // which for a child is its parent line's name.
        $blocked = [];
        foreach ($quote->getAllItems() as $item) {
            /** @var Mage_Sales_Model_Quote_Item $item */
            $product = $item->getProduct();
            if (!$product instanceof Mage_Catalog_Model_Product) {
                continue;
            }
            if ($gate->isPurchaseBlockedAtCheckout($product, $storeId, $groupId, $country)) {
                $parent = $item->getParentItem();
                $name = (string) ($parent ? $parent->getName() : $item->getName());
                $blocked[$name] = $name;
            }
        }
        return array_values($blocked);
    }

    /* ---------------- config accessors (used by the gate) ---------------- */

    /**
     * Raw configured gated groups (basic mode). Unexpanded, as entered.
     *
     * @return list<int>
     */
    public function getGatedGroupIds(): array
    {
        return $this->csvInts((string) Mage::getStoreConfig(self::XML_CUSTOMER_GROUPS));
    }

    /**
     * Raw configured gated categories (basic mode). Descendant expansion is the
     * gate's job.
     *
     * @return list<int>
     */
    public function getConfiguredCategoryIds(): array
    {
        return $this->csvInts((string) Mage::getStoreConfig(self::XML_CATEGORIES));
    }

    /**
     * Every real customer group id, including NOT LOGGED IN (guests) and
     * excluding the synthetic ALL group. Used to expand an "any group" rule into
     * a concrete restricted-group list for the search index. Memoised.
     *
     * @return list<int>
     */
    public function getAllGroupIds(): array
    {
        if ($this->_allGroupIds !== null) {
            return $this->_allGroupIds;
        }
        $ids = [Mage_Customer_Model_Group::NOT_LOGGED_IN_ID];
        /** @var Mage_Customer_Model_Resource_Group_Collection $groups */
        $groups = Mage::getResourceModel('customer/group_collection')->setRealGroupsFilter();
        foreach ($groups as $group) {
            $ids[] = (int) $group->getId();
        }
        return $this->_allGroupIds = array_values(array_unique($ids));
    }

    /** @return list<int> */
    public function csvInts(string $csv): array
    {
        return array_values(array_unique(array_map(
            'intval',
            array_filter(array_map('trim', explode(',', $csv)), 'strlen'),
        )));
    }
}
