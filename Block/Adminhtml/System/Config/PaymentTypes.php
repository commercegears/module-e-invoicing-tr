<?php
/**
 * @copyright Copyright (c) CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\EInvoicingTr\Block\Adminhtml\System\Config;

use CommerceGears\EInvoicingCore\Block\Adminhtml\Form\Field\OptionsSelect;
use CommerceGears\EInvoicingTr\Model\InternetSale;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;
use Magento\Framework\DataObject;
use Magento\Payment\Helper\Data as PaymentHelper;

/**
 * Internet sales: Magento payment method → GİB payment type (+ payment intermediary, e.g. iyzico).
 */
class PaymentTypes extends AbstractFieldArray
{
    /** @var array<string, OptionsSelect> */
    private array $renderers = [];

    public function __construct(
        Context $context,
        private readonly PaymentHelper $paymentHelper,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    protected function _prepareToRender(): void
    {
        $methods = [];
        foreach ($this->paymentHelper->getPaymentMethodList(true) as $code => $title) {
            $methods[] = ['value' => (string)$code, 'label' => trim((string)$title) !== '' ? $title . ' (' . $code . ')' : (string)$code];
        }
        $this->addColumn('method', ['label' => __('Payment Method'), 'renderer' => $this->renderer('method', $methods)]);
        $this->addColumn('type', ['label' => __('GİB Payment Type'), 'renderer' => $this->renderer('type', array_map(
            static fn (string $type): array => ['value' => $type, 'label' => $type], InternetSale::TYPES))]);
        $this->addColumn('intermediary', ['label' => __('Payment Intermediary'), 'class' => 'admin__control-text']);
        $this->_addAfter = false;
        $this->_addButtonLabel = (string)__('Add Payment Method');
    }

    protected function _prepareArrayRow(DataObject $row): void
    {
        $options = [];
        foreach ($this->renderers as $column => $renderer) {
            $value = (string)$row->getData($column);
            if ($value !== '') {
                $options['option_' . $renderer->calcOptionHash($value)] = 'selected="selected"';
            }
        }
        $row->setData('option_extra_attrs', $options);
    }

    private function renderer(string $column, array $options): OptionsSelect
    {
        if (!isset($this->renderers[$column])) {
            /** @var OptionsSelect $renderer */
            $renderer = $this->getLayout()->createBlock(OptionsSelect::class, '', ['data' => ['is_render_to_js_template' => true]]);
            $renderer->setOptions($options)->setClass('admin__control-select');
            $this->renderers[$column] = $renderer;
        }
        return $this->renderers[$column];
    }
}
