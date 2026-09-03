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
        // Widths are set in CSS rather than inline: the table has to fit the configuration
        // form's value column, which is narrower than the three columns need.
        $this->addColumn('upper_bound', [
            'label' => __('Upper bound (USD, inclusive)'),
            'class' => 'validate-zero-or-greater',
        ]);
        $this->addColumn('brands', ['label' => __('Allowed card brands')]);
        $this->addColumn('message', ['label' => __('Customer message')]);

        $this->_addAfter = false;
        $this->_addButtonLabel = __('Add tier');
    }

    /**
     * Wraps the table in a class of our own so the stylesheet does not have to guess at the
     * ids Magento generates for configuration fields.
     */
    protected function _toHtml(): string
    {
        return '<div class="goodahead-tier-table">' . parent::_toHtml() . '</div>';
    }
}
