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
            'name' => 'enforcement', 'label' => $helper->__('Applies at'),
            'values' => (new MageAustralia_B2bAccess_Model_System_Enforcement())->toOptionArray(),
            'note' => $helper->__(
                'Where the rule fires: on the storefront (catalog / PDP / search), '
                . 'at checkout (add-to-cart guard), or both. Country-scoped rules '
                . 'usually want Checkout or Both because a guest\'s country is only '
                . 'known once they\'ve entered a shipping address.',
            ),
        ]);
        $general->addField('message', 'textarea', [
            'name' => 'message', 'label' => $helper->__('Custom "gated" message'),
            'style' => 'height:3em',
            'note' => $helper->__(
                'Optional. Overrides the store-wide hidden-price / blocked-purchase '
                . 'message for products matched by this rule.',
            ),
        ]);

        /* ---- Activation scope (all AND-ed; empty = any) ---- */
        $scope = $form->addFieldset('scope', [
            'legend' => $helper->__('Activation scope'),
            'comment' => $helper->__(
                'Where the rule fires. Customer groups, stores and destination '
                . 'countries are combined with AND - leave a list empty to match any '
                . 'value for that dimension. Which PRODUCTS the rule fires on is set '
                . 'in the "Product matching" section below.',
            ),
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
        // Same pattern as Mage_Adminhtml_Block_Promo_Catalog_Edit_Tab_Conditions.
        // Mage::getBlockSingleton() returns the layout singleton the fieldset
        // renderer needs, but returns false when there's no dispatched front
        // controller action (CLI). Fall back to direct instantiation for tests
        // and any oddball non-dispatched render path.
        $fieldsetRendererName = 'adminhtml/widget_form_renderer_fieldset';
        $fieldsetRenderer = Mage::getBlockSingleton($fieldsetRendererName)
            ?: $this->getLayout()->createBlock($fieldsetRendererName);
        $conditionsRenderer = $fieldsetRenderer
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

        // Mage_Rule_Block_Conditions is a Renderer, not a Block - it implements
        // RendererInterface directly. Layout::createBlock() rejects it as
        // "Invalid block type" because it doesn't extend Mage_Core_Block_Abstract.
        // Mage::getBlockSingleton() sidesteps that check with `new $className()`,
        // which works. If getBlockSingleton returns false (no FC action), fall
        // through to the same "new" call.
        $treeRenderer = Mage::getBlockSingleton('rule/conditions')
            ?: new Mage_Rule_Block_Conditions();

        $conditions->addField('conditions', 'text', [
            'name' => 'conditions',
            'label' => $helper->__('Product conditions'),
            'title' => $helper->__('Product conditions'),
            'required' => false,
        ])->setRule($rule)->setRenderer($treeRenderer);

        /* ---- What happens when the rule matches ---- */
        // Each row here is an independent switch, applied on top of the others.
        // Merchants read "Hide" as one thing, so we prefix each toggle with the
        // *scope* of what is hidden ("Product", "Price", "Purchase") to break
        // that ambiguity: "Hide product from listings" is not the same as
        // "Hide product price".
        $actions = $form->addFieldset('actions', [
            'legend' => $helper->__('When this rule matches, do the following'),
            'comment' => $helper->__(
                'Each toggle is independent - turn on the ones you want. The most '
                . 'common combination is <strong>Hide price = Yes + Block purchase = Yes</strong> '
                . '(price shows "Log in to see pricing"; add-to-cart is refused '
                . 'server-side).',
            ),
        ]);
        $actions->addField('action_hide_price', 'select', [
            'name' => 'action_hide_price',
            'label' => $helper->__('Hide product price'),
            'values' => $yesno,
            'note' => $helper->__(
                'The product is still browseable, but the price is replaced with the '
                . 'store default "Log in to see pricing" message (or the override above).',
            ),
        ]);
        $actions->addField('action_block_purchase', 'select', [
            'name' => 'action_block_purchase',
            'label' => $helper->__('Block add-to-cart'),
            'values' => $yesno,
            'note' => $helper->__(
                'Reject cart/checkout attempts server-side. Belt-and-braces on top of '
                . '"Hide product price" - a crafted URL cannot add the product to a cart.',
            ),
        ]);
        $actions->addField('action_hide_listing', 'select', [
            'name' => 'action_hide_listing',
            'label' => $helper->__('Remove product from catalog + search'),
            'values' => $yesno,
            'note' => $helper->__(
                'Stronger than hiding the price: the product disappears entirely '
                . 'from category listings, the search index and layered navigation. '
                . 'Requires a Meilisearch reindex to take effect in search.',
            ),
        ]);
        $actions->addField('action_redirect_cms', 'select', [
            'name' => 'action_redirect_cms',
            'label' => $helper->__('Redirect direct product URLs to'),
            'values' => $this->getCmsRedirectOptions(),
            'note' => $helper->__(
                'Optional. Where to send a visitor who hits a hidden product\'s URL '
                . 'directly (bookmark, deep link, ad landing page).',
            ),
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
