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
        $msg = $this->helper()->getPriceMessage();
        $transport->setHtml(
            '<span class="b2b-access__price-hidden">' . $this->helper()->escapeHtml($msg) . '</span>',
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
}
