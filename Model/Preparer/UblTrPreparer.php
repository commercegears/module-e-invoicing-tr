<?php
/**
 * @copyright Copyright (c) CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\EInvoicingTr\Model\Preparer;

use CommerceGears\EInvoicingCore\Api\InvoicePreparerInterface;
use CommerceGears\EInvoicingCore\Model\Channel\ChannelPool;
use CommerceGears\EInvoicingCore\Model\Config;
use CommerceGears\EInvoicingCore\Model\Data\Invoice;
use CommerceGears\EInvoicingCore\Model\Document;
use CommerceGears\EInvoicingTr\Model\Channel\NilveraChannel;
use CommerceGears\EInvoicingTr\Model\InternetSale;

/**
 * UBL-TR: ETTN (document UUID), invoice series and the profile: buyers in GİB's e-Fatura list (checked through
 * Nilvera) get an e-Fatura, all others an e-Arşiv invoice. Through Nilvera the XML carries only the series and
 * Nilvera assigns the GİB number; return invoices refer to the GİB number of the original invoice.
 *
 * Returns (credit memos), per the "Return Invoices" settings: an e-Fatura buyer issues the return invoice (IADE) to
 * the store (GİB UBL-TR code list: IADE = "issued by the buyer to return goods"), so by default nothing is sent and
 * the admin records the buyer's invoice; consumers cannot issue invoices: the signed return section of the internet
 * sale invoice or the store's expense voucher (gider pusulası) documents the return (VUK 509, IV.2.4.5).
 * Online sales get the internet sale details (InternetSale).
 */
class UblTrPreparer implements InvoicePreparerInterface
{
    /** Default of the old "Invoice Series Prefix" setting (before 2.2.0 the series of every invoice). */
    private const LEGACY_DEFAULT = 'MAG';

    public function __construct(
        private readonly Config $config,
        private readonly ChannelPool $channelPool,
        private readonly InternetSale $internetSale
    ) {
    }

    public function getFormats(): array
    {
        return ['ubl_tr'];
    }

    public function prepare(Invoice $invoice, Document $document, ?Document $original): void
    {
        $storeId = $document->getStoreId();
        $invoice->extra['uuid'] = strtoupper($document->getUuid());
        $prefix = strtoupper(trim((string)$this->config->getValue('tr/series_prefix', $storeId))) ?: self::LEGACY_DEFAULT;
        $invoice->extra['tr_prefix'] = $prefix;
        $invoice->seller->taxNumber = $invoice->seller->taxNumber ?: (preg_replace('/^TR/', '', $invoice->seller->vatId) ?? '');
        $invoice->extra['tr_profile'] = 'EARSIVFATURA';
        $channel = $this->channelPool->has($document->getChannel()) ? $this->channelPool->get($document->getChannel()) : null;
        if (!$channel instanceof NilveraChannel) {
            return;
        }
        $configured = $channel->isConfigured($storeId);
        if ($configured) {
            $taxId = $invoice->buyer->taxNumber ?: (preg_replace('/^TR/', '', $invoice->buyer->vatId) ?? '');
            $recipient = $channel->eInvoiceRecipient($taxId, $storeId);
            if ($recipient !== null) {
                $profile = $this->config->getValue('tr/einvoice_profile', $storeId) ?: 'TICARIFATURA';
                // Public administrations (KAMU) cannot answer a commercial invoice, and GİB accepts a return invoice
                // (IADE) only as TEMELFATURA, never as TICARIFATURA.
                $invoice->extra['tr_profile'] = $recipient['type'] === 'KAMU' || $invoice->isCreditNote() ? 'TEMELFATURA' : $profile;
                $invoice->extra['tr_alias'] = $recipient['alias'];
            }
        }
        if ($invoice->isCreditNote() && $this->documentedOutside($invoice, $configured, $storeId)) {
            return;
        }
        $eArchive = $invoice->extra['tr_profile'] === 'EARSIVFATURA';
        // Nilvera numbers the invoice: the XML carries the series only
        $series = strtoupper(trim((string)$this->config->getValue($eArchive ? 'tr/earchive_series' : 'tr/einvoice_series', $storeId)));
        if ($series === '' && $prefix !== self::LEGACY_DEFAULT) {
            $series = $prefix;
        }
        if ($series === '' && $configured && !$document->getData('sent_at')) {
            $series = $channel->defaultSeries($eArchive, $storeId);
        }
        $invoice->extra['tr_series'] = $series !== '' ? $series : $prefix;
        if ($original !== null) {
            $number = $original->getExternalId();
            if (preg_match(NilveraChannel::GIB_NUMBER, $number)) {
                $invoice->extra['preceding_tr_number'] = $number;
            } elseif (!$original->getData('sent_at')) {
                // the original invoice has not been numbered by Nilvera yet
                $invoice->extra['preceding_tr_missing'] = true;
            }
            // sent before 2.2.0 (no GİB number stored): series + year + Magento number, as in that XML
        }
        if (!$invoice->isCreditNote() && $this->internetSale->isEnabled($storeId)) {
            $invoice->extra['tr_internet'] = $this->internetSale->collect($invoice, $storeId);
        }
    }

    /**
     * Credit memo that the store does not send as a return invoice (status "Not sent (documented outside)").
     */
    private function documentedOutside(Invoice $invoice, bool $configured, int $storeId): bool
    {
        if (isset($invoice->extra['tr_alias'])) {
            if (($this->config->getValue('tr/return_einvoice', $storeId) ?: 'buyer') !== 'buyer') {
                return false;
            }
            $invoice->extra[Invoice::EXTRA_NOT_SENT_CODE] = 'issued_by_buyer';
            $invoice->extra[Invoice::EXTRA_NOT_SENT] = (string)__('Not sent: the buyer is registered for e-Fatura, so the buyer issues the return invoice (IADE) to you through GİB. When you receive it, record its number and date here. (Setting: Return Invoices > e-Fatura Buyers.)');
            return true;
        }
        if (($this->config->getValue('tr/return_earchive', $storeId) ?: 'voucher') !== 'voucher'
            // without the Nilvera key the buyer cannot be checked in GİB's e-Fatura list: only when neither kind of
            // buyer gets a return invoice from the store is the answer the same
            || (!$configured && ($this->config->getValue('tr/return_einvoice', $storeId) ?: 'buyer') !== 'buyer')) {
            return false;
        }
        $invoice->extra[Invoice::EXTRA_NOT_SENT_CODE] = 'return_voucher';
        $invoice->extra[Invoice::EXTRA_NOT_SENT] = (string)__('Not sent: e-Arşiv buyer. A consumer documents the return with the signed return section of the internet sale invoice, otherwise you issue an expense voucher (gider pusulası); a business buyer issues its own return invoice. Record that document\'s number and date here. (Setting: Return Invoices > e-Arşiv Buyers.)');
        return true;
    }
}
