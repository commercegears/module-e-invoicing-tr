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

/**
 * UBL-TR: ETTN (document UUID), GİB invoice number series, and the profile: buyers in GİB's e-Fatura list
 * (checked through Nilvera) get an e-Fatura, all others an e-Arşiv invoice.
 */
class UblTrPreparer implements InvoicePreparerInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly ChannelPool $channelPool
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
        $invoice->extra['tr_prefix'] = $this->config->getValue('tr/series_prefix', $storeId) ?: 'MAG';
        $invoice->seller->taxNumber = $invoice->seller->taxNumber ?: (preg_replace('/^TR/', '', $invoice->seller->vatId) ?? '');
        $invoice->extra['tr_profile'] = 'EARSIVFATURA';
        $channel = $this->channelPool->has($document->getChannel()) ? $this->channelPool->get($document->getChannel()) : null;
        if ($channel instanceof NilveraChannel && $channel->isConfigured($storeId)) {
            $taxId = $invoice->buyer->taxNumber ?: (preg_replace('/^TR/', '', $invoice->buyer->vatId) ?? '');
            $alias = $channel->eInvoiceAlias($taxId, $storeId);
            if ($alias !== '') {
                $invoice->extra['tr_profile'] = $this->config->getValue('tr/einvoice_profile', $storeId) ?: 'TICARIFATURA';
                $invoice->extra['tr_alias'] = $alias;
            }
        }
    }
}
