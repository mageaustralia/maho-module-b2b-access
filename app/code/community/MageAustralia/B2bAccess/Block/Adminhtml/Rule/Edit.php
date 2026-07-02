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
 * Edit container for an access rule: Save / Save and Continue / Delete / Back.
 */
class MageAustralia_B2bAccess_Block_Adminhtml_Rule_Edit extends Mage_Adminhtml_Block_Widget_Form_Container
{
    public function __construct()
    {
        $this->_blockGroup = 'b2baccess';
        $this->_controller = 'adminhtml_rule';
        $this->_objectId = 'id';

        parent::__construct();

        $this->_addButton('save_and_continue', [
            'label' => Mage::helper('b2baccess')->__('Save and Continue Edit'),
            'onclick' => "saveAndContinueEdit('{$this->getSaveAndContinueUrl()}')",
            'class' => 'save',
        ], 100);

        $this->_formScripts[] = '
            function saveAndContinueEdit(url) {
                editForm.submit(url);
            }
        ';
    }

    #[\Override]
    public function getHeaderText(): string
    {
        /** @var MageAustralia_B2bAccess_Model_Rule|null $rule */
        $rule = Mage::registry('b2baccess_rule');
        if ($rule && $rule->getId()) {
            return Mage::helper('b2baccess')->__('Edit Rule "%s"', $this->escapeHtml($rule->getName()));
        }
        return Mage::helper('b2baccess')->__('New Access Rule');
    }

    public function getSaveAndContinueUrl(): string
    {
        return $this->getUrl('*/*/save', ['_current' => true, 'back' => 'edit']);
    }
}
