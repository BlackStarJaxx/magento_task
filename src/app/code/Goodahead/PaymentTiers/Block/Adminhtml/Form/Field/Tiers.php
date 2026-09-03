<?php

declare(strict_types=1);

namespace Goodahead\PaymentTiers\Block\Adminhtml\Form\Field;

use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;

/**
 * The tier table in system configuration.
 *
 * Plain text columns rather than brand multiselects: the brief puts "admin UI beyond a
 * system.xml configuration section" out of scope, and a multiselect inside a dynamic row
 * needs custom JavaScript to round-trip its value. The safety a multiselect would buy is
 * bought instead by strict validation in the backend model, which rejects unknown brands
 * with a readable error.
 */
class Tiers extends AbstractFieldArray
{
    protected function _prepareToRender(): void
    {
        $this->addColumn('upper_bound', [
            'label' => __('Upper bound (USD, inclusive)'),
            'class' => 'validate-zero-or-greater',
            'style' => 'width:120px',
        ]);
        $this->addColumn('brands', [
            'label' => __('Allowed card brands'),
            'style' => 'width:320px',
        ]);
        $this->addColumn('message', [
            'label' => __('Customer message'),
            'style' => 'width:420px',
        ]);

        $this->_addAfter = false;
        $this->_addButtonLabel = __('Add tier');
    }
}
