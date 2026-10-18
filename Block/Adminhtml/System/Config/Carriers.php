<?php
/**
 * @copyright Copyright (c) CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\EInvoicingTr\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;

/**
 * Internet sales: carrier company (name, VKN/TCKN) per shipping carrier / tracking title.
 */
class Carriers extends AbstractFieldArray
{
    protected function _prepareToRender(): void
    {
        $this->addColumn('match', ['label' => __('Shipping Carrier or Word'), 'class' => 'admin__control-text', 'style' => 'width:110px']);
        $this->addColumn('name', ['label' => __('Carrier Company (on the invoice)'), 'class' => 'admin__control-text', 'style' => 'width:260px']);
        $this->addColumn('tax_id', ['label' => __('VKN / TCKN'), 'class' => 'admin__control-text validate-digits', 'style' => 'width:120px']);
        $this->_addAfter = false;
        $this->_addButtonLabel = (string)__('Add Carrier');
    }
}
