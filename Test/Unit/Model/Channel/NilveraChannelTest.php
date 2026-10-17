<?php
/**
 * @copyright Copyright (c) CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\EInvoicingTr\Test\Unit\Model\Channel;

use CommerceGears\EInvoicingCore\Api\Data\DocumentInterface as D;
use CommerceGears\EInvoicingCore\Model\Config;
use CommerceGears\EInvoicingCore\Model\Data\Invoice;
use CommerceGears\EInvoicingCore\Model\Document;
use CommerceGears\EInvoicingCore\Model\Http\HttpClient;
use CommerceGears\EInvoicingCore\Model\Http\Masker;
use CommerceGears\EInvoicingCore\Model\Http\TransportInterface;
use CommerceGears\EInvoicingTr\Model\Channel\NilveraChannel;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class NilveraChannelTest extends TestCase
{
    /** @var RequestInterface[] */
    private array $requests = [];
    /** @var ResponseInterface[] */
    private array $queue = [];

    private function channel(): NilveraChannel
    {
        $transport = new class ($this) implements TransportInterface {
            public function __construct(private readonly NilveraChannelTest $test)
            {
            }

            public function send(RequestInterface $request, array $options = []): ResponseInterface
            {
                return $this->test->handle($request);
            }
        };
        $http = new HttpClient($transport, new Masker());
        $http->setSleeper(static fn () => null);
        $config = new class extends Config {
            public function __construct()
            {
            }

            public function getValue(string $path, ?int $storeId = null): string
            {
                return ['tr/api_key' => 'unit-test-key', 'tr/environment' => 'test'][$path] ?? '';
            }

            public function getSecret(string $path, ?int $storeId = null): string
            {
                return $this->getValue($path, $storeId);
            }
        };
        return new NilveraChannel($config, $http);
    }

    public function handle(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        return array_shift($this->queue) ?? new Response(500, [], '{}');
    }

    private function document(array $data): Document
    {
        $document = $this->createStub(Document::class);
        $document->method('getUuid')->willReturn($data['uuid'] ?? '34018020-aaaa-4bbb-8ccc-0123456789ab');
        $document->method('getStoreId')->willReturn(1);
        $document->method('getExternalId')->willReturn((string)($data['external_id'] ?? ''));
        $document->method('getData')->willReturnCallback(static fn ($key = '') => $data[$key] ?? null);
        return $document;
    }

    /** A: GİB returns the receiving mailboxes with the taxpayer type OZEL/KAMU, never "PK". */
    public function testAliasAcceptsOzelAndKamuAndPrefersDefaultPk(): void
    {
        $entries = [
            ['DocumentType' => 'Invoice', 'Name' => 'urn:mail:defaultpk-cgbilgi-3-6-2-a-2@mersel.io', 'Type' => 'OZEL'],
            ['DocumentType' => 'Invoice', 'Name' => 'urn:mail:testkurum1pk@koyuteknoloji1.com', 'Type' => 'OZEL'],
            ['DocumentType' => 'Invoice', 'Name' => 'urn:mail:defaultpk@nilvera.com', 'Type' => 'OZEL'],
            ['DocumentType' => 'Invoice', 'Name' => 'urn:mail:defaultpk@testkurum.com', 'Type' => 'OZEL'],
        ];
        self::assertSame(['alias' => 'urn:mail:defaultpk@nilvera.com', 'type' => 'OZEL'], NilveraChannel::pickAlias($entries));
        self::assertSame(['alias' => 'urn:mail:fatura@kurum.gov.tr', 'type' => 'KAMU'],
            NilveraChannel::pickAlias([['DocumentType' => 'Invoice', 'Name' => 'urn:mail:fatura@kurum.gov.tr', 'Type' => 'KAMU']]));
        self::assertSame('urn:mail:defaultpk@firma.com', NilveraChannel::pickAlias([
            ['DocumentType' => 'Invoice', 'Name' => 'urn:mail:defaultgb@firma.com', 'Type' => 'OZEL'],
            ['DocumentType' => 'Invoice', 'Name' => 'urn:mail:other@firma.com', 'Type' => 'GB'],
            ['DocumentType' => 'Invoice', 'Name' => 'urn:mail:defaultpk@firma.com', 'Type' => 'OZEL'],
        ])['alias']);
        self::assertNull(NilveraChannel::pickAlias([
            ['DocumentType' => 'DespatchAdvice', 'Name' => 'urn:mail:irsaliyepk@firma.com', 'Type' => 'OZEL'],
            ['DocumentType' => 'Invoice', 'Name' => 'urn:mail:defaultgb@firma.com', 'Type' => 'GB'],
        ]));
        self::assertNull(NilveraChannel::pickAlias([]));
    }

    public function testRecipientLookup(): void
    {
        $channel = $this->channel();
        $this->queue = [new Response(200, [], (string)json_encode([
            ['TaxNumber' => '1234567801', 'DocumentType' => 'Invoice', 'Name' => 'urn:mail:defaultpk@nilvera.com', 'Type' => 'OZEL']]))];
        self::assertSame('urn:mail:defaultpk@nilvera.com', $channel->eInvoiceAlias('1234567801', 1));
        self::assertStringContainsString('/general/GlobalCompany/Check/TaxNumber/1234567801', (string)end($this->requests)->getUri());
        $this->queue = [new Response(200, [], '[]')];
        self::assertSame('', $channel->eInvoiceAlias('10000000146', 1));
        $count = count($this->requests);
        self::assertSame('', $channel->eInvoiceAlias('11111111111', 1));
        self::assertCount($count, $this->requests, 'the anonymous consumer TCKN is not looked up');
    }

    /** B: the GİB number Nilvera assigns is stored as the external ID. */
    public function testSendStoresGibNumber(): void
    {
        $channel = $this->channel();
        $this->queue = [new Response(200, [], '{"UUID":"34018020-aaaa-4bbb-8ccc-0123456789ab","InvoiceNumber":"TST2026924101575"}')];
        $result = $channel->send($this->document([]), '<cbc:ProfileID>EARSIVFATURA</cbc:ProfileID>', null, new Invoice());
        self::assertSame(D::STATUS_SENDING, $result->status);
        self::assertSame('TST2026924101575', $result->externalId);
        self::assertSame('earchive', $result->externalRef);
        self::assertStringContainsString('34018020-AAAA-4BBB-8CCC-0123456789AB', $result->message);
        self::assertSame('https://apitest.nilvera.com/earchive/Send/Xml', (string)end($this->requests)->getUri());

        $invoice = new Invoice();
        $invoice->extra['tr_alias'] = 'urn:mail:defaultpk@nilvera.com';
        $this->queue = [new Response(200, [], '{"UUID":"34018020-aaaa-4bbb-8ccc-0123456789ab","InvoiceNumber":"TST2026999902211"}')];
        $result = $channel->send($this->document([]), '<cbc:ProfileID>TICARIFATURA</cbc:ProfileID>', null, $invoice);
        self::assertSame('einvoice', $result->externalRef);
        self::assertSame('TST2026999902211', $result->externalId);
        self::assertStringEndsWith('/einvoice/Send/Xml?Alias=urn%3Amail%3Adefaultpk%40nilvera.com', (string)end($this->requests)->getUri());
    }

    public function testDuplicateReadsNumberFromDetails(): void
    {
        $channel = $this->channel();
        $this->queue = [new Response(409, [], '{}'), new Response(200, [], '{"InvoiceNumber":"TST2026924101576"}')];
        $result = $channel->send($this->document([]), '<cbc:ProfileID>EARSIVFATURA</cbc:ProfileID>', null, new Invoice());
        self::assertSame('duplicate', $result->externalStatus);
        self::assertSame('TST2026924101576', $result->externalId);
        self::assertStringEndsWith('/earchive/Invoices/34018020-AAAA-4BBB-8CCC-0123456789AB/Details', (string)end($this->requests)->getUri());
    }

    public function testNilveraErrorDetailIsShown(): void
    {
        $channel = $this->channel();
        $this->queue = [new Response(422, [], '{"Message":"HATALI ISTEK","Errors":[{"Code":3009,"Description":"Seri bulunamadı.","Detail":"MAG serisi tanımlı değil."}]}')];
        try {
            $channel->send($this->document([]), '<cbc:ProfileID>EARSIVFATURA</cbc:ProfileID>', null, new Invoice());
            self::fail('no exception');
        } catch (\CommerceGears\EInvoicingCore\Exception\ChannelException $e) {
            self::assertStringContainsString('3009 Seri bulunamadı. MAG serisi tanımlı değil.', $e->getMessage());
        }
    }

    /** C: e-Arşiv status is at the top level (StatusCode, ReportStatus). */
    public function testEArchiveStatus(): void
    {
        $r = NilveraChannel::mapEArchiveStatus(['StatusCode' => 'succeed', 'ReportStatus' => 'Reported', 'StatusDetail' => 'Başarılı'], 'TST2026924101575');
        self::assertSame([D::STATUS_DELIVERED, 'succeed:reported', 'TST2026924101575'], [$r->status, $r->externalStatus, $r->externalId]);
        $r = NilveraChannel::mapEArchiveStatus(['StatusCode' => 'succeed', 'ReportStatus' => 'NotReported'], 'X');
        self::assertSame([D::STATUS_SENT, 'succeed:not_reported'], [$r->status, $r->externalStatus]);
        self::assertSame(D::STATUS_SENDING, NilveraChannel::mapEArchiveStatus(['StatusCode' => 'waiting'], '')->status);
        self::assertSame(D::STATUS_REJECTED, NilveraChannel::mapEArchiveStatus(['StatusCode' => 'error', 'StatusDetail' => 'x'], '')->status);
        self::assertSame(D::STATUS_CANCELLED, NilveraChannel::mapEArchiveStatus(['StatusCode' => 'succeed', 'CancelStatus' => true], '')->status);
        self::assertSame(D::STATUS_SENDING, NilveraChannel::mapEArchiveStatus([], '')->status);
        // what the test system answers for a signed invoice before the daily report
        $r = NilveraChannel::mapEArchiveStatus(['StatusDetail' => 'Rapor Oluşturulmadı.', 'StatusCode' => 'unknown',
            'ReportStatus' => 'NotReported', 'CancelStatus' => false], 'TST2026924101576');
        self::assertSame([D::STATUS_SENT, 'created:not_reported'], [$r->status, $r->externalStatus]);
    }

    public function testEInvoiceStatus(): void
    {
        $envelope = static fn (int $code): array => ['UUID' => 'e', 'GIBCode' => $code, 'GIBDescription' => 'desc'];
        $r = NilveraChannel::mapEInvoiceStatus(['InvoiceProfile' => 'TICARIFATURA', 'InvoiceStatus' => ['Code' => 'waiting'],
            'EnvelopeInfo' => $envelope(1200), 'Answer' => ['AnswerCode' => 'unknown']], 'TST2026999902211');
        self::assertSame([D::STATUS_SENDING, 'waiting:1200'], [$r->status, $r->externalStatus]);
        $r = NilveraChannel::mapEInvoiceStatus(['InvoiceProfile' => 'TICARIFATURA', 'InvoiceStatus' => ['Code' => 'succeed'],
            'EnvelopeInfo' => $envelope(1300), 'Answer' => ['AnswerCode' => 'waitingForApproval']], 'N');
        self::assertSame([D::STATUS_SENT, 'succeed:1300:awaiting_answer'], [$r->status, $r->externalStatus]);
        $r = NilveraChannel::mapEInvoiceStatus(['InvoiceProfile' => 'TICARIFATURA', 'InvoiceStatus' => ['Code' => 'succeed'],
            'EnvelopeInfo' => $envelope(1300), 'Answer' => ['AnswerCode' => 'approved']], 'N');
        self::assertSame([D::STATUS_DELIVERED, 'succeed:1300:approved'], [$r->status, $r->externalStatus]);
        $r = NilveraChannel::mapEInvoiceStatus(['InvoiceProfile' => 'TICARIFATURA', 'InvoiceStatus' => ['Code' => 'succeed'],
            'EnvelopeInfo' => $envelope(1300), 'Answer' => ['AnswerCode' => 'rejected', 'AnswerNote' => 'yanlış']], 'N');
        self::assertSame([D::STATUS_REJECTED, 'rejected_by_buyer:1300'], [$r->status, $r->externalStatus]);
        $r = NilveraChannel::mapEInvoiceStatus(['InvoiceProfile' => 'TEMELFATURA', 'InvoiceStatus' => ['Code' => 'succeed'],
            'EnvelopeInfo' => $envelope(1300)], 'N');
        self::assertSame([D::STATUS_DELIVERED, 'succeed:1300'], [$r->status, $r->externalStatus]);
        // as answered by the test system: delivered, no answer yet (Answer null)
        $r = NilveraChannel::mapEInvoiceStatus(['InvoiceProfile' => 'TICARIFATURA', 'Answer' => null, 'InvoiceStatus' => ['Code' => 'succeed',
            'Description' => 'FATURA ALICIYA İLETİLDİ İŞLEMLERİ TAMAMLANDI'], 'EnvelopeInfo' => ['GIBCode' => 1300, 'GIBDescription' => 'BAŞARIYLA TAMAMLANDI']], 'N');
        self::assertSame([D::STATUS_SENT, 'succeed:1300:awaiting_answer'], [$r->status, $r->externalStatus]);
        $r = NilveraChannel::mapEInvoiceStatus(['InvoiceProfile' => 'TEMELFATURA', 'InvoiceStatus' => ['Code' => 'error'],
            'EnvelopeInfo' => $envelope(1163)], 'N');
        self::assertSame([D::STATUS_REJECTED, 'error:1163'], [$r->status, $r->externalStatus]);
        self::assertStringContainsString('GİB 1163: desc', $r->message);
    }

    public function testCheckStatusUsesTheRightEndpoint(): void
    {
        $channel = $this->channel();
        $this->queue = [new Response(200, [], '{"StatusCode":"succeed","ReportStatus":"Reported","StatusDetail":"ok"}')];
        $r = $channel->checkStatus($this->document(['external_ref' => 'earchive', 'external_id' => 'TST2026924101575', 'sent_at' => '2026-10-17 10:00:00']));
        self::assertSame(D::STATUS_DELIVERED, $r?->status);
        self::assertStringEndsWith('/earchive/Invoices/34018020-AAAA-4BBB-8CCC-0123456789AB/Status', (string)end($this->requests)->getUri());
        $this->queue = [new Response(200, [], '{"InvoiceProfile":"TEMELFATURA","InvoiceStatus":{"Code":"succeed"},"EnvelopeInfo":{"GIBCode":1300}}')];
        $r = $channel->checkStatus($this->document(['external_ref' => 'einvoice', 'external_id' => 'TST2026999902211', 'sent_at' => '2026-10-17 10:00:00']));
        self::assertSame([D::STATUS_DELIVERED, 'succeed:1300'], [$r?->status, $r?->externalStatus]);
        self::assertStringEndsWith('/einvoice/Sale/34018020-AAAA-4BBB-8CCC-0123456789AB/Status', (string)end($this->requests)->getUri());
        self::assertNull($channel->checkStatus($this->document([])), 'not sent yet: no status call');
    }

    public function testDefaultSeries(): void
    {
        $channel = $this->channel();
        $this->queue = [new Response(200, [], '{"Content":[{"Name":"10X","IsDefault":true,"IsActive":true}]}')];
        self::assertSame('10X', $channel->defaultSeries(true, 1));
        self::assertSame('10X', $channel->defaultSeries(true, 1), 'cached');
        self::assertCount(1, $this->requests);
        self::assertStringContainsString('/earchive/Series?IsDefault=true', (string)$this->requests[0]->getUri());
    }
}
