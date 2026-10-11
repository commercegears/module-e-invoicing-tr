<?php
/**
 * @copyright Copyright (c) CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\EInvoicingTr\Model\Channel;

use CommerceGears\EInvoicingCore\Api\Data\DocumentInterface;
use CommerceGears\EInvoicingCore\Exception\ChannelException;
use CommerceGears\EInvoicingCore\Model\Channel\AbstractChannel;
use CommerceGears\EInvoicingCore\Model\Channel\SendResult;
use CommerceGears\EInvoicingCore\Model\Data\Invoice;
use CommerceGears\EInvoicingCore\Model\Document;
use CommerceGears\EInvoicingTr\Model\Format\UblTr;

/**
 * Türkiye: Nilvera private integrator (e-Fatura and e-Arşiv). The buyer's VKN/TCKN is checked in GİB's
 * taxpayer list: registered → e-Fatura (TICARIFATURA by default), otherwise e-Arşiv. Nilvera signs and submits
 * to GİB. Beta: written from Nilvera's published OpenAPI (apitest.nilvera.com), tested with mocks.
 */
class NilveraChannel extends AbstractChannel
{
    public const CODE = 'tr_nilvera';
    private const BASES = ['test' => 'https://apitest.nilvera.com', 'live' => 'https://api.nilvera.com'];

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getLabel(): string
    {
        return (string)__('Nilvera (Türkiye e-Fatura / e-Arşiv)');
    }

    public function isBeta(): bool
    {
        return true;
    }

    public function getSupportedFormats(): array
    {
        return [UblTr::CODE];
    }

    public function isConfigured(int $storeId): bool
    {
        return $this->config->getSecret('tr/api_key', $storeId) !== '';
    }

    /**
     * e-Fatura alias of a registered taxpayer, or '' when the buyer is not an e-Fatura user (→ e-Arşiv).
     *
     * @throws ChannelException
     */
    public function eInvoiceAlias(string $taxId, int $storeId): string
    {
        if (!preg_match('/^\d{10,11}$/', $taxId) || $taxId === '11111111111') {
            return '';
        }
        $response = $this->http->request('GET', $this->base($storeId) . '/general/GlobalCompany/Check/TaxNumber/'
            . rawurlencode($taxId), $this->headers($storeId), null, ['timeout' => 20]);
        if ($response->status === 404) {
            return '';
        }
        if (!$response->isSuccess()) {
            throw new ChannelException(__('Nilvera taxpayer check failed: %1', $this->apiError($response)), $response->status >= 500);
        }
        foreach ($response->json() as $entry) {
            if (is_array($entry) && strcasecmp((string)($entry['DocumentType'] ?? ''), 'Invoice') === 0
                && strcasecmp((string)($entry['Type'] ?? 'PK'), 'PK') === 0 && !empty($entry['Name'])) {
                return (string)$entry['Name'];
            }
        }
        return '';
    }

    public function send(Document $document, string $xml, ?string $pdf, Invoice $invoice): SendResult
    {
        $storeId = $document->getStoreId();
        $isEArchive = str_contains($xml, '<cbc:ProfileID>EARSIVFATURA</cbc:ProfileID>');
        $boundary = 'commercegears' . bin2hex(random_bytes(8));
        $body = '--' . $boundary . "\r\n" . 'Content-Disposition: form-data; name="file"; filename="' . $document->getUuid()
            . ".xml\"\r\nContent-Type: application/xml\r\n\r\n" . $xml . "\r\n--" . $boundary . "--\r\n";
        $url = $this->base($storeId) . ($isEArchive ? '/earchive/Send/Xml' : '/einvoice/Send/Xml?Alias='
            . rawurlencode((string)($invoice->extra['tr_alias'] ?? '')));
        $response = $this->http->request('POST', $url, $this->headers($storeId)
            + ['Content-Type' => 'multipart/form-data; boundary=' . $boundary], $body,
            // Safe to repeat: Nilvera refuses a second invoice with the same ETTN (HTTP 409, handled below).
            ['log_body' => false, 'idempotent' => true]);
        if ($response->status === 409) {
            return new SendResult(DocumentInterface::STATUS_SENT, $document->getUuid(), $isEArchive ? 'earchive' : 'einvoice',
                'duplicate', (string)__('Nilvera already has this invoice (same ETTN); not sent twice.'));
        }
        if (!$response->isSuccess()) {
            throw new ChannelException(__('Nilvera did not accept the invoice: %1', $this->apiError($response)),
                in_array($response->status, [429, 502, 503, 504], true));
        }
        $data = $response->json();
        return new SendResult(DocumentInterface::STATUS_SENDING, (string)($data['UUID'] ?? $document->getUuid()),
            $isEArchive ? 'earchive' : 'einvoice', 'waiting',
            (string)__('Sent to Nilvera as %1, invoice number %2.', $isEArchive ? 'e-Arşiv' : 'e-Fatura', (string)($data['InvoiceNumber'] ?? '')));
    }

    public function checkStatus(Document $document): ?SendResult
    {
        $uuid = $document->getExternalId();
        if ($uuid === '') {
            return null;
        }
        $storeId = $document->getStoreId();
        $path = $document->getData('external_ref') === 'earchive' ? '/earchive/Invoices/' : '/einvoice/Sale/';
        $response = $this->http->request('GET', $this->base($storeId) . $path . rawurlencode($uuid) . '/Status', $this->headers($storeId));
        if (!$response->isSuccess()) {
            throw new ChannelException(__('Nilvera status: %1', $this->apiError($response)), $response->status >= 500);
        }
        $status = (array)($response->json()['InvoiceStatus'] ?? []);
        $code = strtolower((string)($status['Code'] ?? 'unknown'));
        $text = trim((string)($status['Description'] ?? '') . ' ' . (string)($status['DetailDescription'] ?? ''));
        return match ($code) {
            'succeed' => new SendResult(DocumentInterface::STATUS_DELIVERED, $uuid, '', 'succeed', $text),
            'error' => new SendResult(DocumentInterface::STATUS_REJECTED, $uuid, '', 'not_accepted:error', $text),
            default => new SendResult(DocumentInterface::STATUS_SENDING, $uuid, '', $code, $text),
        };
    }

    public function testConnection(int $storeId): string
    {
        if (!$this->isConfigured($storeId)) {
            throw new ChannelException(__('Enter the Nilvera API key first.'));
        }
        $response = $this->http->request('GET', $this->base($storeId) . '/general/Company', $this->headers($storeId));
        if (!$response->isSuccess()) {
            throw new ChannelException(__('Nilvera: %1', $this->apiError($response)));
        }
        return (string)__('Connected to Nilvera (%1).', $this->config->getValue('tr/environment', $storeId) ?: 'test');
    }

    private function base(int $storeId): string
    {
        return self::BASES[$this->config->getValue('tr/environment', $storeId)] ?? self::BASES['test'];
    }

    /**
     * @return array<string, string>
     */
    private function headers(int $storeId): array
    {
        $key = $this->config->getSecret('tr/api_key', $storeId);
        $this->http->addSecrets([$key]);
        return ['Authorization' => 'Bearer ' . $key, 'Accept' => 'application/json'];
    }
}
