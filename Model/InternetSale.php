<?php
/**
 * @copyright Copyright (c) CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\EInvoicingTr\Model;

use CommerceGears\EInvoicingCore\Model\Config;
use CommerceGears\EInvoicingCore\Model\Data\Invoice;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\UrlInterface;
use Magento\Sales\Api\InvoiceRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice as MagentoInvoice;
use Magento\Sales\Model\ResourceModel\Order\Shipment\CollectionFactory as ShipmentCollectionFactory;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Internet sale details GİB requires on invoices of online sales (VUK General Communiqué 509, IV.2.4.5; e-Arşiv
 * technical guide, "internetSatisBilgi"): web address, payment method and date, the carrier (name and VKN/TCKN) and
 * the shipment date (for services: the date the service was performed).
 */
class InternetSale
{
    public const CARD = 'KREDIKARTI/BANKAKARTI';
    public const TRANSFER = 'EFT/HAVALE';
    public const CASH_ON_DELIVERY = 'KAPIDAODEME';
    public const INTERMEDIARY = 'ODEMEARACISI';
    public const OTHER = 'DIGER';
    public const TYPES = [self::CARD, self::TRANSFER, self::CASH_ON_DELIVERY, self::INTERMEDIARY, self::OTHER];
    /** Payment types for which GİB requires the payment date. */
    public const DATE_REQUIRED = [self::CARD, self::TRANSFER, self::INTERMEDIARY];

    /**
     * Built-in payment type per Magento payment method code (overridden by the "Payment Types" setting).
     * [type, payment intermediary]
     */
    private const DEFAULT_TYPES = [
        'banktransfer' => [self::TRANSFER, ''],
        'checkmo' => [self::TRANSFER, ''],
        'cashondelivery' => [self::CASH_ON_DELIVERY, ''],
        'free' => [self::OTHER, ''],
        'purchaseorder' => [self::OTHER, ''],
        'paypal_express' => [self::INTERMEDIARY, 'PayPal'],
        'paypal_express_bml' => [self::INTERMEDIARY, 'PayPal'],
        'payflow_express' => [self::INTERMEDIARY, 'PayPal'],
        'braintree' => [self::CARD, 'Braintree'],
        'braintree_cc_vault' => [self::CARD, 'Braintree'],
        'braintree_paypal' => [self::INTERMEDIARY, 'PayPal'],
        'braintree_paypal_vault' => [self::INTERMEDIARY, 'PayPal'],
        'payflowpro' => [self::CARD, 'PayPal'],
        'stripe_payments' => [self::CARD, 'Stripe'],
        'iyzipay' => [self::CARD, 'iyzico'],
        'iyzico' => [self::CARD, 'iyzico'],
        'paytr_checkout' => [self::CARD, 'PayTR'],
        'paytr' => [self::CARD, 'PayTR'],
        'adyen_cc' => [self::CARD, 'Adyen'],
        'mollie_methods_creditcard' => [self::CARD, 'Mollie'],
    ];

    public function __construct(
        private readonly Config $config,
        private readonly Json $json,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly InvoiceRepositoryInterface $invoiceRepository,
        private readonly ShipmentCollectionFactory $shipmentCollectionFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly TimezoneInterface $timezone
    ) {
    }

    public function isEnabled(int $storeId): bool
    {
        // default Yes (config.xml); an explicit "No" turns the internet sale details off
        return $this->config->getValue('tr/internet_sale', $storeId) !== '0';
    }

    /**
     * Details of the sale behind this invoice.
     *
     * @return array{website: string, payment_type: string, payment_title: string, intermediary: string,
     *     payment_date: string, goods: bool, shipping: string, carrier_name: string, carrier_id: string, despatch_date: string}
     */
    public function collect(Invoice $invoice, int $storeId): array
    {
        /** @var Order $order */
        $order = $this->orderRepository->get($invoice->orderId);
        $method = $order->getPayment() ? (string)$order->getPayment()->getMethod() : '';
        $title = '';
        try {
            $title = $order->getPayment() ? (string)$order->getPayment()->getMethodInstance()->getTitle() : '';
        } catch (\Throwable) {
            $title = $method;
        }
        [$type, $intermediary] = $this->paymentType($method, $storeId);
        $goods = !$order->getIsVirtual();
        $shipping = $goods ? $this->shipping($order, $storeId) : ['label' => '', 'date' => '', 'carrier' => ['', '']];
        return [
            'website' => $this->website($storeId),
            'payment_type' => $type,
            'payment_title' => $title ?: $method,
            'intermediary' => $intermediary,
            'payment_date' => $this->paymentDate($invoice),
            'goods' => $goods,
            'shipping' => $shipping['label'],
            'carrier_name' => $shipping['carrier'][0],
            'carrier_id' => $shipping['carrier'][1],
            // services and downloads: the date the service was performed (the invoice date)
            'despatch_date' => $goods ? $shipping['date'] : $invoice->issueDate,
        ];
    }

    /**
     * GİB payment type and payment intermediary of a Magento payment method.
     *
     * @return array{0: string, 1: string}
     */
    public function paymentType(string $method, int $storeId): array
    {
        foreach ($this->rows('tr/payment_types', $storeId) as $row) {
            if ((string)($row['method'] ?? '') === $method && in_array($row['type'] ?? '', self::TYPES, true)) {
                return [(string)$row['type'], trim((string)($row['intermediary'] ?? ''))];
            }
        }
        if (isset(self::DEFAULT_TYPES[$method])) {
            return self::DEFAULT_TYPES[$method];
        }
        if (str_starts_with($method, 'paypal') || str_starts_with($method, 'klarna')) {
            return [self::INTERMEDIARY, str_starts_with($method, 'paypal') ? 'PayPal' : 'Klarna'];
        }
        return [self::OTHER, ''];
    }

    /**
     * Carrier (company name, VKN/TCKN) of a shipment: the first "Carriers" row whose key is the Magento carrier code
     * or a word of the tracking title / shipping method name; "*" matches every other shipment.
     *
     * @param string[] $texts carrier codes and titles of the shipment
     * @return array{0: string, 1: string}
     */
    public function carrier(array $texts, int $storeId): array
    {
        $haystack = ' ' . self::normalise(implode(' ', $texts)) . ' ';
        $fallback = null;
        foreach ($this->rows('tr/carriers', $storeId) as $row) {
            $key = self::normalise((string)($row['match'] ?? ''));
            $carrier = [trim((string)($row['name'] ?? '')), preg_replace('/\D/', '', (string)($row['tax_id'] ?? '')) ?? ''];
            if ($key === '*') {
                $fallback ??= $carrier;
            } elseif ($key !== '' && preg_match('/(?<![a-z0-9])' . preg_quote($key, '/') . '(?![a-z0-9])/', $haystack)) {
                return $carrier;
            }
        }
        return $fallback ?? ['', ''];
    }

    /** Lower case ASCII, Turkish letters folded (Yurtiçi → yurtici), underscores as spaces. */
    public static function normalise(string $text): string
    {
        $text = strtr($text, ['İ' => 'i', 'I' => 'i', 'ı' => 'i', 'Ç' => 'c', 'ç' => 'c', 'Ğ' => 'g', 'ğ' => 'g',
            'Ö' => 'o', 'ö' => 'o', 'Ş' => 's', 'ş' => 's', 'Ü' => 'u', 'ü' => 'u', '_' => ' ']);
        return trim((string)preg_replace('/\s+/', ' ', mb_strtolower($text)));
    }

    private function website(int $storeId): string
    {
        $configured = trim($this->config->getValue('tr/website_url', $storeId));
        if ($configured !== '') {
            return $configured;
        }
        try {
            return rtrim((string)$this->storeManager->getStore($storeId)->getBaseUrl(UrlInterface::URL_TYPE_WEB, true), '/');
        } catch (\Throwable) {
            return '';
        }
    }

    /** Payment date: the date of a paid Magento invoice (online payments are captured with the invoice). */
    private function paymentDate(Invoice $invoice): string
    {
        if ($invoice->isCreditNote() || $invoice->entityId <= 0) {
            return '';
        }
        try {
            $entity = $this->invoiceRepository->get($invoice->entityId);
        } catch (\Throwable) {
            return '';
        }
        return (int)$entity->getState() === MagentoInvoice::STATE_PAID ? $invoice->issueDate : '';
    }

    /**
     * First shipment of the order: date and carrier.
     *
     * @return array{label: string, date: string, carrier: array{0: string, 1: string}}
     */
    private function shipping(Order $order, int $storeId): array
    {
        $shipment = $this->shipmentCollectionFactory->create()
            ->addFieldToFilter('order_id', (int)$order->getEntityId())
            ->setOrder('created_at', 'ASC')->setPageSize(1)->getFirstItem();
        $method = (string)$order->getShippingMethod();
        $texts = [strstr($method, '_', true) ?: $method, (string)$order->getShippingDescription()];
        $date = '';
        if ($shipment && $shipment->getId()) {
            $date = $this->timezone->scopeDate($storeId, (string)$shipment->getCreatedAt(), true)->format('Y-m-d');
            foreach ($shipment->getTracksCollection() as $track) {
                array_unshift($texts, (string)$track->getCarrierCode(), (string)$track->getTitle());
            }
        }
        $texts = array_values(array_filter(array_map('trim', $texts), 'strlen'));
        return ['label' => implode(' / ', array_unique($texts)), 'date' => $date, 'carrier' => $this->carrier($texts, $storeId)];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(string $path, int $storeId): array
    {
        $value = $this->config->getValue($path, $storeId);
        if ($value === '') {
            return [];
        }
        try {
            $rows = $this->json->unserialize($value);
        } catch (\InvalidArgumentException) {
            return [];
        }
        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }
}
