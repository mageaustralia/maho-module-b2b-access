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
 * Container for the B2B Access Rules grid page (title + Add New button).
 */
class MageAustralia_B2bAccess_Block_Adminhtml_Rule extends Mage_Adminhtml_Block_Widget_Grid_Container
{
    public function __construct()
    {
        $this->_blockGroup = 'b2baccess';
        $this->_controller = 'adminhtml_rule';
        $this->_headerText = Mage::helper('b2baccess')->__('B2B Access Rules');
        $this->_addButtonLabel = Mage::helper('b2baccess')->__('Add New Rule');
        parent::__construct();
    }
}
