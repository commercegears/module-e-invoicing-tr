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
use CommerceGears\EInvoicingTr\Model\InternetSale;
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

    private function net(array $override = []): array
    {
        return $override + ['website' => 'https://shop.example.com.tr', 'payment_type' => InternetSale::CARD, 'payment_title' => 'Kredi kartı',
            'intermediary' => 'iyzico', 'payment_date' => '2026-10-17', 'goods' => true, 'shipping' => 'Yurtiçi Kargo',
            'carrier_name' => 'Test Kargo A.Ş.', 'carrier_id' => '1234567890', 'despatch_date' => '2026-10-18'];
    }

    /** e-Arşiv internet sale: Nilvera's AdditionalDocumentReference codes plus the standard UBL places. */
    public function testEArchiveInternetSale(): void
    {
        $format = new UblTr();
        $invoice = $this->invoice(['tr_series' => 'TST', 'tr_internet' => $this->net()]);
        self::assertSame([], $format->check($invoice));
        $xml = preg_replace('/>\s+</', '><', $format->toXml($invoice));
        self::assertStringNotContainsString('Bu satış internet', $xml, 'e-Arşiv: Nilvera prints it');
        foreach ([
                     '<cbc:ID>.</cbc:ID><cbc:IssueDate>2026-10-17</cbc:IssueDate><cbc:DocumentTypeCode>IS_INTERNET</cbc:DocumentTypeCode>',
                     '<cbc:ID>https://shop.example.com.tr</cbc:ID><cbc:IssueDate>2026-10-17</cbc:IssueDate><cbc:DocumentTypeCode>INT_WEBSITE</cbc:DocumentTypeCode>',
                     '<cbc:ID>KREDIKARTI/BANKAKARTI</cbc:ID><cbc:IssueDate>2026-10-17</cbc:IssueDate><cbc:DocumentTypeCode>INT_PAYMENT_METHOD</cbc:DocumentTypeCode>',
                     '<cbc:ID>17.10.2026</cbc:ID><cbc:IssueDate>2026-10-17</cbc:IssueDate><cbc:DocumentTypeCode>INT_PAYMENTDATE</cbc:DocumentTypeCode>',
                     '<cbc:ID>iyzico</cbc:ID><cbc:IssueDate>2026-10-17</cbc:IssueDate><cbc:DocumentTypeCode>INT_PAYMENT_AGENT_NAME</cbc:DocumentTypeCode>',
                     '<cbc:ID>Test Kargo A.Ş.</cbc:ID><cbc:IssueDate>2026-10-17</cbc:IssueDate><cbc:DocumentTypeCode>INT_TRANSPORTER</cbc:DocumentTypeCode>',
                     '<cbc:ID>1234567890</cbc:ID><cbc:IssueDate>2026-10-17</cbc:IssueDate><cbc:DocumentTypeCode>INT_TRANSPORTER_REGISTER_NUMBER</cbc:DocumentTypeCode>',
                     '<cbc:ID>18.10.2026</cbc:ID><cbc:IssueDate>2026-10-17</cbc:IssueDate><cbc:DocumentTypeCode>INT_TRANSPORT_DATE</cbc:DocumentTypeCode>',
                     '<cac:AccountingSupplierParty><cac:Party><cbc:WebsiteURI>https://shop.example.com.tr</cbc:WebsiteURI><cac:PartyIdentification>',
                     '<cac:Delivery><cac:Despatch><cbc:ActualDespatchDate>2026-10-18</cbc:ActualDespatchDate></cac:Despatch></cac:Delivery><cac:PaymentMeans>',
                     '<cac:PaymentMeans><cbc:PaymentMeansCode>48</cbc:PaymentMeansCode><cbc:PaymentDueDate>2026-10-17</cbc:PaymentDueDate><cbc:PaymentChannelCode>iyzico</cbc:PaymentChannelCode><cbc:InstructionNote>KREDIKARTI/BANKAKARTI</cbc:InstructionNote></cac:PaymentMeans>',
                 ] as $expected) {
            self::assertStringContainsString($expected, $xml);
        }
        $xsd = (string)(class_exists(\CommerceGears\EInvoicingCore\Model\Config::class)
            ? dirname((string)(new \ReflectionClass(\CommerceGears\EInvoicingCore\Model\Config::class))->getFileName(), 2) : '')
            . '/Resources/schema/ubl21/maindoc/UBL-Invoice-2.1.xsd';
        if (is_file($xsd)) {
            $dom = new \DOMDocument();
            $dom->loadXML($format->toXml($invoice));
            self::assertTrue(@$dom->schemaValidate($xsd), 'UBL 2.1 schema');
        }
    }

    /** e-Fatura: standard UBL places only (no Nilvera e-Arşiv codes), "DIGER" carries the method name. */
    public function testEInvoiceInternetSaleAndOtherPayment(): void
    {
        $format = new UblTr();
        $invoice = $this->invoice(['tr_series' => 'TST', 'tr_profile' => 'TICARIFATURA',
            'tr_internet' => $this->net(['payment_type' => InternetSale::OTHER, 'payment_title' => 'Hediye çeki', 'intermediary' => '',
                'payment_date' => '', 'goods' => false, 'carrier_name' => '', 'carrier_id' => '', 'despatch_date' => '2026-10-17'])]);
        $invoice->extra['tr_profile'] = 'TICARIFATURA';
        self::assertSame([], $format->check($invoice));
        $xml = preg_replace('/>\s+</', '><', $format->toXml($invoice));
        self::assertStringNotContainsString('IS_INTERNET', $xml);
        self::assertStringContainsString('<cbc:Note>Bu satış internet üzerinden yapılmıştır.</cbc:Note>', $xml);
        self::assertStringContainsString('<cbc:Note>Ödeme şekli: DIGER - Hediye çeki</cbc:Note><cbc:Note>Hizmetin ifa tarihi: 17.10.2026</cbc:Note>', $xml);
        $goods = $this->invoice(['tr_series' => 'TST', 'tr_profile' => 'TICARIFATURA', 'tr_internet' => $this->net()]);
        $goods->extra['tr_profile'] = 'TICARIFATURA';
        self::assertStringContainsString('<cbc:Note>Web adresi: https://shop.example.com.tr</cbc:Note><cbc:Note>Ödeme şekli: KREDIKARTI/BANKAKARTI (ödeme aracısı: iyzico), ödeme tarihi: 17.10.2026</cbc:Note><cbc:Note>Gönderiyi taşıyan: Test Kargo A.Ş., VKN/TCKN: 1234567890, gönderim tarihi: 18.10.2026</cbc:Note>',
            preg_replace('/>\s+</', '><', $format->toXml($goods)));
        self::assertStringContainsString('<cac:Delivery><cac:Despatch><cbc:ActualDespatchDate>2026-10-17</cbc:ActualDespatchDate></cac:Despatch></cac:Delivery>', $xml);
        self::assertStringContainsString('<cbc:PaymentMeansCode>97</cbc:PaymentMeansCode><cbc:InstructionNote>DIGER - Hediye çeki</cbc:InstructionNote>', $xml);
    }

    public function testInternetSaleChecks(): void
    {
        $format = new UblTr();
        $codes = static fn (array $errors): array => array_map(static fn (string $e): string => strstr($e, ':', true), $errors);
        self::assertSame(['TR-SHIP'], $codes($format->check($this->invoice(['tr_series' => 'TST', 'tr_internet' => $this->net(['despatch_date' => ''])]))));
        self::assertSame(['TR-CARRIER'], $codes($format->check($this->invoice(['tr_series' => 'TST', 'tr_internet' => $this->net(['carrier_id' => ''])]))));
        self::assertSame(['TR-PAYDATE'], $codes($format->check($this->invoice(['tr_series' => 'TST', 'tr_internet' => $this->net(['payment_date' => ''])]))));
        self::assertSame([], $format->check($this->invoice(['tr_series' => 'TST',
            'tr_internet' => $this->net(['payment_type' => InternetSale::CASH_ON_DELIVERY, 'payment_date' => ''])])));
        self::assertSame(['TR-WEB'], $codes($format->check($this->invoice(['tr_series' => 'TST', 'tr_internet' => $this->net(['website' => 'shop'])]))));
        // return invoices carry no internet sale details
        self::assertSame([], $format->check($this->invoice(['tr_series' => 'TST', 'preceding_tr_number' => 'TST2026924101575',
            'tr_internet' => $this->net(['despatch_date' => ''])], true)));
    }
}
