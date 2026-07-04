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
 * The access-rule edit form: three fieldsets (General, Scope, Actions). Scope
 * multiselects left empty mean "any" for that dimension. Product scope is a
 * SKU/id textarea that round-trips as SKUs.
 */
class MageAustralia_B2bAccess_Block_Adminhtml_Rule_Edit_Form extends Mage_Adminhtml_Block_Widget_Form
{
    #[\Override]
    protected function _prepareForm(): self
    {
        /** @var MageAustralia_B2bAccess_Model_Rule $rule */
        $rule = Mage::registry('b2baccess_rule');
        $helper = Mage::helper('b2baccess');

        $form = new Varien_Data_Form([
            'id' => 'edit_form',
            'action' => $this->getUrl('*/*/save'),
            'method' => 'post',
        ]);
        $form->setUseContainer(true);
        $this->setForm($form);

        $yesno = [['value' => 1, 'label' => $helper->__('Yes')], ['value' => 0, 'label' => $helper->__('No')]];

        /* ---- General ---- */
        $general = $form->addFieldset('general', ['legend' => $helper->__('General')]);
        if ($rule->getId()) {
            $general->addField('rule_id', 'hidden', ['name' => 'rule_id']);
        }
        $general->addField('name', 'text', [
            'name' => 'name', 'label' => $helper->__('Name'), 'required' => true,
        ]);
        $general->addField('is_active', 'select', [
            'name' => 'is_active', 'label' => $helper->__('Active'), 'values' => $yesno,
        ]);
        $general->addField('priority', 'text', [
            'name' => 'priority', 'label' => $helper->__('Priority'),
            'class' => 'validate-number',
            'note' => $helper->__('Lower numbers are evaluated first.'),
        ]);
        $general->addField('enforcement', 'select', [
            'name' => 'enforcement', 'label' => $helper->__('Enforcement'),
            'values' => (new MageAustralia_B2bAccess_Model_System_Enforcement())->toOptionArray(),
            'note' => $helper->__('Where the rule bites. Country rules usually want Checkout or Both.'),
        ]);
        $general->addField('message', 'textarea', [
            'name' => 'message', 'label' => $helper->__('Message override'),
            'style' => 'height:3em',
            'note' => $helper->__('Optional. Shown in place of a hidden price / on a blocked purchase. Falls back to the store default.'),
        ]);

        /* ---- Scope (all AND-ed; empty = any) ---- */
        $scope = $form->addFieldset('scope', [
            'legend' => $helper->__('Scope'),
            'comment' => $helper->__('All dimensions are combined with AND. Leave a list empty to match any value for that dimension.'),
        ]);
        $scope->addField('scope_group_ids', 'multiselect', [
            'name' => 'scope_group_ids[]', 'label' => $helper->__('Customer groups'),
            'values' => (new MageAustralia_B2bAccess_Model_System_CustomerGroups())->toOptionArray(),
            'can_be_empty' => true,
        ]);
        $scope->addField('scope_store_ids', 'multiselect', [
            'name' => 'scope_store_ids[]', 'label' => $helper->__('Stores'),
            'values' => (new MageAustralia_B2bAccess_Model_System_Stores())->toOptionArray(),
            'can_be_empty' => true,
        ]);
        $scope->addField('scope_country_codes', 'multiselect', [
            'name' => 'scope_country_codes[]', 'label' => $helper->__('Destination countries'),
            'values' => Mage::getModel('adminhtml/system_config_source_country')->toOptionArray(),
            'can_be_empty' => true,
            'note' => $helper->__('Matched against the shipping country at checkout.'),
        ]);
        // The flat category-ID / product-token fields are gone as of v1.2 -
        // product matching now lives in the condition tree below, which composes
        // categories, SKUs, attributes and price into a single expressive tree.
        // The scope_category_ids column + b2baccess_rule_product table remain
        // for backwards-compat with pre-v1.2 rules (see the Gate fallback path).

        /* ---- Product matching (Catalog-Rule-style condition tree) ---- */
        // Same pattern as Mage_Adminhtml_Block_Promo_Catalog_Edit_Tab_Conditions
        // - Mage::getBlockSingleton() returns the layout singleton the fieldset
        // renderer + rule/conditions widget need. Returns false only in the
        // absence of a dispatched front controller action (CLI); safe here
        // because this block only ever renders inside the admin request.
        $conditionsRenderer = Mage::getBlockSingleton('adminhtml/widget_form_renderer_fieldset')
            ->setTemplate('promo/fieldset.phtml')
            ->setNewChildUrl(
                $this->getUrl('*/*/newConditionHtml/form/conditions_fieldset'),
            );

        $conditions = $form->addFieldset('conditions_fieldset', [
            'legend' => $helper->__('Product matching (leave empty to match every product in scope)'),
            'comment' => $helper->__(
                'Reuses the standard Catalog Rule condition tree so you can match '
                . 'on attributes (brand = Head, colour = Black, ...), SKUs, price, '
                . 'category, or any combination. Empty tree = rule fires on every '
                . 'product that falls within the scope above.',
            ),
        ])->setRenderer($conditionsRenderer);

        $conditions->addField('conditions', 'text', [
            'name' => 'conditions',
            'label' => $helper->__('Product conditions'),
            'title' => $helper->__('Product conditions'),
            'required' => false,
        ])->setRule($rule)->setRenderer(Mage::getBlockSingleton('rule/conditions'));

        /* ---- Actions ---- */
        $actions = $form->addFieldset('actions', ['legend' => $helper->__('Actions')]);
        $actions->addField('action_hide_listing', 'select', [
            'name' => 'action_hide_listing', 'label' => $helper->__('Hide from listings and search'), 'values' => $yesno,
            'note' => $helper->__('Requires a Meilisearch reindex to take effect in search.'),
        ]);
        $actions->addField('action_hide_price', 'select', [
            'name' => 'action_hide_price', 'label' => $helper->__('Hide price'), 'values' => $yesno,
        ]);
        $actions->addField('action_block_purchase', 'select', [
            'name' => 'action_block_purchase', 'label' => $helper->__('Block purchase'), 'values' => $yesno,
        ]);
        $actions->addField('action_redirect_cms', 'select', [
            'name' => 'action_redirect_cms', 'label' => $helper->__('Redirect gated product page to'),
            'values' => $this->getCmsRedirectOptions(),
            'note' => $helper->__('Optional. Where a direct hit on a hidden product page is sent.'),
        ]);

        $form->setValues($this->getFormValues($rule));

        return parent::_prepareForm();
    }

    /**
     * Assemble form values from the model, converting stored JSON / join-table
     * data back into the shapes the fields expect.
     *
     * @return array<string, mixed>
     */
    private function getFormValues(MageAustralia_B2bAccess_Model_Rule $rule): array
    {
        // Session form data (after a validation error) wins so the admin's edits
        // are not lost. It is in raw-post shape (multiselects as arrays,
        // categories as CSV, products as the typed text), which is exactly what
        // the form fields expect. Peek then clear so it does not leak to the next
        // edit.
        $session = Mage::getSingleton('adminhtml/session')->getFormData();
        if (is_array($session) && $session !== []) {
            Mage::getSingleton('adminhtml/session')->setFormData(false);
            return $session;
        }

        if (!$rule->getId()) {
            return [
                'is_active' => 1,
                'enforcement' => MageAustralia_B2bAccess_Model_Gate_Rule::ENFORCE_BOTH,
                'action_block_purchase' => 1,
            ];
        }

        return [
            'rule_id' => $rule->getId(),
            'name' => $rule->getName(),
            'is_active' => (int) $rule->getIsActive(),
            'priority' => (int) $rule->getPriority(),
            'enforcement' => $rule->getEnforcementValue(),
            'message' => (string) $rule->getMessage(),
            'scope_group_ids' => $rule->getGroupIdsArray(),
            'scope_store_ids' => $rule->getStoreIdsArray(),
            'scope_country_codes' => $rule->getCountryCodesArray(),
            'action_hide_listing' => (int) $rule->getActionHideListing(),
            'action_hide_price' => (int) $rule->getActionHidePrice(),
            'action_block_purchase' => (int) $rule->getActionBlockPurchase(),
            'action_redirect_cms' => (string) $rule->getActionRedirectCms(),
        ];
    }

    /**
     * CMS-page options for the redirect field, with a blank "no redirect" first.
     *
     * @return list<array{value:string,label:string}>
     */
    private function getCmsRedirectOptions(): array
    {
        $options = [['value' => '', 'label' => Mage::helper('b2baccess')->__('-- No redirect --')]];
        foreach ((new MageAustralia_B2bAccess_Model_System_CmsPage())->toOptionArray() as $opt) {
            if ($opt['value'] === '') {
                continue; // drop the login-page sentinel from the login-wall source
            }
            $options[] = $opt;
        }
        return $options;
    }

}
