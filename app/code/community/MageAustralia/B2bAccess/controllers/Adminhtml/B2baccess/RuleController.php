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
 * Admin CRUD for B2B access rules (rules mode). Routes are attribute-registered;
 * the adminhtml/b2baccess_rule frontName (config.xml) keeps menu links resolving.
 */
class MageAustralia_B2bAccess_Adminhtml_B2baccess_RuleController extends Mage_Adminhtml_Controller_Action
{
    public const ADMIN_RESOURCE = 'customer/b2baccess_rules';

    #[\Override]
    public function preDispatch()
    {
        $this->_setForcedFormKeyActions(['save', 'delete', 'massStatus', 'massDelete']);
        return parent::preDispatch();
    }

    #[\Override]
    protected function _isAllowed(): bool
    {
        // Must match the ACL node in adminhtml.xml (admin/customer/b2baccess_rules)
        // so a role granted this permission is actually honoured.
        return Mage::getSingleton('admin/session')->isAllowed('admin/customer/b2baccess_rules');
    }

    protected function _initAction(): self
    {
        $this->loadLayout()
            ->_setActiveMenu('customer/b2baccess_rules')
            ->_addBreadcrumb(Mage::helper('b2baccess')->__('B2B Access'), Mage::helper('b2baccess')->__('B2B Access'))
            ->_addBreadcrumb(Mage::helper('b2baccess')->__('Access Rules'), Mage::helper('b2baccess')->__('Access Rules'));
        return $this;
    }

    #[Maho\Config\Route('/admin/b2baccess_rule')]
    #[Maho\Config\Route('/admin/b2baccess_rule/index')]
    public function indexAction(): void
    {
        $this->_initAction()->renderLayout();
    }

    #[Maho\Config\Route('/admin/b2baccess_rule/grid')]
    public function gridAction(): void
    {
        $this->loadLayout(false);
        $this->renderLayout();
    }

    #[Maho\Config\Route('/admin/b2baccess_rule/new')]
    public function newAction(): void
    {
        $this->_forward('edit');
    }

    #[Maho\Config\Route('/admin/b2baccess_rule/edit')]
    public function editAction(): void
    {
        $id = (int) $this->getRequest()->getParam('id');
        /** @var MageAustralia_B2bAccess_Model_Rule $model */
        $model = Mage::getModel('b2baccess/rule');
        if ($id) {
            $model->load($id);
            if (!$model->getId()) {
                Mage::getSingleton('adminhtml/session')->addError($this->__('This rule no longer exists.'));
                $this->_redirect('*/*/');
                return;
            }
        }

        // Do not merge session form data into the model here: after a validation
        // error the posted values are in raw-post shape (multiselects as arrays,
        // categories as CSV), which the model's JSON accessors would misread. The
        // edit form peeks the session itself and prefers it verbatim.
        Mage::register('b2baccess_rule', $model);

        $this->_initAction()
            ->_addBreadcrumb(
                $id ? $this->__('Edit Rule') : $this->__('New Rule'),
                $id ? $this->__('Edit Rule') : $this->__('New Rule'),
            )
            ->renderLayout();
    }

    #[Maho\Config\Route('/admin/b2baccess_rule/save')]
    public function saveAction(): void
    {
        $data = $this->getRequest()->getPost();
        if (!$data) {
            $this->_redirect('*/*/');
            return;
        }

        try {
            $id = (int) ($data['rule_id'] ?? 0);
            /** @var MageAustralia_B2bAccess_Model_Rule $model */
            $model = Mage::getModel('b2baccess/rule');
            if ($id) {
                $model->load($id);
                if (!$model->getId()) {
                    Mage::throwException($this->__('This rule no longer exists.'));
                }
            }

            $name = trim((string) ($data['name'] ?? ''));
            if ($name === '') {
                Mage::throwException($this->__('Rule name is required.'));
            }
            $model->setName($name);
            $model->setIsActive((int) (bool) ($data['is_active'] ?? 0));
            $model->setPriority((int) ($data['priority'] ?? 0));

            // Scope: multiselects arrive as arrays; store as JSON int/code lists.
            $model->setData('scope_group_ids', $this->encodeIntList($data['scope_group_ids'] ?? []));
            $model->setData('scope_store_ids', $this->encodeIntList($data['scope_store_ids'] ?? []));
            $model->setData('scope_country_codes', $this->encodeCountryList($data['scope_country_codes'] ?? []));
            $model->setData('scope_category_ids', $this->encodeIntList($this->parseCsvInts($data['scope_category_ids'] ?? '')));

            // Actions.
            $model->setData('action_hide_listing', (int) (bool) ($data['action_hide_listing'] ?? 0));
            $model->setData('action_hide_price', (int) (bool) ($data['action_hide_price'] ?? 0));
            $model->setData('action_block_purchase', (int) (bool) ($data['action_block_purchase'] ?? 0));
            $redirect = trim((string) ($data['action_redirect_cms'] ?? ''));
            $model->setData('action_redirect_cms', $redirect !== '' ? $redirect : null);

            $enforcement = (string) ($data['enforcement'] ?? MageAustralia_B2bAccess_Model_Gate_Rule::ENFORCE_BOTH);
            $model->setData('enforcement', in_array($enforcement, [
                MageAustralia_B2bAccess_Model_Gate_Rule::ENFORCE_VISIBILITY,
                MageAustralia_B2bAccess_Model_Gate_Rule::ENFORCE_CHECKOUT,
                MageAustralia_B2bAccess_Model_Gate_Rule::ENFORCE_BOTH,
            ], true) ? $enforcement : MageAustralia_B2bAccess_Model_Gate_Rule::ENFORCE_BOTH);

            $message = trim((string) ($data['message'] ?? ''));
            $model->setData('message', $message !== '' ? $message : null);

            // Product scope: a textarea of SKUs and/or numeric ids.
            $model->setProductIdsArray($this->resolveProductTokens((string) ($data['product_tokens'] ?? '')));

            $model->save();

            Mage::getSingleton('adminhtml/session')->addSuccess($this->__('Rule saved.'));
            Mage::getSingleton('adminhtml/session')->setFormData(false);

            if ($this->getRequest()->getParam('back')) {
                $this->_redirect('*/*/edit', ['id' => $model->getId()]);
                return;
            }
            $this->_redirect('*/*/');
        } catch (Exception $e) {
            Mage::getSingleton('adminhtml/session')->addError($e->getMessage());
            Mage::getSingleton('adminhtml/session')->setFormData($data);
            $this->_redirect('*/*/edit', ['id' => (int) ($data['rule_id'] ?? 0)]);
        }
    }

    #[Maho\Config\Route('/admin/b2baccess_rule/delete')]
    public function deleteAction(): void
    {
        $id = (int) $this->getRequest()->getParam('id');
        if ($id) {
            try {
                Mage::getModel('b2baccess/rule')->load($id)->delete();
                Mage::getSingleton('adminhtml/session')->addSuccess($this->__('Rule deleted.'));
            } catch (Exception $e) {
                Mage::getSingleton('adminhtml/session')->addError($e->getMessage());
            }
        }
        $this->_redirect('*/*/');
    }

    #[Maho\Config\Route('/admin/b2baccess_rule/massStatus')]
    public function massStatusAction(): void
    {
        $ids = (array) $this->getRequest()->getParam('rule');
        $status = (int) (bool) $this->getRequest()->getParam('status');
        try {
            foreach ($ids as $id) {
                Mage::getModel('b2baccess/rule')->load((int) $id)->setIsActive($status)->save();
            }
            Mage::getSingleton('adminhtml/session')->addSuccess(
                $this->__('%d rule(s) updated.', count($ids)),
            );
        } catch (Exception $e) {
            Mage::getSingleton('adminhtml/session')->addError($e->getMessage());
        }
        $this->_redirect('*/*/');
    }

    #[Maho\Config\Route('/admin/b2baccess_rule/massDelete')]
    public function massDeleteAction(): void
    {
        $ids = (array) $this->getRequest()->getParam('rule');
        try {
            foreach ($ids as $id) {
                Mage::getModel('b2baccess/rule')->load((int) $id)->delete();
            }
            Mage::getSingleton('adminhtml/session')->addSuccess(
                $this->__('%d rule(s) deleted.', count($ids)),
            );
        } catch (Exception $e) {
            Mage::getSingleton('adminhtml/session')->addError($e->getMessage());
        }
        $this->_redirect('*/*/');
    }

    /* ---------------- save helpers ---------------- */

    /**
     * @param mixed $value
     */
    private function encodeIntList($value): string
    {
        $ids = [];
        foreach ((array) $value as $v) {
            $v = (int) $v;
            $ids[$v] = $v;
        }
        return json_encode(array_values($ids)) ?: '[]';
    }

    /**
     * @param mixed $value
     */
    private function encodeCountryList($value): string
    {
        $codes = [];
        foreach ((array) $value as $c) {
            $c = strtoupper(trim((string) $c));
            if ($c !== '') {
                $codes[$c] = $c;
            }
        }
        return json_encode(array_values($codes)) ?: '[]';
    }

    /**
     * @param mixed $value
     * @return list<int>
     */
    private function parseCsvInts($value): array
    {
        $out = [];
        foreach (preg_split('/[\s,]+/', (string) $value) ?: [] as $token) {
            $token = trim($token);
            if ($token !== '' && ctype_digit($token)) {
                $out[(int) $token] = (int) $token;
            }
        }
        return array_values($out);
    }

    /**
     * Resolve a free-text list of SKUs and/or numeric product ids to ids.
     * Numeric tokens are treated as ids; the rest are looked up by SKU in one
     * query. Unknown tokens are silently dropped.
     *
     * @return list<int>
     */
    private function resolveProductTokens(string $text): array
    {
        $tokens = array_filter(array_map('trim', preg_split('/[\s,]+/', $text) ?: []), 'strlen');
        if ($tokens === []) {
            return [];
        }

        $ids = [];
        $skus = [];
        foreach ($tokens as $token) {
            if (ctype_digit($token)) {
                $ids[(int) $token] = (int) $token;
            } else {
                $skus[] = $token;
            }
        }

        if ($skus !== []) {
            /** @var Mage_Catalog_Model_Resource_Product_Collection $collection */
            $collection = Mage::getResourceModel('catalog/product_collection')
                ->addAttributeToFilter('sku', ['in' => array_values(array_unique($skus))]);
            foreach ($collection->getAllIds() as $pid) {
                $ids[(int) $pid] = (int) $pid;
            }
        }

        return array_values($ids);
    }
}
