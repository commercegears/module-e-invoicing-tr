<?php
/**
 * @copyright Copyright (c) CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\EInvoicingTr\Test\Unit\Model\Format;

use CommerceGears\EInvoicingCore\Model\Data\Address;
use CommerceGears\EInvoicingCore\Model\Data\Invoice;
use CommerceGears\EInvoicingCore\Model\Data\Line;
use CommerceGears\EInvoicingCore\Model\Data\Party;
use CommerceGears\EInvoicingCore\Model\Data\VatBreakdown;
use CommerceGears\EInvoicingTr\Model\Format\UblTr;
use PHPUnit\Framework\TestCase;

class UblTrTest extends TestCase
{
    private function invoice(array $extra, bool $creditNote = false): Invoice
    {
        $seller = new Party(name: 'Test Satıcı A.Ş.', taxNumber: '1234567801', taxOffice: 'Karesi',
            address: new Address(['Test Cad. 1'], 'Balıkesir', '10030', 'TR'));
        $buyer = new Party(name: 'Test Alıcı', taxNumber: '10000000146', isPerson: true,
            address: new Address(['Moda Cad. 45'], 'İstanbul', '34710', 'TR'));
        $invoice = new Invoice(number: $creditNote ? '000000007' : '000000031', typeCode: $creditNote ? Invoice::TYPE_CREDIT_NOTE : Invoice::TYPE_INVOICE,
            issueDate: '2026-10-17', currency: 'TRY', seller: $seller, buyer: $buyer,
            lines: [new Line('1', 1, 'C62', 100.0, 100.0, 'Test Çanta', 'S', 20.0, taxAmount: 20.0)],
            vatBreakdown: [new VatBreakdown('S', 20.0, 100.0, 20.0)], salesOrderReference: '000000099',
            precedingInvoiceNumber: $creditNote ? '000000031' : '', precedingInvoiceDate: $creditNote ? '2026-10-17' : '',
            extra: $extra + ['uuid' => 'F47AC10B-58CC-4372-A567-0E02B2C3D479', 'tr_profile' => 'EARSIVFATURA']);
        $invoice->totals->taxExclusive = 100.0;
        $invoice->totals->taxTotal = 20.0;
        $invoice->totals->taxInclusive = 120.0;
        return $invoice;
    }

    /** B: through Nilvera the XML carries the series only; Nilvera assigns year and sequence. */
    public function testSeriesOnlyThroughIntegrator(): void
    {
        $format = new UblTr();
        $invoice = $this->invoice(['tr_series' => 'TST', 'tr_prefix' => 'MAG']);
        self::assertSame([], $format->check($invoice));
        self::assertStringContainsString('<cbc:ProfileID>EARSIVFATURA</cbc:ProfileID><cbc:ID>TST</cbc:ID>',
            preg_replace('/>\s+</', '><', $format->toXml($invoice)));
        self::assertNotEmpty($format->check($this->invoice(['tr_series' => 'TOOLONG'])));
        self::assertNotEmpty($format->check($this->invoice(['tr_series' => ''])));
    }

    public function testLegacyNumberWithoutIntegrator(): void
    {
        $format = new UblTr();
        $invoice = $this->invoice(['tr_prefix' => 'ABC']);
        self::assertSame([], $format->check($invoice));
        self::assertStringContainsString('<cbc:ID>ABC2026000000031</cbc:ID>', $format->toXml($invoice));
    }

    /** Return invoices refer to the GİB number of the original invoice. */
    public function testReturnInvoiceRefersToGibNumber(): void
    {
        $format = new UblTr();
        $invoice = $this->invoice(['tr_series' => 'TST', 'preceding_tr_number' => 'TST2026924101575'], true);
        self::assertSame([], $format->check($invoice));
        $xml = preg_replace('/>\s+</', '><', $format->toXml($invoice));
        self::assertStringContainsString('<cbc:InvoiceTypeCode>IADE</cbc:InvoiceTypeCode>', $xml);
        self::assertStringContainsString('<cac:BillingReference><cac:InvoiceDocumentReference><cbc:ID>TST2026924101575</cbc:ID><cbc:IssueDate>2026-10-17</cbc:IssueDate>', $xml);
        $missing = $this->invoice(['tr_series' => 'TST', 'preceding_tr_missing' => true], true);
        self::assertStringStartsWith('TR-REF', $format->check($missing)[0] ?? '');
    }
}
