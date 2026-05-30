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
 * B2B access gate decisions. Two independent gates, both server-side:
 *
 *  - Login wall: when enabled, guests are redirected to a CMS page (or the
 *    login page) on every frontend action except the auth/CMS surfaces they
 *    need to actually log in.
 *  - Price / purchase gate: an activation matrix - active when the current
 *    customer group is in the configured list OR the product sits in a gated
 *    category (subcategories inherit). When active, prices are replaced with a
 *    message and add-to-cart is rejected server-side.
 *
 * All config is store-scoped (Mage::getStoreConfig). No state; pure helper.
 */
class MageAustralia_B2bAccess_Helper_Data extends Mage_Core_Helper_Abstract
{
    protected $_moduleName = 'MageAustralia_B2bAccess';

    public const XML_ENABLED         = 'b2baccess/general/enabled';
    public const XML_LOGIN_REQUIRED  = 'b2baccess/login/required';
    public const XML_LOGIN_REDIRECT  = 'b2baccess/login/redirect_cms';
    public const XML_LOGIN_MESSAGE   = 'b2baccess/login/message';
    public const XML_HIDE_PRICE      = 'b2baccess/price/hide';
    public const XML_PRICE_MESSAGE   = 'b2baccess/price/message';
    public const XML_BLOCK_PURCHASE  = 'b2baccess/price/block_purchase';
    public const XML_BY_CUSTOMER     = 'b2baccess/matrix/by_customer';
    public const XML_CUSTOMER_GROUPS = 'b2baccess/matrix/customer_groups';
    public const XML_BY_CATEGORY     = 'b2baccess/matrix/by_category';
    public const XML_CATEGORIES      = 'b2baccess/matrix/categories';

    /** @var list<int>|null memoised gated category ids (incl. descendants) */
    private ?array $_gatedCategoryIds = null;

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

    /* ---------------- price / purchase gate ---------------- */

    /**
     * The activation matrix: true when prices/purchasing should be gated for the
     * current request. By-customer-group OR by-category (either is enough).
     */
    public function customerGateApplies(?Mage_Catalog_Model_Product $product = null): bool
    {
        if (Mage::getStoreConfigFlag(self::XML_BY_CUSTOMER)
            && in_array($this->getCustomerGroupId(), $this->getGatedGroupIds(), true)
        ) {
            return true;
        }
        if (Mage::getStoreConfigFlag(self::XML_BY_CATEGORY)
            && $product instanceof Mage_Catalog_Model_Product
            && $this->productInGatedCategory($product)
        ) {
            return true;
        }
        return false;
    }

    public function shouldHidePrice(?Mage_Catalog_Model_Product $product = null): bool
    {
        return $this->isEnabled()
            && Mage::getStoreConfigFlag(self::XML_HIDE_PRICE)
            && $this->customerGateApplies($product);
    }

    public function shouldBlockPurchase(?Mage_Catalog_Model_Product $product = null): bool
    {
        return $this->isEnabled()
            && Mage::getStoreConfigFlag(self::XML_BLOCK_PURCHASE)
            && $this->customerGateApplies($product);
    }

    public function getPriceMessage(): string
    {
        return trim((string) Mage::getStoreConfig(self::XML_PRICE_MESSAGE));
    }

    /** @return list<int> */
    public function getGatedGroupIds(): array
    {
        return $this->_csvInts((string) Mage::getStoreConfig(self::XML_CUSTOMER_GROUPS));
    }

    public function productInGatedCategory(Mage_Catalog_Model_Product $product): bool
    {
        $gated = $this->_getGatedCategoryIds();
        if ($gated === []) {
            return false;
        }
        $productCats = array_map('intval', (array) $product->getCategoryIds());
        return array_intersect($productCats, $gated) !== [];
    }

    /**
     * Configured gated categories expanded to include all descendants, so a
     * product in any subcategory of a gated category is gated too. Memoised.
     *
     * @return list<int>
     */
    private function _getGatedCategoryIds(): array
    {
        if ($this->_gatedCategoryIds !== null) {
            return $this->_gatedCategoryIds;
        }
        $set = [];
        foreach ($this->_csvInts((string) Mage::getStoreConfig(self::XML_CATEGORIES)) as $id) {
            $set[$id] = true;
            /** @var Mage_Catalog_Model_Category $cat */
            $cat = Mage::getModel('catalog/category')->load($id);
            if ($cat->getId()) {
                foreach ($this->_csvInts((string) $cat->getAllChildren()) as $child) {
                    $set[$child] = true;
                }
            }
        }
        return $this->_gatedCategoryIds = array_keys($set);
    }

    /** @return list<int> */
    private function _csvInts(string $csv): array
    {
        return array_values(array_unique(array_map(
            'intval',
            array_filter(array_map('trim', explode(',', $csv)), 'strlen'),
        )));
    }
}
