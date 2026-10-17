<?php
/**
 * Türkiye end-to-end against Nilvera's TEST system (only with "remote", only when the Nilvera environment is "test"
 * and an API key of a Nilvera test account is set): a test order of the account's own company (an e-Fatura user)
 * becomes an e-Fatura (TICARIFATURA) numbered by Nilvera in the account's default series, GİB delivers it (1300),
 * and its refund becomes a return invoice (IADE, TEMELFATURA) that refers to the GİB number. The test order is
 * removed afterwards; the invoices stay in Nilvera's test system (not reported to GİB as real invoices).
 */
declare(strict_types=1);

$kit = null;
foreach ([dirname(__DIR__, 3) . '/EInvoicingCore/Test/Live', dirname(__DIR__, 3) . '/module-e-invoicing-core/Test/Live'] as $dir) {
    if (is_file($dir . '/bootstrap.php')) {
        $kit = $dir;
        break;
    }
}
require $kit . '/bootstrap.php';
$config = om()->get(\CommerceGears\EInvoicingCore\Model\Config::class);
if (!$remote || ($config->getValue('tr/environment', 2) ?: 'test') !== 'test' || $config->getSecret('tr/api_key', 2) === '') {
    echo "  SKIP: needs 'remote', the Nilvera test environment and a test API key\n";
    done();
}
$channel = om()->get(\CommerceGears\EInvoicingTr\Model\Channel\NilveraChannel::class);
$company = (string)$channel->testConnection(2);
check(str_contains($company, 'VKN/TCKN'), 'Nilvera test account: ' . $company);
preg_match('/VKN\/TCKN (\d{10,11})/', $company, $m);
$vkn = $m[1] ?? '';
$writer = om()->get(\Magento\Framework\App\Config\Storage\WriterInterface::class);
$paths = ['seller/vat_id' => '', 'seller/tax_number' => $vkn, 'seller/tax_office' => 'Karesi', 'seller/legal_name' => 'Test Kurum Bir',
    'seller/street' => 'Test Cad. 1', 'seller/postcode' => '10030', 'seller/city' => 'Balıkesir', 'seller/country_id' => 'TR',
    'seller/endpoint_scheme' => '', 'seller/endpoint_id' => ''];
foreach ($paths as $p => $v) { $writer->save('commercegears_einvoicing/' . $p, $v, 'stores', 2); }
om()->get(\Magento\Framework\App\Config\ReinitableConfigInterface::class)->reinit();
$poll = static function ($doc, int $seconds) use ($service) {
    $until = time() + $seconds;
    while ($doc->getStatus() === 'sending' && time() < $until) {
        sleep(10);
        $service->checkStatus($service->load((int)$doc->getId()), 'test');
        $doc = $service->load((int)$doc->getId());
    }
    return $doc;
};
try {
    $o = ei_order('TR', ['street' => 'Test Cad. 2', 'city' => 'Balıkesir', 'postcode' => '10030', 'email' => 'test-tr-e2e@example.com',
        'details' => ['is_business' => 1, 'tax_id' => $vkn, 'tax_office' => 'Karesi']],
        [['TEST-SKU-TR1', 'Test Çanta', 2, 500.00, 20, 0], ['TEST-SKU-TR2', 'Test Matara', 1, 100.00, 20, 0]], 50.00, 20, 'banktransfer', 'TRY', 2);
    $i = ei_invoice($o);
    $d = gen('tr e-fatura e2e', $i);
    $xml = $d ? $service->readXml($d) : '';
    check(str_contains($xml, '<cbc:ProfileID>TICARIFATURA</cbc:ProfileID>'), 'e-Fatura user → TICARIFATURA');
    check((bool)preg_match('#<cbc:ProfileID>TICARIFATURA</cbc:ProfileID>\s*<cbc:ID>([A-Z0-9]{3})</cbc:ID>#', $xml, $s), 'XML carries the series only: ' . ($s[1] ?? '-'));
    $d = $service->send($d, 'test');
    $d = $service->load((int)$d->getId());
    $number = $d->getExternalId();
    check((bool)preg_match('/^' . ($s[1] ?? 'XXX') . date('Y') . '\d{9}$/', $number), "Nilvera assigned the GİB number $number (ETTN " . strtoupper($d->getUuid()) . ')');
    $d = $poll($d, 180);
    check(in_array($d->getStatus(), ['sent', 'delivered'], true) && str_contains((string)$d->getData('external_status'), '1300'),
        'GİB delivered the e-Fatura: ' . $d->getStatus() . ' ' . $d->getData('external_status'));
    $cm = ei_creditmemo($i, [1, 0]);
    $c = gen('tr e-fatura return e2e', $cm);
    $cxml = $c ? $service->readXml($c) : '';
    check(str_contains($cxml, '<cbc:ProfileID>TEMELFATURA</cbc:ProfileID>') && str_contains($cxml, '<cbc:InvoiceTypeCode>IADE</cbc:InvoiceTypeCode>'),
        'return invoice: IADE as TEMELFATURA');
    check(str_contains(preg_replace('/>\s+</', '><', $cxml), '<cac:InvoiceDocumentReference><cbc:ID>' . $number . '</cbc:ID>'), 'return invoice refers to ' . $number);
    $c = $service->send($c, 'test');
    $c = $poll($service->load((int)$c->getId()), 180);
    check((bool)preg_match(\CommerceGears\EInvoicingTr\Model\Channel\NilveraChannel::GIB_NUMBER, $c->getExternalId())
        && in_array($c->getStatus(), ['sent', 'delivered'], true),
        'return invoice accepted: ' . $c->getExternalId() . ' ETTN ' . strtoupper($c->getUuid()) . ' ' . $c->getStatus() . ' ' . $c->getData('external_status')
        . ($c->getLastError() ? ' ' . $c->getLastError() : ''));
    // private buyer → e-Arşiv in the account's default e-Arşiv series
    $o2 = ei_order('TR', ['street' => 'Moda Cad. 45', 'city' => 'İstanbul', 'postcode' => '34710', 'email' => 'test-tr-e2e2@example.com',
        'details' => ['tax_id' => '10000000146']], [['TEST-SKU-TR1', 'Test Çanta', 1, 500.00, 20, 0]], 50.00, 20, 'checkmo', 'TRY', 2);
    $a = gen('tr e-arsiv e2e', ei_invoice($o2));
    $axml = $a ? $service->readXml($a) : '';
    check((bool)preg_match('#<cbc:ProfileID>EARSIVFATURA</cbc:ProfileID>\s*<cbc:ID>([A-Z0-9]{3})</cbc:ID>#', $axml, $as), 'e-Arşiv, series ' . ($as[1] ?? '-'));
    $a = $poll($service->load((int)$service->send($a, 'test')->getId()), 120);
    check((bool)preg_match(\CommerceGears\EInvoicingTr\Model\Channel\NilveraChannel::GIB_NUMBER, $a->getExternalId())
        && in_array($a->getStatus(), ['sent', 'delivered'], true),
        'e-Arşiv accepted: ' . $a->getExternalId() . ' ETTN ' . strtoupper($a->getUuid()) . ' ' . $a->getStatus() . ' ' . $a->getData('external_status')
        . ($a->getLastError() ? ' ' . $a->getLastError() : ''));
} catch (\Throwable $e) {
    check(false, 'exception: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    foreach (array_keys($paths) as $p) { $writer->delete('commercegears_einvoicing/' . $p, 'stores', 2); }
    om()->get(\Magento\Framework\App\Cache\TypeListInterface::class)->cleanType('config');
    ei_cleanup();
}
done();
