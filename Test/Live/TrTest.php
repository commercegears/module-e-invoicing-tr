<?php
/**
 * Core + Türkiye (beta): UBL-TR e-Arşiv for a Turkish test seller, VKN/TCKN, Nilvera adapter (mock transport).
 */
declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use CommerceGears\EInvoicingCore\Model\Http\HttpClient;

$kit = null;
foreach ([dirname(__DIR__, 3) . '/EInvoicingCore/Test/Live', dirname(__DIR__, 3) . '/module-e-invoicing-core/Test/Live'] as $dir) {
    if (is_file($dir . '/bootstrap.php')) {
        $kit = $dir;
        break;
    }
}
require $kit . '/bootstrap.php';
$writer = om()->get(\Magento\Framework\App\Config\Storage\WriterInterface::class);
$paths = ['seller/vat_id' => '', 'seller/tax_number' => '1234567890', 'seller/tax_office' => 'Test Vergi Dairesi', 'seller/legal_name' => 'Test Satıcı A.Ş.',
    'seller/street' => 'Test Cad. 1', 'seller/postcode' => '34000', 'seller/city' => 'İstanbul', 'seller/country_id' => 'TR', 'seller/endpoint_scheme' => '', 'seller/endpoint_id' => '',
    // unshipped test order: the internet sale details are tested in TrNilveraE2eTest and the unit tests
    'tr/internet_sale' => '0'];
foreach ($paths as $p => $v) { $writer->save('commercegears_einvoicing/' . $p, $v, 'stores', 2); }
om()->get(\Magento\Framework\App\Config\ReinitableConfigInterface::class)->reinit();
try {
    $tax = \CommerceGears\EInvoicingTr\Model\Buyer\TaxIdField::class;
    check($tax::valid('1234567890') && $tax::valid('11111111110') && !$tax::valid('11111111111'), 'VKN/TCKN checksums');
    $o = ei_order('TR', ['street' => 'Test Sok. 5', 'city' => 'Ankara', 'postcode' => '06000', 'email' => 'test-tr@example.com',
        'details' => ['tax_id' => '11111111110']], [['TEST-SKU-TR1', 'Test Çanta', 1, 500.00, 20, 0]], 50.00, 20, 'banktransfer', 'TRY', 2);
    $i = ei_invoice($o);
    $d = gen('tr consumer e-arsiv', $i);
    check($d?->getFormat() === 'ubl_tr' && $d?->getChannel() === 'tr_nilvera', 'Turkish seller → UBL-TR through Nilvera (also consumers)');
    $xml = $d ? $service->readXml($d) : '';
    check(str_contains($xml, '<cbc:ProfileID>EARSIVFATURA</cbc:ProfileID>') && str_contains($xml, 'schemeID="TCKN">11111111110<'), 'e-Arşiv with the buyer TCKN');
    check(str_contains($xml, '<cbc:UUID>' . strtoupper($d->getUuid()) . '</cbc:UUID>'), 'ETTN = document UUID');
} catch (\Throwable $e) {
    check(false, 'exception: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    foreach (array_keys($paths) as $p) { $writer->delete('commercegears_einvoicing/' . $p, 'stores', 2); }
    om()->get(\Magento\Framework\App\Cache\TypeListInterface::class)->cleanType('config');
    ei_cleanup();
}
require $kit . '/mocks.php';
// Nilvera (TR): taxpayer check, e-Fatura with alias, status
$cfg->values = ['tr/api_key' => 'nil_test_key_777', 'tr/environment' => 'test'];
$n = new \CommerceGears\EInvoicingTr\Model\Channel\NilveraChannel($cfg, $http);
// GİB's list gives the taxpayer type (OZEL / KAMU) in "Type", not the mailbox type
$mock->queue = [new Response(200, [], '[{"TaxNumber":"1234567890","Title":"TEST AŞ","DocumentType":"Invoice","Name":"urn:mail:defaultpk@test.com","Type":"OZEL"}]')];
check($n->eInvoiceAlias('1234567890', 1) === 'urn:mail:defaultpk@test.com', 'Nilvera: registered taxpayer → e-Fatura alias');
check(str_contains((string)end($mock->requests)->getUri(), '/general/GlobalCompany/Check/TaxNumber/1234567890?globalUserType=Invoice'), 'Nilvera: GİB list check endpoint');
$mock->queue = [new Response(200, [], '[]')];
check($n->eInvoiceAlias('11111111110', 1) === '', 'Nilvera: not registered → e-Arşiv');
// UBL-TR document from a TR test order (not saved): build model and check the format
$fmt = new \CommerceGears\EInvoicingTr\Model\Format\UblTr();
$inv = sample_invoice(function ($i) { $i->currency = 'TRY'; $i->seller->taxNumber = '1234567890'; $i->seller->taxOffice = 'Test VD'; $i->seller->address = new \CommerceGears\EInvoicingCore\Model\Data\Address(['Test Cad. 1'], 'İstanbul', '34000', 'TR');
    $i->buyer->vatId = ''; $i->buyer->taxNumber = '11111111110'; $i->buyer->isPerson = true; $i->buyer->name = 'Test Alıcı'; foreach ($i->lines as $l) { $l->vatRate = 20.0; $l->taxAmount = round($l->netAmount * 0.2, 2); } foreach ($i->allowanceCharges as $a) { $a->vatRate = 20.0; }
    $i->number = '000000123'; $i->extra = ['uuid' => 'F47AC10B-58CC-4372-A567-0E02B2C3D479', 'tr_prefix' => 'MAG', 'tr_profile' => 'EARSIVFATURA']; });
$trXml = $fmt->toXml($inv);
file_put_contents(EI_OUT . '/golden/ubl-tr-earsiv.xml', $trXml);
$xr = om()->get(\CommerceGears\EInvoicingCore\Model\Validation\XsdValidator::class)->validate($trXml, $fmt->getSchemaFile($inv));
check($xr->isValid(), 'UBL-TR e-Arşiv: UBL 2.1 schema valid ' . implode('; ', $xr->errors));
check(!$fmt->check($inv), 'UBL-TR rules: ' . implode('; ', $fmt->check($inv)));
check(str_contains($trXml, '<cbc:ID>MAG2026000000123</cbc:ID>') && str_contains($trXml, 'schemeID="TCKN"'), 'UBL-TR: GİB number format, TCKN buyer');
$tdoc = om()->create(\CommerceGears\EInvoicingCore\Model\Document::class)->setData(['uuid' => 'f47ac10b-58cc-4372-a567-0e02b2c3d479', 'store_id' => 1]);
$mock->queue = [new Response(200, [], '{"UUID":"f47ac10b-58cc-4372-a567-0e02b2c3d479","InvoiceNumber":"MAG2026000000123"}')];
$r = $n->send($tdoc, $trXml, null, $inv);
check((string)end($mock->requests)->getUri() === 'https://apitest.nilvera.com/earchive/Send/Xml' && $r->status === 'sending' && $r->externalRef === 'earchive', 'Nilvera: e-Arşiv XML upload');
check($r->externalId === 'MAG2026000000123', 'Nilvera: GİB number stored as the network ID');
$tdoc->setData('external_id', 'MAG2026000000123')->setData('external_ref', 'earchive')->setData('sent_at', '2026-10-17 10:00:00');
$mock->queue = [new Response(200, [], '{"StatusCode":"succeed","ReportStatus":"Reported","StatusDetail":"Başarılı"}')];
check($n->checkStatus($tdoc)?->status === 'delivered', 'Nilvera: e-Arşiv succeed + reported → delivered');
$mock->queue = [new Response(200, [], '{"StatusCode":"waiting","ReportStatus":"NotReported"}')];
check($n->checkStatus($tdoc)?->status === 'sending', 'Nilvera: e-Arşiv waiting → still sending');
$mock->queue = [new Response(409, [], '{}'), new Response(200, [], '{"InvoiceNumber":"MAG2026000000123"}')];
check($n->send($tdoc, $trXml, null, $inv)->externalStatus === 'duplicate', 'Nilvera: 409 duplicate → not sent twice');
check(!str_contains($http->drainCalls(), 'nil_test_key_777'), 'Nilvera key masked');
// Timeout after the upload: repeated with the same ETTN, Nilvera answers 409 → recorded once, not sent twice
$mock->queue = [new \GuzzleHttp\Exception\ConnectException('cURL error 28: Operation timed out after 60001 milliseconds with 0 bytes received',
    new \GuzzleHttp\Psr7\Request('POST', 'https://apitest.nilvera.com/earchive/Send/Xml'), null, ['errno' => 28]), new Response(409, [], '{}'),
    new Response(200, [], '{"InvoiceNumber":"MAG2026000000123"}')];
check($n->send($tdoc, $trXml, null, $inv)->externalStatus === 'duplicate', 'Nilvera: timeout → repeated with the same ETTN → 409 duplicate, not sent twice');
$http->drainCalls();

done();
