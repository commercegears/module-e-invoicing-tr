<?php
/**
 * @copyright Copyright (c) CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\EInvoicingTr\Test\Unit\Model\Preparer;

use CommerceGears\EInvoicingCore\Model\Channel\ChannelPool;
use CommerceGears\EInvoicingCore\Model\Config;
use CommerceGears\EInvoicingCore\Model\Data\Invoice;
use CommerceGears\EInvoicingCore\Model\Data\Party;
use CommerceGears\EInvoicingCore\Model\Document;
use CommerceGears\EInvoicingTr\Model\Channel\NilveraChannel;
use CommerceGears\EInvoicingTr\Model\InternetSale;
use CommerceGears\EInvoicingTr\Model\Preparer\UblTrPreparer;
use PHPUnit\Framework\TestCase;

class UblTrPreparerTest extends TestCase
{
    private function preparer(array $config, ?array $recipient, string $defaultSeries = '10X', bool $configured = true): UblTrPreparer
    {
        $cfg = $this->createStub(Config::class);
        $cfg->method('getValue')->willReturnCallback(static fn (string $path) => (string)($config[$path] ?? ''));
        $channel = $this->createStub(NilveraChannel::class);
        $channel->method('isConfigured')->willReturn($configured);
        $channel->method('eInvoiceRecipient')->willReturn($recipient);
        $channel->method('defaultSeries')->willReturn($defaultSeries);
        $pool = $this->createStub(ChannelPool::class);
        $pool->method('has')->willReturn(true);
        $pool->method('get')->willReturn($channel);
        $internet = $this->createStub(InternetSale::class);
        $internet->method('isEnabled')->willReturn(($config['tr/internet_sale'] ?? '1') !== '0');
        $internet->method('collect')->willReturn(['website' => 'https://shop.example.com.tr', 'payment_type' => InternetSale::CARD]);
        return new UblTrPreparer($cfg, $pool, $internet);
    }

    private function document(array $data = []): Document
    {
        $document = $this->createStub(Document::class);
        $document->method('getUuid')->willReturn('f47ac10b-58cc-4372-a567-0e02b2c3d479');
        $document->method('getStoreId')->willReturn(1);
        $document->method('getChannel')->willReturn('tr_nilvera');
        $document->method('getExternalId')->willReturn((string)($data['external_id'] ?? ''));
        $document->method('getData')->willReturnCallback(static fn ($key = '') => $data[$key] ?? null);
        return $document;
    }

    private function invoice(string $buyerTaxId): Invoice
    {
        return new Invoice(seller: new Party(taxNumber: '1234567801'), buyer: new Party(taxNumber: $buyerTaxId));
    }

    public function testEFaturaBuyerGetsCommercialInvoiceInEFaturaSeries(): void
    {
        $invoice = $this->invoice('1234567801');
        $this->preparer(['tr/einvoice_series' => 'TST', 'tr/series_prefix' => 'MAG'],
            ['alias' => 'urn:mail:defaultpk@nilvera.com', 'type' => 'OZEL'])->prepare($invoice, $this->document(), null);
        self::assertSame(['TICARIFATURA', 'urn:mail:defaultpk@nilvera.com', 'TST', 'F47AC10B-58CC-4372-A567-0E02B2C3D479'],
            [$invoice->extra['tr_profile'], $invoice->extra['tr_alias'], $invoice->extra['tr_series'], $invoice->extra['uuid']]);
    }

    public function testPublicSectorGetsBasicInvoice(): void
    {
        $invoice = $this->invoice('1234567801');
        $this->preparer([], ['alias' => 'urn:mail:fatura@kurum.gov.tr', 'type' => 'KAMU'])->prepare($invoice, $this->document(), null);
        self::assertSame('TEMELFATURA', $invoice->extra['tr_profile']);
    }

    public function testReturnInvoiceToEFaturaBuyerIsBasicInvoiceWhenIssuedByStore(): void
    {
        $invoice = $this->invoice('1234567801');
        $invoice->typeCode = Invoice::TYPE_CREDIT_NOTE;
        $this->preparer(['tr/einvoice_profile' => 'TICARIFATURA', 'tr/return_einvoice' => 'store'],
            ['alias' => 'urn:mail:defaultpk@nilvera.com', 'type' => 'OZEL'])->prepare($invoice, $this->document(), null);
        self::assertSame('TEMELFATURA', $invoice->extra['tr_profile'], 'GİB: IADE only with TEMELFATURA');
        self::assertArrayNotHasKey(Invoice::EXTRA_NOT_SENT, $invoice->extra);
    }

    /** Default: the e-Fatura buyer issues the return invoice (UBL-TR code list: IADE is issued by the buyer). */
    public function testReturnToEFaturaBuyerIsNotSentByDefault(): void
    {
        $invoice = $this->invoice('1234567801');
        $invoice->typeCode = Invoice::TYPE_CREDIT_NOTE;
        $this->preparer([], ['alias' => 'urn:mail:defaultpk@nilvera.com', 'type' => 'OZEL'])->prepare($invoice, $this->document(), null);
        self::assertSame('issued_by_buyer', $invoice->extra[Invoice::EXTRA_NOT_SENT_CODE]);
        self::assertStringContainsString('buyer issues the return invoice', $invoice->extra[Invoice::EXTRA_NOT_SENT]);
        self::assertArrayNotHasKey('tr_series', $invoice->extra);
    }

    /** Default: consumer returns are documented with the return section / expense voucher (VUK 509 IV.2.4.5). */
    public function testConsumerReturnIsNotSentByDefault(): void
    {
        $invoice = $this->invoice('10000000146');
        $invoice->typeCode = Invoice::TYPE_CREDIT_NOTE;
        $this->preparer([], null)->prepare($invoice, $this->document(), null);
        self::assertSame('return_voucher', $invoice->extra[Invoice::EXTRA_NOT_SENT_CODE]);
        $invoice = $this->invoice('10000000146');
        $invoice->typeCode = Invoice::TYPE_CREDIT_NOTE;
        $this->preparer(['tr/return_earchive' => 'store'], null)->prepare($invoice, $this->document(), null);
        self::assertArrayNotHasKey(Invoice::EXTRA_NOT_SENT, $invoice->extra);
        self::assertSame('EARSIVFATURA', $invoice->extra['tr_profile']);
    }

    /** Without the Nilvera key the buyer type is unknown: decided only when both settings give the same answer. */
    public function testReturnWithoutNilveraKey(): void
    {
        $invoice = $this->invoice('1234567801');
        $invoice->typeCode = Invoice::TYPE_CREDIT_NOTE;
        $this->preparer([], null, '', false)->prepare($invoice, $this->document(), null);
        self::assertSame('return_voucher', $invoice->extra[Invoice::EXTRA_NOT_SENT_CODE] ?? '');
        $invoice = $this->invoice('1234567801');
        $invoice->typeCode = Invoice::TYPE_CREDIT_NOTE;
        $this->preparer(['tr/return_einvoice' => 'store'], null, '', false)->prepare($invoice, $this->document(), null);
        self::assertArrayNotHasKey(Invoice::EXTRA_NOT_SENT, $invoice->extra);
    }

    public function testInternetSaleDetailsOnSalesInvoicesOnly(): void
    {
        $invoice = $this->invoice('10000000146');
        $this->preparer([], null)->prepare($invoice, $this->document(), null);
        self::assertSame(InternetSale::CARD, $invoice->extra['tr_internet']['payment_type']);
        $invoice = $this->invoice('10000000146');
        $this->preparer(['tr/internet_sale' => '0'], null)->prepare($invoice, $this->document(), null);
        self::assertArrayNotHasKey('tr_internet', $invoice->extra);
        $invoice = $this->invoice('10000000146');
        $invoice->typeCode = Invoice::TYPE_CREDIT_NOTE;
        $this->preparer(['tr/return_earchive' => 'store'], null)->prepare($invoice, $this->document(), null);
        self::assertArrayNotHasKey('tr_internet', $invoice->extra);
    }

    public function testConsumerGetsEArchiveInDefaultSeries(): void
    {
        $invoice = $this->invoice('10000000146');
        $this->preparer(['tr/series_prefix' => 'MAG'], null)->prepare($invoice, $this->document(), null);
        self::assertSame(['EARSIVFATURA', '10X'], [$invoice->extra['tr_profile'], $invoice->extra['tr_series']]);
        self::assertArrayNotHasKey('tr_alias', $invoice->extra);
    }

    public function testOldCustomPrefixIsKeptAsSeries(): void
    {
        $invoice = $this->invoice('10000000146');
        $this->preparer(['tr/series_prefix' => 'abc'], null)->prepare($invoice, $this->document(), null);
        self::assertSame('ABC', $invoice->extra['tr_series']);
    }

    public function testReturnInvoiceTakesGibNumberOfOriginal(): void
    {
        $invoice = $this->invoice('10000000146');
        $original = $this->document(['external_id' => 'TST2026924101575', 'sent_at' => '2026-10-17 10:00:00']);
        $this->preparer(['tr/earchive_series' => 'TST'], null)->prepare($invoice, $this->document(), $original);
        self::assertSame('TST2026924101575', $invoice->extra['preceding_tr_number']);
        $invoice = $this->invoice('10000000146');
        $this->preparer(['tr/earchive_series' => 'TST'], null)->prepare($invoice, $this->document(), $this->document());
        self::assertTrue($invoice->extra['preceding_tr_missing']);
    }
}
