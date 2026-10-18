<?php
/**
 * @copyright Copyright (c) CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\EInvoicingTr\Test\Unit\Model;

use CommerceGears\EInvoicingCore\Model\Config;
use CommerceGears\EInvoicingTr\Model\InternetSale;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Sales\Api\InvoiceRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\ResourceModel\Order\Shipment\CollectionFactory;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class InternetSaleTest extends TestCase
{
    private function model(array $config): InternetSale
    {
        $cfg = $this->createStub(Config::class);
        $cfg->method('getValue')->willReturnCallback(static fn (string $path) => (string)($config[$path] ?? ''));
        return new InternetSale($cfg, new Json(), $this->createStub(OrderRepositoryInterface::class),
            $this->createStub(InvoiceRepositoryInterface::class), $this->createStub(CollectionFactory::class),
            $this->createStub(StoreManagerInterface::class), $this->createStub(TimezoneInterface::class));
    }

    public function testPaymentTypeDefaultsAndSetting(): void
    {
        $model = $this->model(['tr/payment_types' => json_encode([
            '_1' => ['method' => 'checkmo', 'type' => InternetSale::OTHER, 'intermediary' => ''],
            '_2' => ['method' => 'stripe_payments', 'type' => 'NONSENSE', 'intermediary' => 'x'],
        ])]);
        self::assertSame([InternetSale::OTHER, ''], $model->paymentType('checkmo', 1), 'setting wins');
        self::assertSame([InternetSale::CARD, 'Stripe'], $model->paymentType('stripe_payments', 1), 'invalid row ignored');
        self::assertSame([InternetSale::CASH_ON_DELIVERY, ''], $model->paymentType('cashondelivery', 1));
        self::assertSame([InternetSale::TRANSFER, ''], $model->paymentType('banktransfer', 1));
        self::assertSame([InternetSale::INTERMEDIARY, 'PayPal'], $model->paymentType('paypal_billing_agreement', 1));
        self::assertSame([InternetSale::OTHER, ''], $model->paymentType('some_gateway', 1));
    }

    public function testCarrierMatching(): void
    {
        $model = $this->model(['tr/carriers' => json_encode([
            '_1' => ['match' => 'yurtici', 'name' => 'Yurtiçi Kargo Servisi A.Ş.', 'tax_id' => '111 111 1111'],
            '_2' => ['match' => 'flatrate', 'name' => 'Test Lojistik A.Ş.', 'tax_id' => '2222222222'],
            '_3' => ['match' => '*', 'name' => 'Kendi Teslimatımız', 'tax_id' => '3333333333'],
            '_4' => ['match' => 'aras', 'name' => 'Aras', 'tax_id' => ''],
        ])]);
        self::assertSame(['Yurtiçi Kargo Servisi A.Ş.', '1111111111'], $model->carrier(['custom', 'YURTİÇİ KARGO'], 1));
        self::assertSame(['Test Lojistik A.Ş.', '2222222222'], $model->carrier(['flatrate', 'Flat Rate - Fixed'], 1));
        self::assertSame(['Kendi Teslimatımız', '3333333333'], $model->carrier(['tablerate', 'Karayolları arasında'], 1),
            'whole words only: "arasında" is not Aras');
        self::assertSame(['', ''], $this->model([])->carrier(['flatrate'], 1));
        self::assertSame('yurtici kargo', InternetSale::normalise(' Yurtiçi  Kargo '));
    }
}
