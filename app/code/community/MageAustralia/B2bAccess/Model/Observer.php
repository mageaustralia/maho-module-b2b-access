<?php

declare(strict_types=1);

use Maho\Config\Observer as MahoObserver;
use Maho\Event\Observer;

/**
 * Maho
 *
 * @package    MageAustralia_B2bAccess
 * @copyright  Copyright (c) 2026 Mage Australia
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

/**
 * Server-side enforcement of the B2B access gate. Every behaviour is here:
 *   - login wall (redirect guests),
 *   - hide price (rewrite the price block),
 *   - block purchase (reject add-to-cart),
 *   - drop the price sort option when prices are hidden.
 *
 * Observers are attribute-registered; run `composer dump-autoload` after install
 * so they compile into vendor/composer/maho_attributes.php.
 */
class MageAustralia_B2bAccess_Model_Observer
{
    private function helper(): MageAustralia_B2bAccess_Helper_Data
    {
        /** @var MageAustralia_B2bAccess_Helper_Data $h */
        $h = Mage::helper('b2baccess');
        return $h;
    }

    /**
     * Login wall: redirect guests to the configured CMS page (or login) on every
     * frontend action except the CMS/auth surfaces they need to log in.
     */
    #[MahoObserver('controller_action_predispatch', area: 'frontend', type: 'singleton')]
    public function loginWall(Observer $observer): void
    {
        $helper = $this->helper();
        if (!$helper->isLoginWallActive()) {
            return;
        }

        $action = $observer->getEvent()->getControllerAction();
        if (!$action instanceof Mage_Core_Controller_Varien_Action) {
            return;
        }
        if ($helper->isRequestExempt($action->getRequest())) {
            return;
        }

        // Remember where they were headed so login can return them there.
        $session = Mage::getSingleton('customer/session');
        $session->setBeforeAuthUrl(Mage::helper('core/url')->getCurrentUrl());
        $message = $helper->getLoginMessage();
        if ($message !== '') {
            $session->addNotice($message);
        }

        $action->getResponse()->setRedirect($helper->getLoginRedirectUrl());
        $action->setFlag('', Mage_Core_Controller_Varien_Action::FLAG_NO_DISPATCH, true);
    }

    /**
     * Hide price: replace a rendered price block with the configured message
     * when the activation matrix gates the current customer/product.
     */
    #[MahoObserver('core_block_abstract_to_html_after', area: 'frontend', type: 'singleton')]
    public function hidePrice(Observer $observer): void
    {
        $block = $observer->getEvent()->getBlock();
        if (!$block instanceof Mage_Catalog_Block_Product_Price) {
            return;
        }
        $product = $block->getProduct();
        $product = $product instanceof Mage_Catalog_Model_Product ? $product : null;
        if (!$this->helper()->shouldHidePrice($product)) {
            return;
        }
        $transport = $observer->getEvent()->getTransport();
        if (!$transport) {
            return;
        }
        $msg = $this->helper()->getPriceMessage($product);
        $transport->setHtml(
            '<span class="b2b-access__price-hidden">' . $this->helper()->escapeHtml($msg) . '</span>',
        );
    }

    /**
     * Hide listing: drop gated products from category / search product-list
     * collections before they load. Fires only for the product-list block's
     * collection (not every collection load), so admin grids, the cart, related
     * products etc. are untouched. When a catalog-wide rule matches, the whole
     * collection is emptied.
     */
    #[MahoObserver('catalog_block_product_list_collection', area: 'frontend', type: 'singleton')]
    public function hideListing(Observer $observer): void
    {
        $helper = $this->helper();
        if (!$helper->isEnabled()) {
            return;
        }
        $collection = $observer->getEvent()->getCollection();
        if (!$collection instanceof Mage_Catalog_Model_Resource_Product_Collection) {
            return;
        }

        $storeId = $helper->getCurrentStoreId();
        $groupId = $helper->getCustomerGroupId();
        $country = $helper->getCurrentCountryCode();
        $gate = $helper->gate();

        if ($gate->hidesEntireCatalog($storeId, $groupId, $country)) {
            $collection->getSelect()->where('1 = 0');
            return;
        }

        $hiddenIds = $gate->getHiddenProductIds($storeId, $groupId, $country);
        if ($hiddenIds !== []) {
            $collection->addFieldToFilter('entity_id', ['nin' => $hiddenIds]);
        }
    }

    /**
     * Contribute this rule set's group restrictions to a search index, so a
     * hidden product never surfaces in search for the wrong customer group. Fires
     * once per product per store during reindex, via the engine-neutral
     * `catalog_search_product_restrictions` event that every search backend
     * (Meilisearch, the pure-PHP Lucene engine, and any other) dispatches. No-op
     * when no search module is installed (the event never fires).
     */
    #[MahoObserver('catalog_search_product_restrictions', type: 'singleton')]
    public function contributeSearchRestrictions(Observer $observer): void
    {
        $product = $observer->getEvent()->getProduct();
        if (!$product instanceof Mage_Catalog_Model_Product) {
            return;
        }
        $transport = $observer->getEvent()->getTransport();
        if (!$transport) {
            return;
        }
        $storeId = (int) $observer->getEvent()->getStoreId();
        if (!$this->helper()->isEnabled($storeId)) {
            return;
        }

        $restricted = $this->helper()->getRestrictedGroupIdsForProduct($product, $storeId);
        if ($restricted === []) {
            return;
        }
        $existing = (array) $transport->getData('restricted_customer_group_ids');
        $transport->setData(
            'restricted_customer_group_ids',
            array_values(array_unique(array_merge($existing, $restricted))),
        );
    }

    /**
     * Drop the "price" sort option from the listing toolbar when prices are
     * hidden for this customer, so the storefront cannot expose price ordering.
     */
    #[MahoObserver('core_block_abstract_to_html_before', area: 'frontend', type: 'singleton')]
    public function dropPriceSort(Observer $observer): void
    {
        $block = $observer->getEvent()->getBlock();
        if (!$block instanceof Mage_Catalog_Block_Product_List_Toolbar) {
            return;
        }
        // Group-level gate (no product context here); covers the common
        // "hide prices from group X" case.
        if ($this->helper()->shouldHidePrice()) {
            $block->removeOrderFromAvailableOrders('price');
        }
    }

    /**
     * Block purchase server-side: reject add-to-cart for a gated product even if
     * the UI button was bypassed (crafted ?product=...&qty= URL).
     */
    #[MahoObserver('controller_action_predispatch_checkout_cart_add', area: 'frontend', type: 'singleton')]
    #[MahoObserver('controller_action_predispatch_checkout_cart_addgroup', area: 'frontend', type: 'singleton')]
    public function blockPurchase(Observer $observer): void
    {
        $action = $observer->getEvent()->getControllerAction();
        if (!$action instanceof Mage_Core_Controller_Varien_Action) {
            return;
        }
        $productId = (int) $action->getRequest()->getParam('product');
        if ($productId <= 0) {
            return;
        }
        /** @var Mage_Catalog_Model_Product $product */
        $product = Mage::getModel('catalog/product')->load($productId);
        if (!$product->getId() || !$this->helper()->shouldBlockPurchase($product)) {
            return;
        }

        Mage::getSingleton('checkout/session')->addError(
            (string) $this->helper()->__('This product is not available for purchase.'),
        );
        $referer = (string) $action->getRequest()->getServer('HTTP_REFERER', '');
        $action->getResponse()->setRedirect($referer !== '' ? $referer : Mage::getUrl(''));
        $action->setFlag('', Mage_Core_Controller_Varien_Action::FLAG_NO_DISPATCH, true);
    }

    /**
     * Checkout guard: the authoritative backstop for country-scoped rules. Fires
     * before any order is created (onepage, multishipping, admin, PayPal, ...),
     * so a destination-restricted product cannot be ordered even if it slipped
     * into the cart before a country was known. Aborts submission with a message
     * naming the offending item(s).
     */
    #[MahoObserver('sales_model_service_quote_submit_before', type: 'singleton')]
    public function guardCheckout(Observer $observer): void
    {
        $quote = $observer->getEvent()->getQuote();
        if (!$quote instanceof Mage_Sales_Model_Quote) {
            return;
        }
        $blocked = $this->helper()->getBlockedQuoteItemNames($quote);
        if ($blocked === []) {
            return;
        }
        Mage::throwException(
            (string) $this->helper()->__('This product is not available in your country.')
            . ' ' . implode(', ', $blocked),
        );
    }

    /**
     * Headless integration: annotate every product DTO built by the catalog API
     * with a `b2bAccess` extension block carrying the gate flags evaluated for
     * the current caller, and strip the price fields when hidePrice is on.
     *
     * The storefront reads `product.extensions.b2bAccess.gateFlags` and renders
     * the login prompt in place of the price / suppresses add-to-cart / redirects
     * on category-level gates. Because the price is not just flagged but
     * actually withheld from the response, a crafted client cannot recover it.
     *
     * See docs: reference/b2b-integration-pattern.
     */
    #[MahoObserver('api_product_dto_build', type: 'singleton')]
    public function annotateApiDto(Observer $observer): void
    {
        $helper = $this->helper();
        if (!$helper->isEnabled()) {
            return;
        }

        $product = $observer->getEvent()->getData('product');
        $dto = $observer->getEvent()->getData('dto');
        if (!$product instanceof Mage_Catalog_Model_Product || !is_object($dto)) {
            return;
        }

        // In API-Platform requests the caller identity is resolved from the JWT,
        // not the customer/session singleton, so the ProductProvider dispatches
        // this event with an explicit `customer_group_id`. Use it directly if
        // present; fall back to the session-based path only if a legacy caller
        // is dispatching without the group id (older core, tests).
        $callerGroupId = $observer->getEvent()->getData('customer_group_id');
        if (is_int($callerGroupId) || (is_string($callerGroupId) && $callerGroupId !== '')) {
            $gate = $helper->gate();
            $storeId = (int) Mage::app()->getStore()->getId();
            $groupId = (int) $callerGroupId;
            // Evaluate against THIS product. groupGateApplies() only looks at
            // catalog-wide rules, so using it here silently ignored every
            // category-scoped, product-scoped and condition-based rule: on a
            // headless store the entire rules engine did nothing, and only a
            // catalog-wide gate had any effect.
            $hidePrice = $gate->actionApplies($product, $storeId, $groupId, 'hide_price');
            $blockPurchase = $gate->actionApplies($product, $storeId, $groupId, 'block_purchase');
        } else {
            $hidePrice = $helper->shouldHidePrice($product);
            $blockPurchase = $helper->shouldBlockPurchase($product);
        }
        // Login-wall applies site-wide, not per-product; expose it so a
        // headless storefront can decide whether to redirect off category/PDP
        // pages when a guest lands on them.
        $requiresLogin = $helper->isLoginWallActive() && !$helper->isLoggedIn();

        $flags = [
            'requiresLogin' => $requiresLogin,
            'hidePrice'     => $hidePrice,
            'canCheckout'   => !$blockPurchase,
        ];

        // Guest vs logged-in-but-not-eligible: same gate (hidePrice=true) but
        // different UX. Guest gets "Log in to see pricing" → /login. Logged-in
        // customer gets "Trade customer pricing" → /trade-application. The
        // observer picks the right message + CTA per caller so the storefront
        // doesn't have to duplicate the "am I signed in" check at render time.
        $callerIsGuest = ($callerGroupId === null) || (int) $callerGroupId === Mage_Customer_Model_Group::NOT_LOGGED_IN_ID;
        if ($hidePrice) {
            if ($callerIsGuest) {
                $hiddenPriceMessage = $helper->getPriceMessage($product);
                $hiddenPriceCta = [
                    'label' => 'Log in',
                    'href'  => $helper->getPriceCtaHref(),
                ];
            } else {
                $hiddenPriceMessage = $helper->getPriceMessageForCustomer();
                $hiddenPriceCta = [
                    'label' => $helper->getPriceCtaLabelForCustomer(),
                    'href'  => $helper->getPriceCtaHrefForCustomer(),
                ];
            }
        } else {
            $hiddenPriceMessage = null;
            $hiddenPriceCta = null;
        }

        // Namespace under the module code so future B2B modules can add their
        // own extension blocks under their own keys (myPrice, orderApproval, ...).
        $existing = (array) ($dto->extensions ?? []);
        $existing['b2bAccess'] = [
            'gateFlags'          => $flags,
            'callerIsGuest'      => $callerIsGuest,
            'hiddenPriceMessage' => $hiddenPriceMessage,
            'hiddenPriceCta'     => $hiddenPriceCta,
        ];
        $dto->extensions = $existing;

        if ($hidePrice) {
            // The API is the enforcement boundary: withhold the price entirely,
            // not just flag it. A caller with the flags stripped still cannot
            // recover the price.
            foreach (['price', 'finalPrice', 'specialPrice', 'minimalPrice'] as $field) {
                if (property_exists($dto, $field)) {
                    $dto->{$field} = null;
                }
            }
        }
    }

    /**
     * Block purchase on ANY add-to-cart, headless included.
     *
     * blockPurchase() above only fires on the legacy frontend controllers
     * (controller_action_predispatch_checkout_cart_add, area: frontend). The
     * API Platform cart is a Symfony route and never dispatches those, so
     * `POST /api/rest/v2/guest-carts/{id}/items` walked straight past the gate:
     * a blocked product could be added, and the cart DTO then handed back the
     * very price the catalog API had just withheld.
     *
     * sales_quote_product_add_after lives in Mage_Sales_Model_Quote, so it fires
     * for the frontend, the API and the admin alike. Throwing here aborts the
     * add on every surface.
     *
     * The group comes from the quote, not the session: under a JWT the customer
     * session is empty, so a session lookup would read every API caller as a
     * guest and over-block logged-in trade customers.
     */
    #[MahoObserver('sales_quote_product_add_after', type: 'singleton')]
    public function blockGatedQuoteAdd(Observer $observer): void
    {
        $helper = $this->helper();
        if (!$helper->isEnabled()) {
            return;
        }

        $items = $observer->getEvent()->getData('items');
        if (!is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if (!$item instanceof Mage_Sales_Model_Quote_Item) {
                continue;
            }
            $quote = $item->getQuote();
            $product = $item->getProduct();
            if (!$quote instanceof Mage_Sales_Model_Quote || !$product instanceof Mage_Catalog_Model_Product) {
                continue;
            }

            if ($helper->gate()->actionApplies(
                $product,
                (int) $quote->getStoreId(),
                (int) $quote->getCustomerGroupId(),
                'block_purchase',
            )) {
                Mage::throwException(
                    (string) $helper->__('This product is not available for purchase.'),
                );
            }
        }
    }

    /**
     * Withhold prices on cart items the caller isn't allowed to see.
     *
     * Defence in depth behind blockGatedQuoteAdd(): a gated product should never
     * reach a cart in the first place, but one can already be sitting there --
     * added while the customer was in an eligible group, then left behind when
     * they logged out and the quote fell back to NOT_LOGGED_IN.
     *
     * The DTO's money fields are non-nullable floats, so they are zeroed rather
     * than nulled. Checkout is blocked for such a cart anyway (guardCheckout),
     * so a zeroed total is never charged.
     */
    #[MahoObserver('api_cart_item_dto_build', type: 'singleton')]
    public function gateApiCartItemDto(Observer $observer): void
    {
        $helper = $this->helper();
        if (!$helper->isEnabled()) {
            return;
        }

        $item = $observer->getEvent()->getData('item');
        $dto = $observer->getEvent()->getData('dto');
        if (!$item instanceof Mage_Sales_Model_Quote_Item || !is_object($dto)) {
            return;
        }

        $quote = $item->getQuote();
        $product = $item->getProduct();
        if (!$quote instanceof Mage_Sales_Model_Quote || !$product instanceof Mage_Catalog_Model_Product) {
            return;
        }

        if (!$helper->gate()->actionApplies(
            $product,
            (int) $quote->getStoreId(),
            (int) $quote->getCustomerGroupId(),
            'hide_price',
        )) {
            return;
        }

        foreach (['price', 'priceInclTax', 'rowTotal', 'rowTotalInclTax', 'rowTotalWithDiscount'] as $field) {
            if (property_exists($dto, $field)) {
                $dto->{$field} = 0.0;
            }
        }
        foreach (['discountAmount', 'discountPercent', 'taxAmount', 'taxPercent'] as $field) {
            if (property_exists($dto, $field)) {
                $dto->{$field} = null;
            }
        }
    }

    /**
     * Withhold the cart totals when the cart holds any price-gated item.
     *
     * Zeroing the line items alone still leaks: a single-item cart's grandTotal
     * is the price. Same reasoning as gateApiCartItemDto() -- such a cart cannot
     * be checked out, so the zeroed totals are never charged.
     */
    #[MahoObserver('api_cart_dto_build', type: 'singleton')]
    public function gateApiCartDto(Observer $observer): void
    {
        $helper = $this->helper();
        if (!$helper->isEnabled()) {
            return;
        }

        $quote = $observer->getEvent()->getData('quote');
        $dto = $observer->getEvent()->getData('dto');
        if (!$quote instanceof Mage_Sales_Model_Quote || !is_object($dto)) {
            return;
        }

        $gate = $helper->gate();
        $storeId = (int) $quote->getStoreId();
        $groupId = (int) $quote->getCustomerGroupId();

        $gated = false;
        foreach ($quote->getAllVisibleItems() as $item) {
            $product = $item->getProduct();
            if ($product instanceof Mage_Catalog_Model_Product
                && $gate->actionApplies($product, $storeId, $groupId, 'hide_price')
            ) {
                $gated = true;
                break;
            }
        }

        if (!$gated || !property_exists($dto, 'prices') || !is_array($dto->prices)) {
            return;
        }

        foreach (array_keys($dto->prices) as $key) {
            if (is_numeric($dto->prices[$key])) {
                $dto->prices[$key] = 0;
            }
        }
    }
}
