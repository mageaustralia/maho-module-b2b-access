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
 * Source model for the "redirect guests to" dropdown. Values are CMS page
 * identifiers (so the gate can build a {{store}}-relative direct URL); a blank
 * first option falls back to the login page.
 */
class MageAustralia_B2bAccess_Model_System_CmsPage
{
    /**
     * @return list<array{value:string,label:string}>
     */
    public function toOptionArray(): array
    {
        $options = [['value' => '', 'label' => Mage::helper('b2baccess')->__('-- Login page --')]];
        /** @var Mage_Cms_Model_Resource_Page_Collection $pages */
        $pages = Mage::getResourceModel('cms/page_collection')
            ->addFieldToFilter('is_active', 1)
            ->setOrder('title', 'ASC');
        foreach ($pages as $page) {
            $options[] = [
                'value' => (string) $page->getIdentifier(),
                'label' => sprintf('%s (%s)', (string) $page->getTitle(), (string) $page->getIdentifier()),
            ];
        }
        return $options;
    }
}
