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
     * Contribute this rule set's group restrictions to the Meilisearch product
     * index. The search module dispatches `meilisearch_product_restrictions`
     * once per product per store during reindex; we push the customer-group ids
     * the product is hidden from so it never surfaces in search for them. No-op
     * when the search module isn't installed (the event simply never fires).
     */
    #[MahoObserver('meilisearch_product_restrictions', type: 'singleton')]
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
}
