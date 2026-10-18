<?php
/**
 * @copyright Copyright (c) CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\EInvoicingTr\Model\Format;

use CommerceGears\EInvoicingCore\Model\Data\Address;
use CommerceGears\EInvoicingCore\Model\Data\Invoice;
use CommerceGears\EInvoicingCore\Model\Data\Party;
use CommerceGears\EInvoicingCore\Model\Data\VatCategory;
use CommerceGears\EInvoicingCore\Model\Format\AbstractUbl;
use CommerceGears\EInvoicingCore\Model\Format\FormatInterface;
use CommerceGears\EInvoicingCore\Model\Format\Xml;
use CommerceGears\EInvoicingTr\Model\InternetSale;

/**
 * Türkiye UBL-TR 1.2 (e-Fatura TEMELFATURA/TICARIFATURA or e-Arşiv EARSIVFATURA), unsigned: the private
 * integrator (e.g. Nilvera) numbers, signs and submits it to GİB. Built from the GİB UBL-TR guide, checked with the
 * UBL 2.1 schema and accepted by Nilvera's test system (e-Arşiv, e-Fatura and return invoices).
 */
class UblTr implements FormatInterface
{
    public const CODE = 'ubl_tr';
    private const EXT = 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2';
    /** UNTDID 4461 code per GİB payment type of internet sales (as used by Turkish integrators, e.g. Turkcell e-Şirket). */
    private const PAYMENT_CODES = [InternetSale::CARD => '48', InternetSale::TRANSFER => '30',
        InternetSale::CASH_ON_DELIVERY => '10', InternetSale::INTERMEDIARY => '1', InternetSale::OTHER => '97'];
    /** Required by VUK General Communiqué 509 (IV.2.4.5) on invoices of internet sales. */
    public const INTERNET_NOTE = 'Bu satış internet üzerinden yapılmıştır.';

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getLabel(): string
    {
        return (string)__('UBL-TR 1.2 (Türkiye e-Fatura / e-Arşiv)');
    }

    public function getOutputType(): string
    {
        return 'xml';
    }

    public function getSpecificationId(): string
    {
        return 'TR1.2';
    }

    public function isBeta(): bool
    {
        return true;
    }

    public function isEn16931(): bool
    {
        return false;
    }

    public function getSchemaFile(Invoice $invoice): string
    {
        return 'CommerceGears_EInvoicingCore::ubl21/maindoc/UBL-Invoice-2.1.xsd';
    }

    public function check(Invoice $invoice): array
    {
        $e = [];
        if (!preg_match('/^\d{10,11}$/', $this->taxId($invoice->seller))) {
            $e[] = 'TR-SELLER: ' . __('The seller VKN (10 digits) is missing: set the tax number in the seller settings.');
        }
        if ($invoice->seller->taxOffice === '') {
            $e[] = 'TR-TAXOFFICE: ' . __('The seller tax office (vergi dairesi) is missing.');
        }
        if ($this->integratorNumbering($invoice)) {
            if (!preg_match('/^[A-Z0-9]{3}$/', $this->number($invoice))) {
                $e[] = 'TR-SERIES: ' . __('The invoice series must be 3 capital letters or digits and exist in your Nilvera account (set it in the Türkiye settings).');
            }
            if ($invoice->isCreditNote() && !empty($invoice->extra['preceding_tr_missing'])) {
                $e[] = 'TR-REF: ' . __('The original invoice has no GİB number yet: send it to Nilvera first, then generate this return invoice again.');
            }
        } elseif (!preg_match('/^[A-Z0-9]{3}\d{13}$/', $this->number($invoice))) {
            $e[] = 'TR-ID: ' . __('The invoice number must have 3 letters/digits + year + 9 digits (set the series prefix).');
        }
        $net = $this->internet($invoice);
        if ($net !== null) {
            if (!preg_match('#^https?://[^\s/]+\.[^\s]+$#i', (string)$net['website'])) {
                $e[] = 'TR-WEB: ' . __('Internet sale: the web address of the shop is missing or not a full URL (Türkiye settings > Internet Sales > Web Address).');
            }
            if (in_array($net['payment_type'], InternetSale::DATE_REQUIRED, true) && $net['payment_date'] === '') {
                $e[] = 'TR-PAYDATE: ' . __('Internet sale: GİB needs the payment date for payment type %1, but the Magento invoice is not paid yet. Create the invoice when the payment has been received.', $net['payment_type']);
            }
            if ($net['goods'] && $net['despatch_date'] === '') {
                $e[] = 'TR-SHIP: ' . __('Internet sale: the order is not shipped yet. GİB needs the shipment date and the carrier; the invoice is generated again automatically when you create the shipment.');
            } elseif ($net['goods'] && ($net['carrier_name'] === '' || !preg_match('/^\d{10,11}$/', (string)$net['carrier_id']))) {
                $e[] = 'TR-CARRIER: ' . __('Internet sale: no carrier with name and VKN/TCKN for shipment "%1". Add it in Türkiye settings > Internet Sales > Carriers.', $net['shipping'] ?: '-');
            }
        }
        return $e;
    }

    public function toXml(Invoice $invoice): string
    {
        $x = new Xml(['' => AbstractUbl::NS_INVOICE, 'cac' => AbstractUbl::NS_CAC, 'cbc' => AbstractUbl::NS_CBC, 'ext' => self::EXT]);
        $root = $x->root('Invoice', ['' => AbstractUbl::NS_INVOICE, 'cac' => AbstractUbl::NS_CAC, 'cbc' => AbstractUbl::NS_CBC, 'ext' => self::EXT]);
        $cur = $invoice->currency;
        $profile = (string)($invoice->extra['tr_profile'] ?? 'EARSIVFATURA');
        // No ext:UBLExtensions: the integrator adds the XAdES signature extension when it signs the invoice.
        $x->add($root, 'cbc:UBLVersionID', '2.1');
        $x->add($root, 'cbc:CustomizationID', 'TR1.2');
        $x->add($root, 'cbc:ProfileID', $profile);
        $x->add($root, 'cbc:ID', $this->number($invoice));
        $x->add($root, 'cbc:CopyIndicator', 'false');
        $x->add($root, 'cbc:UUID', (string)($invoice->extra['uuid'] ?? ''));
        $x->add($root, 'cbc:IssueDate', $invoice->issueDate);
        $x->add($root, 'cbc:IssueTime', (string)($invoice->extra['issue_time'] ?? '12:00:00'));
        $x->add($root, 'cbc:InvoiceTypeCode', $invoice->isCreditNote() ? 'IADE' : 'SATIS');
        foreach ($invoice->notes as $note) {
            $x->add($root, 'cbc:Note', $note);
        }
        $x->add($root, 'cbc:Note', 'Sipariş: ' . $invoice->salesOrderReference);
        $net = $this->internet($invoice);
        if ($net !== null && $profile !== 'EARSIVFATURA') {
            // e-Arşiv: Nilvera prints the sentence itself with the internet sale details (IS_INTERNET below)
            $x->add($root, 'cbc:Note', self::INTERNET_NOTE);
            // e-Fatura has no dedicated fields for these (GİB's UBL-TR party needs a postal address, which a carrier
            // row does not have): the details are printed as notes
            foreach ($this->internetNotes($net) as $note) {
                $x->add($root, 'cbc:Note', $note);
            }
        }
        $x->add($root, 'cbc:DocumentCurrencyCode', $cur);
        $lines = $this->lines($invoice);
        $x->add($root, 'cbc:LineCountNumeric', (string)count($lines));
        if ($invoice->isCreditNote() && $invoice->precedingInvoiceNumber !== '') {
            $ref = $x->add($x->add($root, 'cac:BillingReference'), 'cac:InvoiceDocumentReference');
            // the GİB number of the original invoice (same series rule), not the Magento number or the ETTN
            $x->add($ref, 'cbc:ID', (string)(($invoice->extra['preceding_tr_number'] ?? '') ?: $this->gibNumber($invoice,
                $invoice->precedingInvoiceNumber, $invoice->precedingInvoiceDate ?: $invoice->issueDate)));
            $x->add($ref, 'cbc:IssueDate', $invoice->precedingInvoiceDate ?: $invoice->issueDate);
            $x->add($ref, 'cbc:DocumentTypeCode', 'IADE');
        }
        if ($profile === 'EARSIVFATURA') {
            $send = $x->add($root, 'cac:AdditionalDocumentReference');
            $x->add($send, 'cbc:ID', 'ELEKTRONIK');
            $x->add($send, 'cbc:IssueDate', $invoice->issueDate);
            $x->add($send, 'cbc:DocumentTypeCode', 'SEND_TYPE');
            if ($net !== null) {
                $this->internetReferences($x, $root, $invoice, $net);
            }
        }
        $signature = $x->add($root, 'cac:Signature');
        $x->add($signature, 'cbc:ID', $this->taxId($invoice->seller), ['schemeID' => 'VKN_TCKN']);
        $signatory = $x->add($signature, 'cac:SignatoryParty');
        $x->add($x->add($signatory, 'cac:PartyIdentification'), 'cbc:ID', $this->taxId($invoice->seller),
            ['schemeID' => strlen($this->taxId($invoice->seller)) === 11 ? 'TCKN' : 'VKN']);
        $this->address($x, $signatory, $invoice->seller->address);
        $x->add($x->add($x->add($signature, 'cac:DigitalSignatureAttachment'), 'cac:ExternalReference'), 'cbc:URI', '#Signature');

        $this->party($x, $x->add($root, 'cac:AccountingSupplierParty'), $invoice->seller, (string)($net['website'] ?? ''));
        $this->party($x, $x->add($root, 'cac:AccountingCustomerParty'), $invoice->buyer);

        $p = $invoice->payment;
        if ($net !== null) {
            $this->delivery($x, $root, $net);
            $means = $x->add($root, 'cac:PaymentMeans');
            $x->add($means, 'cbc:PaymentMeansCode', self::PAYMENT_CODES[$net['payment_type']] ?? '97');
            if ($net['payment_date'] !== '') {
                $x->add($means, 'cbc:PaymentDueDate', $net['payment_date']);
            }
            if ($net['intermediary'] !== '') {
                $x->add($means, 'cbc:PaymentChannelCode', mb_substr($net['intermediary'], 0, 100));
            }
            $x->add($means, 'cbc:InstructionNote', mb_substr($this->paymentText($net), 0, 100));
        } elseif ($p->meansCode !== '') {
            $means = $x->add($root, 'cac:PaymentMeans');
            $x->add($means, 'cbc:PaymentMeansCode', $p->meansCode === '68' ? 'ZZZ' : $p->meansCode);
            $x->add($means, 'cbc:PaymentDueDate', $p->isPaid ? $invoice->issueDate : ($p->dueDate ?: $invoice->issueDate));
            $x->add($means, 'cbc:InstructionNote', mb_substr($p->meansText, 0, 100));
        }

        $taxTotal = $x->add($root, 'cac:TaxTotal');
        $x->add($taxTotal, 'cbc:TaxAmount', Xml::amount($invoice->totals->taxTotal), ['currencyID' => $cur]);
        foreach ($invoice->vatBreakdown as $i => $vat) {
            $this->taxSubtotal($x, $taxTotal, $vat->taxableAmount, $vat->taxAmount, $vat->rate, $vat->category, $cur, $i + 1);
        }
        $t = $invoice->totals;
        $total = $x->add($root, 'cac:LegalMonetaryTotal');
        $x->add($total, 'cbc:LineExtensionAmount', Xml::amount($t->taxExclusive), ['currencyID' => $cur]);
        $x->add($total, 'cbc:TaxExclusiveAmount', Xml::amount($t->taxExclusive), ['currencyID' => $cur]);
        $x->add($total, 'cbc:TaxInclusiveAmount', Xml::amount($t->taxInclusive), ['currencyID' => $cur]);
        $x->add($total, 'cbc:AllowanceTotalAmount', Xml::amount(array_sum(array_column($lines, 'discount'))), ['currencyID' => $cur]);
        $x->add($total, 'cbc:PayableAmount', Xml::amount($t->taxInclusive), ['currencyID' => $cur]);

        foreach ($lines as $n => $line) {
            $el = $x->add($root, 'cac:InvoiceLine');
            $x->add($el, 'cbc:ID', (string)($n + 1));
            $x->add($el, 'cbc:InvoicedQuantity', Xml::decimal($line['qty'], 4, 0), ['unitCode' => $line['unit']]);
            $x->add($el, 'cbc:LineExtensionAmount', Xml::amount($line['net']), ['currencyID' => $cur]);
            if ($line['discount'] > 0) {
                $ac = $x->add($el, 'cac:AllowanceCharge');
                $x->add($ac, 'cbc:ChargeIndicator', 'false');
                $x->add($ac, 'cbc:Amount', Xml::amount($line['discount']), ['currencyID' => $cur]);
                $x->add($ac, 'cbc:BaseAmount', Xml::amount($line['net'] + $line['discount']), ['currencyID' => $cur]);
            }
            $lineTax = $x->add($el, 'cac:TaxTotal');
            $x->add($lineTax, 'cbc:TaxAmount', Xml::amount($line['tax']), ['currencyID' => $cur]);
            $this->taxSubtotal($x, $lineTax, $line['net'], $line['tax'], $line['rate'], $line['category'], $cur, 1);
            $x->add($x->add($el, 'cac:Item'), 'cbc:Name', mb_substr($line['name'], 0, 200));
            $x->add($x->add($el, 'cac:Price'), 'cbc:PriceAmount', Xml::decimal($line['price'], 6), ['currencyID' => $cur]);
        }
        return $x->save();
    }

    /**
     * @return array<int, array{name: string, qty: float, unit: string, price: float, net: float, discount: float, tax: float, rate: float, category: string}>
     */
    private function lines(Invoice $invoice): array
    {
        $lines = [];
        foreach ($invoice->lines as $line) {
            $discount = 0.0;
            foreach ($line->allowances as $ac) {
                $discount += $ac->isCharge ? 0 : $ac->amount;
            }
            $lines[] = ['name' => $line->name, 'qty' => $line->quantity, 'unit' => $line->unitCode === 'C62' ? 'C62' : $line->unitCode,
                'price' => $line->unitPrice, 'net' => $line->netAmount, 'discount' => round($discount, 2),
                'tax' => round($line->taxAmount, 2), 'rate' => $line->vatRate, 'category' => $line->vatCategory];
        }
        foreach ($invoice->allowanceCharges as $ac) {
            $net = ($ac->isCharge ? 1 : -1) * $ac->amount;
            $lines[] = ['name' => $ac->reason ?: ($ac->isCharge ? 'Kargo' : 'İndirim'), 'qty' => 1.0, 'unit' => 'C62',
                'price' => $net, 'net' => $net, 'discount' => 0.0, 'tax' => round($net * $ac->vatRate / 100, 2),
                'rate' => $ac->vatRate, 'category' => $ac->vatCategory];
        }
        // Line taxes must add up to the document tax per rate: give the rounding difference to the last line.
        foreach ($invoice->vatBreakdown as $vat) {
            $idx = array_keys(array_filter($lines, static fn ($l): bool => $l['category'] === $vat->category && $l['rate'] == $vat->rate));
            if ($idx) {
                $sum = array_sum(array_map(static fn ($i): float => $lines[$i]['tax'], $idx));
                $last = end($idx);
                $lines[$last]['tax'] = round($lines[$last]['tax'] + $vat->taxAmount - $sum, 2);
            }
        }
        return $lines;
    }

    private function taxSubtotal(Xml $x, \DOMElement $parent, float $base, float $tax, float $rate, string $category,
        string $cur, int $sequence): void
    {
        $sub = $x->add($parent, 'cac:TaxSubtotal');
        $x->add($sub, 'cbc:TaxableAmount', Xml::amount($base), ['currencyID' => $cur]);
        $x->add($sub, 'cbc:TaxAmount', Xml::amount($tax), ['currencyID' => $cur]);
        $x->add($sub, 'cbc:CalculationSequenceNumeric', (string)$sequence);
        $x->add($sub, 'cbc:Percent', Xml::percent($rate));
        $cat = $x->add($sub, 'cac:TaxCategory');
        if (VatCategory::isZeroRate($category)) {
            // 351: "Kanunun ... maddesi (istisna dışı)"; exports use 301. Override per tax class in the settings.
            $x->add($cat, 'cbc:TaxExemptionReasonCode', $category === VatCategory::EXPORT ? '301' : '351');
            $x->add($cat, 'cbc:TaxExemptionReason', $category === VatCategory::EXPORT ? 'Mal ihracatı' : 'İstisna dışı');
        }
        $scheme = $x->add($cat, 'cac:TaxScheme');
        $x->add($scheme, 'cbc:Name', 'KDV');
        $x->add($scheme, 'cbc:TaxTypeCode', '0015');
    }

    /**
     * Internet sale details as Nilvera writes them into the UBL of e-Arşiv invoices (checked against Nilvera's own
     * XML for a model with SalesPlatform INTERNET): AdditionalDocumentReference with DocumentTypeCode IS_INTERNET,
     * INT_WEBSITE, INT_PAYMENT_METHOD, INT_PAYMENTDATE, INT_PAYMENT_AGENT_NAME, INT_TRANSPORTER,
     * INT_TRANSPORTER_REGISTER_NUMBER and INT_TRANSPORT_DATE; dates as dd.mm.yyyy. Nilvera reports them to GİB
     * (e-Arşiv report "internetSatisBilgi").
     *
     * @param array<string, mixed> $net
     */
    private function internetReferences(Xml $x, \DOMElement $root, Invoice $invoice, array $net): void
    {
        $refs = ['IS_INTERNET' => '.', 'INT_WEBSITE' => (string)$net['website'], 'INT_PAYMENT_METHOD' => $this->paymentText($net)];
        if ($net['payment_date'] !== '') {
            $refs['INT_PAYMENTDATE'] = self::trDate((string)$net['payment_date']);
        }
        if ($net['intermediary'] !== '') {
            $refs['INT_PAYMENT_AGENT_NAME'] = (string)$net['intermediary'];
        }
        if ($net['goods'] && $net['carrier_name'] !== '') {
            $refs['INT_TRANSPORTER'] = (string)$net['carrier_name'];
            $refs['INT_TRANSPORTER_REGISTER_NUMBER'] = (string)$net['carrier_id'];
        }
        if ($net['despatch_date'] !== '') {
            $refs['INT_TRANSPORT_DATE'] = self::trDate((string)$net['despatch_date']);
        }
        foreach ($refs as $code => $value) {
            $ref = $x->add($root, 'cac:AdditionalDocumentReference');
            $x->add($ref, 'cbc:ID', mb_substr($value, 0, 200));
            $x->add($ref, 'cbc:IssueDate', $invoice->issueDate);
            $x->add($ref, 'cbc:DocumentTypeCode', $code);
        }
    }

    /**
     * Shipment / service date in the standard UBL place (Delivery/Despatch/ActualDespatchDate).
     *
     * @param array<string, mixed> $net
     */
    private function delivery(Xml $x, \DOMElement $root, array $net): void
    {
        if ($net['despatch_date'] !== '') {
            $x->add($x->add($x->add($root, 'cac:Delivery'), 'cac:Despatch'), 'cbc:ActualDespatchDate', (string)$net['despatch_date']);
        }
    }

    /**
     * The internet sale details as invoice notes (e-Fatura).
     *
     * @param array<string, mixed> $net
     * @return string[]
     */
    private function internetNotes(array $net): array
    {
        $notes = ['Web adresi: ' . $net['website'], 'Ödeme şekli: ' . $this->paymentText($net)
            . ($net['intermediary'] !== '' ? ' (ödeme aracısı: ' . $net['intermediary'] . ')' : '')
            . ($net['payment_date'] !== '' ? ', ödeme tarihi: ' . self::trDate((string)$net['payment_date']) : '')];
        if ($net['goods'] && $net['carrier_name'] !== '') {
            $notes[] = 'Gönderiyi taşıyan: ' . $net['carrier_name'] . ', VKN/TCKN: ' . $net['carrier_id']
                . ($net['despatch_date'] !== '' ? ', gönderim tarihi: ' . self::trDate((string)$net['despatch_date']) : '');
        } elseif ($net['despatch_date'] !== '') {
            $notes[] = 'Hizmetin ifa tarihi: ' . self::trDate((string)$net['despatch_date']);
        }
        return array_map(static fn (string $n): string => mb_substr($n, 0, 500), $notes);
    }

    /**
     * Internet sale details of a sales invoice (null for return invoices and when the store does not sell online).
     *
     * @return array<string, mixed>|null
     */
    private function internet(Invoice $invoice): ?array
    {
        $net = $invoice->extra['tr_internet'] ?? null;
        return is_array($net) && !$invoice->isCreditNote() ? $net + ['website' => '', 'payment_type' => InternetSale::OTHER,
            'payment_title' => '', 'intermediary' => '', 'payment_date' => '', 'goods' => false, 'shipping' => '',
            'carrier_name' => '', 'carrier_id' => '', 'despatch_date' => ''] : null;
    }

    /**
     * GİB payment type; "DIGER" carries the name of the payment method (e.g. "DIGER - Hediye çeki").
     *
     * @param array<string, mixed> $net
     */
    private function paymentText(array $net): string
    {
        return $net['payment_type'] === InternetSale::OTHER
            ? InternetSale::OTHER . ' - ' . ((string)$net['payment_title'] ?: 'Diğer') : (string)$net['payment_type'];
    }

    private static function trDate(string $date): string
    {
        $parsed = \DateTime::createFromFormat('!Y-m-d', $date);
        return $parsed ? $parsed->format('d.m.Y') : $date;
    }

    private function party(Xml $x, \DOMElement $wrapper, Party $party, string $website = ''): void
    {
        $el = $x->add($wrapper, 'cac:Party');
        if ($website !== '') {
            $x->add($el, 'cbc:WebsiteURI', $website);
        }
        $taxId = $this->taxId($party) ?: '11111111111';
        $x->add($x->add($el, 'cac:PartyIdentification'), 'cbc:ID', $taxId, ['schemeID' => strlen($taxId) === 11 ? 'TCKN' : 'VKN']);
        if (strlen($taxId) !== 11 || !$party->isPerson) {
            $x->add($x->add($el, 'cac:PartyName'), 'cbc:Name', $party->name);
        }
        $this->address($x, $el, $party->address);
        $scheme = $x->add($el, 'cac:PartyTaxScheme');
        $x->add($x->add($scheme, 'cac:TaxScheme'), 'cbc:Name', $party->taxOffice !== '' ? $party->taxOffice : '-');
        if ($party->contactPhone !== '' || $party->contactEmail !== '') {
            $contact = $x->add($el, 'cac:Contact');
            $x->add($contact, 'cbc:Telephone', $party->contactPhone);
            $x->add($contact, 'cbc:ElectronicMail', $party->contactEmail);
        }
        if (strlen($taxId) === 11 && $party->isPerson) {
            $parts = preg_split('/\s+/', trim($party->name)) ?: [''];
            $family = count($parts) > 1 ? array_pop($parts) : '-';
            $person = $x->add($el, 'cac:Person');
            $x->add($person, 'cbc:FirstName', implode(' ', $parts) ?: '-');
            $x->add($person, 'cbc:FamilyName', $family);
        }
    }

    private function address(Xml $x, \DOMElement $parent, Address $address): void
    {
        $el = $x->add($parent, 'cac:PostalAddress');
        $x->add($el, 'cbc:StreetName', implode(' ', $address->lines) ?: '-');
        $x->add($el, 'cbc:CitySubdivisionName', $address->subdivision !== '' ? $address->subdivision : $address->city);
        $x->add($el, 'cbc:CityName', $address->city !== '' ? $address->city : '-');
        $x->add($el, 'cbc:PostalZone', $address->postcode);
        $x->add($x->add($el, 'cac:Country'), 'cbc:Name', $address->countryCode === 'TR' ? 'Türkiye' : $address->countryCode);
    }

    private function taxId(Party $party): string
    {
        foreach ([$party->taxNumber, (string)($party->extra['tr_vkn'] ?? ''), (string)($party->extra['tr_tckn'] ?? ''),
                     preg_replace('/^TR/', '', $party->vatId) ?? ''] as $candidate) {
            if (preg_match('/^\d{10,11}$/', (string)$candidate)) {
                return (string)$candidate;
            }
        }
        return '';
    }

    /**
     * cbc:ID. Through an integrator that numbers invoices (Nilvera): only the 3-character series, the integrator adds
     * year and sequence in GİB's date order and returns the GİB number. Otherwise: series + year + Magento number.
     */
    private function number(Invoice $invoice): string
    {
        if ((string)($invoice->extra['tr_number'] ?? '') !== '') {
            return (string)$invoice->extra['tr_number'];
        }
        if ($this->integratorNumbering($invoice)) {
            return strtoupper((string)$invoice->extra['tr_series']);
        }
        return $this->gibNumber($invoice, $invoice->number, $invoice->issueDate);
    }

    private function integratorNumbering(Invoice $invoice): bool
    {
        return (string)($invoice->extra['tr_number'] ?? '') === '' && array_key_exists('tr_series', $invoice->extra);
    }

    /** GİB invoice number: series prefix + year + the last 9 digits of the Magento number. */
    private function gibNumber(Invoice $invoice, string $number, string $date): string
    {
        $prefix = strtoupper((string)($invoice->extra['tr_prefix'] ?? 'MAG'));
        $digits = preg_replace('/\D/', '', $number) ?? '';
        return substr($prefix, 0, 3) . substr($date, 0, 4) . str_pad(substr($digits, -9), 9, '0', STR_PAD_LEFT);
    }
}
