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
use CommerceGears\EInvoicingTr\Model\Preparer\UblTrPreparer;
use PHPUnit\Framework\TestCase;

class UblTrPreparerTest extends TestCase
{
    private function preparer(array $config, ?array $recipient, string $defaultSeries = '10X'): UblTrPreparer
    {
        $cfg = $this->createStub(Config::class);
        $cfg->method('getValue')->willReturnCallback(static fn (string $path) => (string)($config[$path] ?? ''));
        $channel = $this->createStub(NilveraChannel::class);
        $channel->method('isConfigured')->willReturn(true);
        $channel->method('eInvoiceRecipient')->willReturn($recipient);
        $channel->method('defaultSeries')->willReturn($defaultSeries);
        $pool = $this->createStub(ChannelPool::class);
        $pool->method('has')->willReturn(true);
        $pool->method('get')->willReturn($channel);
        return new UblTrPreparer($cfg, $pool);
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

    public function testReturnInvoiceToEFaturaBuyerIsBasicInvoice(): void
    {
        $invoice = $this->invoice('1234567801');
        $invoice->typeCode = Invoice::TYPE_CREDIT_NOTE;
        $this->preparer(['tr/einvoice_profile' => 'TICARIFATURA'], ['alias' => 'urn:mail:defaultpk@nilvera.com', 'type' => 'OZEL'])
            ->prepare($invoice, $this->document(), null);
        self::assertSame('TEMELFATURA', $invoice->extra['tr_profile'], 'GİB: IADE only with TEMELFATURA');
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
