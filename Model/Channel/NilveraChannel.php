<?php
/**
 * @copyright Copyright (c) CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\EInvoicingTr\Model\Channel;

use CommerceGears\EInvoicingCore\Api\Data\DocumentInterface;
use CommerceGears\EInvoicingCore\Exception\ChannelException;
use CommerceGears\EInvoicingCore\Model\Channel\AbstractChannel;
use CommerceGears\EInvoicingCore\Model\Channel\OfficialNumberChannelInterface;
use CommerceGears\EInvoicingCore\Model\Channel\SendResult;
use CommerceGears\EInvoicingCore\Model\Data\Invoice;
use CommerceGears\EInvoicingCore\Model\Document;
use CommerceGears\EInvoicingCore\Model\Http\Response;
use CommerceGears\EInvoicingTr\Model\Format\UblTr;

/**
 * Türkiye: Nilvera private integrator (e-Fatura and e-Arşiv). The buyer's VKN/TCKN is checked in GİB's
 * taxpayer list: registered → e-Fatura (TICARIFATURA by default), otherwise e-Arşiv. Nilvera numbers the invoice in
 * the series given in the XML (cbc:ID = 3-character series code), signs it and submits it to GİB. The GİB invoice
 * number Nilvera returns is stored as the document's network ID; the ETTN is the document UUID.
 * Tested against Nilvera's test system (apitest.nilvera.com).
 */
class NilveraChannel extends AbstractChannel implements OfficialNumberChannelInterface
{
    public const CODE = 'tr_nilvera';
    /** GİB invoice number: 3-character series + year + 9-digit sequence. */
    public const GIB_NUMBER = '/^[A-Z0-9]{3}\d{13}$/';
    private const BASES = ['test' => 'https://apitest.nilvera.com', 'live' => 'https://api.nilvera.com'];

    /** @var array<string, string> default series per store and document type (this process) */
    private array $defaultSeries = [];

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

    public function getOfficialNumberLabel(): string
    {
        return (string)__('GİB invoice number');
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
        return $this->eInvoiceRecipient($taxId, $storeId)['alias'] ?? '';
    }

    /**
     * GİB e-Fatura recipient (alias of the buyer's receiving mailbox and taxpayer type OZEL/KAMU), or null when the
     * buyer is not an e-Fatura user. GİB's list holds the receiving (PK) mailboxes; "Type" is the taxpayer type
     * (OZEL = private sector, KAMU = public sector), not the mailbox type. Sender mailboxes (GB) are skipped. With
     * several mailboxes the default one ("defaultpk") is used, at Nilvera if the buyer has one there.
     *
     * @return array{alias: string, type: string}|null
     * @throws ChannelException
     */
    public function eInvoiceRecipient(string $taxId, int $storeId): ?array
    {
        if (!preg_match('/^\d{10,11}$/', $taxId) || $taxId === '11111111111') {
            return null;
        }
        $response = $this->http->request('GET', $this->base($storeId) . '/general/GlobalCompany/Check/TaxNumber/'
            . rawurlencode($taxId) . '?globalUserType=Invoice', $this->headers($storeId), null, ['timeout' => 20]);
        if ($response->status === 404) {
            return null;
        }
        if (!$response->isSuccess()) {
            throw new ChannelException(__('Nilvera taxpayer check failed: %1', $this->nilveraError($response)), $response->status >= 500);
        }
        return self::pickAlias($response->json());
    }

    /**
     * @param array<int|string, mixed> $entries GlobalCompany/Check/TaxNumber response
     * @return array{alias: string, type: string}|null
     */
    public static function pickAlias(array $entries): ?array
    {
        $best = null;
        $bestScore = -1;
        foreach ($entries as $entry) {
            if (!is_array($entry) || empty($entry['Name'])
                || strcasecmp((string)($entry['DocumentType'] ?? 'Invoice'), 'Invoice') !== 0) {
                continue;
            }
            $type = strtoupper((string)($entry['Type'] ?? ''));
            $alias = (string)$entry['Name'];
            $local = strtolower((string)preg_replace('/^urn:mail:/i', '', strstr($alias, '@', true) ?: $alias));
            if ($type === 'GB' || preg_match('/gb$/', $local)) {
                continue;
            }
            $score = ($local === 'defaultpk' ? 8 : 0) + (str_contains($local, 'defaultpk') ? 4 : 0)
                + (str_contains($local, 'pk') ? 2 : 0) + (str_ends_with(strtolower($alias), '@nilvera.com') ? 1 : 0);
            if ($score > $bestScore) {
                $best = ['alias' => $alias, 'type' => in_array($type, ['OZEL', 'KAMU'], true) ? $type : 'OZEL'];
                $bestScore = $score;
            }
        }
        return $best;
    }

    /**
     * Default active series of the Nilvera account for e-Fatura or e-Arşiv ('' when none is marked as default).
     *
     * @throws ChannelException
     */
    public function defaultSeries(bool $eArchive, int $storeId): string
    {
        $cacheKey = $storeId . ($eArchive ? 'a' : 'f');
        if (!isset($this->defaultSeries[$cacheKey])) {
            $response = $this->http->request('GET', $this->base($storeId) . ($eArchive ? '/earchive' : '/einvoice')
                . '/Series?IsDefault=true&IsActive=true&PageSize=10', $this->headers($storeId), null, ['timeout' => 20]);
            if (!$response->isSuccess()) {
                throw new ChannelException(__('Nilvera series list: %1', $this->nilveraError($response)), $response->status >= 500);
            }
            $name = '';
            foreach ((array)($response->json()['Content'] ?? []) as $series) {
                if (is_array($series) && !empty($series['IsDefault']) && ($series['IsActive'] ?? true)
                    && preg_match('/^[A-Z0-9]{3}$/', (string)($series['Name'] ?? ''))) {
                    $name = (string)$series['Name'];
                    break;
                }
            }
            $this->defaultSeries[$cacheKey] = $name;
        }
        return $this->defaultSeries[$cacheKey];
    }

    public function send(Document $document, string $xml, ?string $pdf, Invoice $invoice): SendResult
    {
        $storeId = $document->getStoreId();
        $isEArchive = str_contains($xml, '<cbc:ProfileID>EARSIVFATURA</cbc:ProfileID>');
        $kind = $isEArchive ? 'earchive' : 'einvoice';
        $alias = (string)($invoice->extra['tr_alias'] ?? '');
        if (!$isEArchive && $alias === '') {
            throw new ChannelException(__('The buyer is no longer in GİB\'s e-Fatura list: generate the invoice again (it becomes an e-Arşiv invoice).'));
        }
        $boundary = 'commercegears' . bin2hex(random_bytes(8));
        $body = '--' . $boundary . "\r\n" . 'Content-Disposition: form-data; name="file"; filename="' . $document->getUuid()
            . ".xml\"\r\nContent-Type: application/xml\r\n\r\n" . $xml . "\r\n--" . $boundary . "--\r\n";
        $url = $this->base($storeId) . ($isEArchive ? '/earchive/Send/Xml' : '/einvoice/Send/Xml?Alias=' . rawurlencode($alias));
        $response = $this->http->request('POST', $url, $this->headers($storeId)
            + ['Content-Type' => 'multipart/form-data; boundary=' . $boundary], $body,
            // Safe to repeat: Nilvera refuses a second invoice with the same ETTN (HTTP 409, handled below).
            ['log_body' => false, 'idempotent' => true]);
        $ettn = strtoupper($document->getUuid());
        if ($response->status === 409) {
            $number = $this->invoiceNumber($kind, $ettn, $storeId);
            return new SendResult(DocumentInterface::STATUS_SENDING, $number, $kind, 'duplicate',
                (string)__('Nilvera already has this invoice (ETTN %1, GİB number %2); not sent twice.', $ettn, $number ?: '-'));
        }
        if (!$response->isSuccess()) {
            throw new ChannelException(__('Nilvera did not accept the invoice: %1', $this->nilveraError($response)),
                in_array($response->status, [429, 502, 503, 504], true));
        }
        $data = $response->json();
        $number = (string)($data['InvoiceNumber'] ?? '');
        if (!preg_match(self::GIB_NUMBER, $number)) {
            $number = $this->invoiceNumber($kind, $ettn, $storeId);
        }
        return new SendResult(DocumentInterface::STATUS_SENDING, $number, $kind, 'waiting',
            (string)__('Sent to Nilvera as %1: GİB number %2, ETTN %3.', $isEArchive ? 'e-Arşiv' : 'e-Fatura', $number ?: '-',
                strtoupper((string)($data['UUID'] ?? $ettn))));
    }

    /**
     * e-Arşiv: final once Nilvera has signed the invoice and reported it to GİB. e-Fatura: final once GİB delivered
     * the envelope (1300) and, for TICARIFATURA, the buyer accepted or rejected it.
     */
    public function checkStatus(Document $document): ?SendResult
    {
        $ettn = strtoupper($document->getUuid());
        if ($ettn === '' || !$document->getData('sent_at')) {
            return null;
        }
        $storeId = $document->getStoreId();
        $isEArchive = $document->getData('external_ref') === 'earchive';
        $kind = $isEArchive ? 'earchive' : 'einvoice';
        $response = $this->http->request('GET', $this->base($storeId) . ($isEArchive ? '/earchive/Invoices/' : '/einvoice/Sale/')
            . rawurlencode($ettn) . '/Status', $this->headers($storeId));
        if (!$response->isSuccess()) {
            throw new ChannelException(__('Nilvera status: %1', $this->nilveraError($response)), $response->status >= 500);
        }
        $number = $document->getExternalId();
        if (!preg_match(self::GIB_NUMBER, $number)) {
            $number = $this->invoiceNumber($kind, $ettn, $storeId);
        }
        return $isEArchive ? self::mapEArchiveStatus($response->json(), $number)
            : self::mapEInvoiceStatus($response->json(), $number);
    }

    /**
     * @param array<string, mixed> $data earchive/Invoices/{UUID}/Status
     */
    public static function mapEArchiveStatus(array $data, string $number): SendResult
    {
        $code = strtolower((string)($data['StatusCode'] ?? 'unknown'));
        $reported = strcasecmp((string)($data['ReportStatus'] ?? ''), 'Reported') === 0;
        $text = trim((string)($data['StatusDetail'] ?? ''));
        if (!empty($data['CancelStatus'])) {
            return new SendResult(DocumentInterface::STATUS_CANCELLED, $number, '', 'cancelled',
                trim((string)__('Cancelled at Nilvera.') . ' ' . $text));
        }
        return match ($code) {
            'succeed' => $reported
                ? new SendResult(DocumentInterface::STATUS_DELIVERED, $number, '', 'succeed:reported',
                    trim((string)__('e-Arşiv invoice created and reported to GİB.') . ' ' . $text))
                // signed and valid; GİB report follows (Nilvera reports e-Arşiv invoices to GİB once a day)
                : new SendResult(DocumentInterface::STATUS_SENT, $number, '', 'succeed:not_reported',
                    trim((string)__('e-Arşiv invoice created; waiting for the daily report to GİB.') . ' ' . $text)),
            'error' => new SendResult(DocumentInterface::STATUS_REJECTED, $number, '', 'error',
                trim((string)__('Nilvera reported an error:') . ' ' . $text)),
            // "unknown" + "Rapor Oluşturulmadı": signed and numbered, the daily GİB report has not been made yet
            'unknown' => $number !== ''
                ? new SendResult(DocumentInterface::STATUS_SENT, $number, '', 'created:not_reported',
                    trim((string)__('e-Arşiv invoice created; waiting for the daily report to GİB.') . ' ' . $text))
                : new SendResult(DocumentInterface::STATUS_SENDING, $number, '', $code, $text),
            default => new SendResult(DocumentInterface::STATUS_SENDING, $number, '', $code, $text),
        };
    }

    /**
     * @param array<string, mixed> $data einvoice/Sale/{UUID}/Status
     */
    public static function mapEInvoiceStatus(array $data, string $number): SendResult
    {
        $status = (array)($data['InvoiceStatus'] ?? []);
        $envelope = (array)($data['EnvelopeInfo'] ?? []);
        $code = strtolower((string)($status['Code'] ?? 'unknown'));
        $gibCode = (int)($envelope['GIBCode'] ?? 0);
        $answer = (string)(((array)($data['Answer'] ?? []))['AnswerCode'] ?? 'unknown');
        $commercial = strtoupper((string)($data['InvoiceProfile'] ?? '')) === 'TICARIFATURA';
        $gibText = trim((string)($envelope['GIBDescription'] ?? ''));
        $text = trim(implode(' ', array_filter([
            (string)($status['Description'] ?? ''), (string)($status['DetailDescription'] ?? ''),
            $gibCode ? 'GİB ' . $gibCode . ($gibText !== '' ? ': ' . $gibText : '') : '',
        ])));
        $external = $code . ($gibCode ? ':' . $gibCode : '');
        if ($code === 'error') {
            return new SendResult(DocumentInterface::STATUS_REJECTED, $number, '', $external, $text);
        }
        if ($code !== 'succeed') {
            return new SendResult(DocumentInterface::STATUS_SENDING, $number, '', $external, $text);
        }
        if ($commercial && $answer === 'rejected') {
            return new SendResult(DocumentInterface::STATUS_REJECTED, $number, '', 'rejected_by_buyer' . ($gibCode ? ':' . $gibCode : ''),
                trim((string)__('The buyer rejected the commercial invoice.') . ' '
                    . (string)(((array)($data['Answer'] ?? []))['AnswerNote'] ?? '')));
        }
        if ($commercial && !in_array($answer, ['approved', 'documentAnsweredAutomatically'], true)) {
            // delivered to the buyer; a TICARIFATURA can still be accepted or rejected for 8 days
            return new SendResult(DocumentInterface::STATUS_SENT, $number, '', $external . ':awaiting_answer',
                trim(($text !== '' ? rtrim($text, '.') . '. ' : '') . (string)__('Waiting for the buyer to accept or reject it.')));
        }
        return new SendResult(DocumentInterface::STATUS_DELIVERED, $number, '', $external . ($commercial ? ':' . $answer : ''), $text);
    }

    public function testConnection(int $storeId): string
    {
        if (!$this->isConfigured($storeId)) {
            throw new ChannelException(__('Enter the Nilvera API key first.'));
        }
        $response = $this->http->request('GET', $this->base($storeId) . '/general/Company', $this->headers($storeId));
        if (!$response->isSuccess()) {
            throw new ChannelException(__('Nilvera: %1', $this->nilveraError($response)));
        }
        $company = $response->json();
        $environment = $this->config->getValue('tr/environment', $storeId) ?: 'test';
        if (!empty($company['TaxNumber'])) {
            return (string)__('Connected to Nilvera (%1): %2, VKN/TCKN %3.', $environment,
                (string)($company['Name'] ?? ''), (string)$company['TaxNumber']);
        }
        return (string)__('Connected to Nilvera (%1).', $environment);
    }

    /** GİB number of an invoice Nilvera already has ('' when it cannot be read). */
    private function invoiceNumber(string $kind, string $ettn, int $storeId): string
    {
        try {
            $response = $this->http->request('GET', $this->base($storeId) . ($kind === 'earchive' ? '/earchive/Invoices/' : '/einvoice/Sale/')
                . rawurlencode($ettn) . '/Details', $this->headers($storeId), null, ['timeout' => 20]);
        } catch (\Throwable) {
            return '';
        }
        $number = $response->isSuccess() ? (string)($response->json()['InvoiceNumber'] ?? '') : '';
        return preg_match(self::GIB_NUMBER, $number) ? $number : '';
    }

    /** Nilvera's error body: {"Message": "...", "Errors": [{"Code": 2015, "Description": "...", "Detail": "..."}]}. */
    private function nilveraError(Response $response): string
    {
        $data = $response->json();
        $parts = [];
        foreach ((array)($data['Errors'] ?? []) as $error) {
            if (is_array($error)) {
                $parts[] = trim(($error['Code'] ?? '') . ' ' . ($error['Description'] ?? '') . ' ' . ($error['Detail'] ?? ''));
            }
        }
        if (!$parts) {
            return $this->apiError($response);
        }
        return 'HTTP ' . $response->status . ': ' . mb_substr(implode('; ', $parts), 0, 1000);
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
