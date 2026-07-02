<?php

declare(strict_types=1);

/**
 * Maho
 *
 * @package    MageAustralia_B2bAccess
 * @copyright  Copyright (c) 2026 Mage Australia
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

class MageAustralia_B2bAccess_Block_Adminhtml_Rule_Grid extends Mage_Adminhtml_Block_Widget_Grid
{
    public function __construct()
    {
        parent::__construct();
        $this->setId('b2baccess_rule_grid');
        $this->setDefaultSort('priority');
        $this->setDefaultDir('ASC');
        $this->setSaveParametersInSession(true);
    }

    #[\Override]
    protected function _prepareCollection(): self
    {
        $this->setCollection(Mage::getResourceModel('b2baccess/rule_collection'));
        return parent::_prepareCollection();
    }

    #[\Override]
    protected function _prepareColumns(): self
    {
        $this->addColumn('rule_id', ['header' => $this->__('ID'), 'index' => 'rule_id', 'width' => '50px', 'type' => 'number']);
        $this->addColumn('name', ['header' => $this->__('Name'), 'index' => 'name']);
        $this->addColumn('priority', ['header' => $this->__('Priority'), 'index' => 'priority', 'type' => 'number', 'width' => '70px']);
        $this->addColumn('enforcement', [
            'header' => $this->__('Enforcement'),
            'index' => 'enforcement',
            'type' => 'options',
            'options' => [
                MageAustralia_B2bAccess_Model_Gate_Rule::ENFORCE_BOTH => $this->__('Both'),
                MageAustralia_B2bAccess_Model_Gate_Rule::ENFORCE_VISIBILITY => $this->__('Visibility'),
                MageAustralia_B2bAccess_Model_Gate_Rule::ENFORCE_CHECKOUT => $this->__('Checkout'),
            ],
            'width' => '110px',
        ]);
        $this->addColumn('action_hide_listing', ['header' => $this->__('Hide listing'), 'index' => 'action_hide_listing', 'type' => 'options', 'options' => [0 => $this->__('No'), 1 => $this->__('Yes')], 'width' => '80px']);
        $this->addColumn('action_hide_price', ['header' => $this->__('Hide price'), 'index' => 'action_hide_price', 'type' => 'options', 'options' => [0 => $this->__('No'), 1 => $this->__('Yes')], 'width' => '80px']);
        $this->addColumn('action_block_purchase', ['header' => $this->__('Block buy'), 'index' => 'action_block_purchase', 'type' => 'options', 'options' => [0 => $this->__('No'), 1 => $this->__('Yes')], 'width' => '80px']);
        $this->addColumn('is_active', [
            'header' => $this->__('Active'),
            'index' => 'is_active',
            'type' => 'options',
            'options' => [0 => $this->__('No'), 1 => $this->__('Yes')],
            'width' => '70px',
        ]);
        $this->addColumn('action', [
            'header' => $this->__('Action'),
            'width' => '80px',
            'type' => 'action',
            'getter' => 'getRuleId',
            'actions' => [[
                'caption' => $this->__('Edit'),
                'url' => ['base' => '*/*/edit'],
                'field' => 'id',
            ]],
            'filter' => false,
            'sortable' => false,
            'is_system' => true,
        ]);
        return parent::_prepareColumns();
    }

    #[\Override]
    protected function _prepareMassaction(): self
    {
        $this->setMassactionIdField('rule_id');
        $this->getMassactionBlock()->setFormFieldName('rule');

        $this->getMassactionBlock()->addItem('enable', [
            'label' => $this->__('Enable'),
            'url' => $this->getUrl('*/*/massStatus', ['status' => 1]),
        ]);
        $this->getMassactionBlock()->addItem('disable', [
            'label' => $this->__('Disable'),
            'url' => $this->getUrl('*/*/massStatus', ['status' => 0]),
        ]);
        $this->getMassactionBlock()->addItem('delete', [
            'label' => $this->__('Delete'),
            'url' => $this->getUrl('*/*/massDelete'),
            'confirm' => $this->__('Delete the selected rules?'),
        ]);
        return $this;
    }

    #[\Override]
    public function getRowUrl($row): string
    {
        return $this->getUrl('*/*/edit', ['id' => $row->getRuleId()]);
    }

    #[\Override]
    public function getGridUrl(): string
    {
        return $this->getUrl('*/*/grid', ['_current' => true]);
    }
}
