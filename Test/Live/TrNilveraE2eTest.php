<?php
/**
 * Türkiye end-to-end against Nilvera's TEST system (only with "remote", only when the Nilvera environment is "test"
 * and an API key of a Nilvera test account is set). Test orders of the account's own company (an e-Fatura user) and
 * of a consumer, both internet sales with a shipment by "Yurtiçi Kargo":
 *   - e-Fatura (TICARIFATURA) with the internet sale details in the standard UBL places, numbered by Nilvera, GİB 1300;
 *   - its refund: by default not sent (the buyer issues the return invoice; the admin records it), with
 *     "Issued by the store" a return invoice (IADE, TEMELFATURA) that refers to the GİB number;
 *   - e-Arşiv: generated before the shipment → invalid (TR-SHIP); the shipment puts it back to pending; then sent with
 *     the internet sale details (card payment through an intermediary, carrier, dates), checked in Nilvera's own view;
 *   - its refund: by default not sent (return section / expense voucher), with "issued by the store" an e-Arşiv IADE.
 * The test orders are removed afterwards; the invoices stay in Nilvera's test system (not real invoices).
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
// Carrier VKN: Yurtiçi Kargo Servisi A.Ş. as listed in GİB's e-Fatura user list (Nilvera taxpayer search); test data only.
$carrierVkn = '9860008925';
$paths = ['seller/vat_id' => '', 'seller/tax_number' => $vkn, 'seller/tax_office' => 'Karesi', 'seller/legal_name' => 'Test Kurum Bir',
    'seller/street' => 'Test Cad. 1', 'seller/postcode' => '10030', 'seller/city' => 'Balıkesir', 'seller/country_id' => 'TR',
    'seller/endpoint_scheme' => '', 'seller/endpoint_id' => '',
    'tr/website_url' => 'https://www.example.com.tr',
    'tr/carriers' => json_encode(['_1' => ['match' => 'yurtici', 'name' => 'Yurtiçi Kargo Servisi A.Ş.', 'tax_id' => $carrierVkn]]),
    'tr/payment_types' => json_encode(['_1' => ['method' => 'checkmo', 'type' => 'KREDIKARTI/BANKAKARTI', 'intermediary' => 'Test Ödeme Kuruluşu']]),
    'tr/return_einvoice' => 'buyer', 'tr/return_earchive' => 'voucher'];
$setConfig = static function (array $values) use ($writer): void {
    foreach ($values as $p => $v) { $writer->save('commercegears_einvoicing/' . $p, $v, 'stores', 2); }
    om()->get(\Magento\Framework\App\Config\ReinitableConfigInterface::class)->reinit();
};
$setConfig($paths);
$poll = static function ($doc, int $seconds) use ($service) {
    $until = time() + $seconds;
    while ($doc->getStatus() === 'sending' && time() < $until) {
        sleep(10);
        $service->checkStatus($service->load((int)$doc->getId()), 'test');
        $doc = $service->load((int)$doc->getId());
    }
    return $doc;
};
$ship = static function (\Magento\Sales\Model\Order $order): void {
    $order = om()->create(\Magento\Sales\Model\Order::class)->load((int)$order->getId());
    $items = [];
    foreach ($order->getAllItems() as $item) { $items[(int)$item->getId()] = (float)$item->getQtyOrdered(); }
    $shipment = om()->get(\Magento\Sales\Model\Order\ShipmentFactory::class)->create($order, $items);
    $shipment->register();
    $track = om()->create(\Magento\Sales\Model\Order\Shipment\Track::class);
    $track->setCarrierCode('custom')->setTitle('Yurtiçi Kargo')->setTrackNumber('TEST' . random_int(100000, 999999));
    $shipment->addTrack($track);
    $order->setIsInProcess(true);
    om()->create(\Magento\Framework\DB\Transaction::class)->addObject($shipment)->addObject($order)->save();
    $GLOBALS['tr_created_shipments'][] = (int)$shipment->getId();
};
$flat = static fn (string $xml): string => (string)preg_replace('/>\s+</', '><', $xml);
$nilvera = static function (string $path) use ($config): string {
    $key = $config->getSecret('tr/api_key', 2);
    $ch = curl_init('https://apitest.nilvera.com/' . ltrim($path, '/'));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key]]);
    return (string)curl_exec($ch);
};
$gibNumber = \CommerceGears\EInvoicingTr\Model\Channel\NilveraChannel::GIB_NUMBER;
try {
    // ---- e-Fatura internet sale, bank transfer, shipped by Yurtiçi Kargo ----
    $o = ei_order('TR', ['street' => 'Test Cad. 2', 'city' => 'Balıkesir', 'postcode' => '10030', 'email' => 'test-tr-e2e@example.com',
        'details' => ['is_business' => 1, 'tax_id' => $vkn, 'tax_office' => 'Karesi']],
        [['TEST-SKU-TR1', 'Test Çanta', 2, 500.00, 20, 0], ['TEST-SKU-TR2', 'Test Matara', 1, 100.00, 20, 0]], 50.00, 20, 'banktransfer', 'TRY', 2);
    $i = ei_invoice($o);
    $ship($o);
    $d = gen('tr e-fatura e2e', $i);
    $xml = $d ? $flat($service->readXml($d)) : '';
    check(str_contains($xml, '<cbc:ProfileID>TICARIFATURA</cbc:ProfileID>'), 'e-Fatura user → TICARIFATURA');
    check((bool)preg_match('#<cbc:ProfileID>TICARIFATURA</cbc:ProfileID><cbc:ID>([A-Z0-9]{3})</cbc:ID>#', $xml, $s), 'XML carries the series only: ' . ($s[1] ?? '-'));
    check(str_contains($xml, '<cbc:Note>Bu satış internet üzerinden yapılmıştır.</cbc:Note>')
        && str_contains($xml, '<cbc:WebsiteURI>https://www.example.com.tr</cbc:WebsiteURI>')
        && str_contains($xml, '<cbc:Note>Gönderiyi taşıyan: Yurtiçi Kargo Servisi A.Ş., VKN/TCKN: ' . $carrierVkn . ', gönderim tarihi: ' . date('d.m.Y') . '</cbc:Note>')
        && str_contains($xml, '<cac:Delivery><cac:Despatch><cbc:ActualDespatchDate>' . date('Y-m-d') . '</cbc:ActualDespatchDate>')
        && str_contains($xml, '<cbc:PaymentMeansCode>30</cbc:PaymentMeansCode><cbc:PaymentDueDate>')
        && !str_contains($xml, 'IS_INTERNET'), 'e-Fatura internet sale: note, web address, carrier, EFT/HAVALE (30) with payment date');
    $d = $service->send($d, 'test');
    $d = $service->load((int)$d->getId());
    $number = $d->getExternalId();
    check((bool)preg_match('/^' . ($s[1] ?? 'XXX') . date('Y') . '\d{9}$/', $number), "Nilvera assigned the GİB number $number (ETTN " . strtoupper($d->getUuid()) . ')'
        . ($d->getLastError() ? ' ' . $d->getLastError() : ''));
    $d = $poll($d, 180);
    check(in_array($d->getStatus(), ['sent', 'delivered'], true) && str_contains((string)$d->getData('external_status'), '1300'),
        'GİB delivered the e-Fatura: ' . $d->getStatus() . ' ' . $d->getData('external_status'));

    // ---- its refund: issued by the buyer (default) ----
    $cm = ei_creditmemo($i, [1, 0]);
    $c = ei_doc('creditmemo', (int)$cm->getId());
    $c = $c ? $service->generate($c, 'test') : null;
    check($c?->getStatus() === 'external' && $c->getData('external_status') === 'issued_by_buyer' && $c->getXmlPath() === '',
        'refund to an e-Fatura buyer: not sent (' . $c?->getStatus() . ', ' . $c?->getData('external_status') . '): ' . ($c ? $service->notSentReason($c) : ''));
    $service->recordReceived($c, 'ABC' . date('Y') . '000000123', date('Y-m-d'), 'test');
    $c = $service->load((int)$c->getId());
    check($c->getData('received_number') === 'ABC' . date('Y') . '000000123' && $c->getData('received_date') === date('Y-m-d'),
        'buyer\'s return invoice recorded: ' . $c->getData('received_number') . ' ' . $c->getData('received_date'));
    $threw = false;
    try { $service->recordReceived($c, '', 'x', 'test'); } catch (\Magento\Framework\Exception\LocalizedException) { $threw = true; }
    check($threw, 'empty number / bad date refused');

    // ---- the same refund with "Issued by the store": IADE TEMELFATURA ----
    $setConfig(['tr/return_einvoice' => 'store']);
    $c = $service->generate($c, 'test');
    $cxml = $c->getXmlPath() !== '' ? $flat($service->readXml($c)) : '';
    check($c->getStatus() === 'ready' && str_contains($cxml, '<cbc:ProfileID>TEMELFATURA</cbc:ProfileID>') && str_contains($cxml, '<cbc:InvoiceTypeCode>IADE</cbc:InvoiceTypeCode>')
        && !str_contains($cxml, 'Bu satış internet'), 'issued by the store: return invoice IADE as TEMELFATURA, no internet sale details');
    check(str_contains($cxml, '<cac:InvoiceDocumentReference><cbc:ID>' . $number . '</cbc:ID>'), 'return invoice refers to ' . $number);
    $c = $service->send($c, 'test');
    $c = $poll($service->load((int)$c->getId()), 180);
    check((bool)preg_match($gibNumber, $c->getExternalId()) && in_array($c->getStatus(), ['sent', 'delivered'], true),
        'return invoice accepted: ' . $c->getExternalId() . ' ETTN ' . strtoupper($c->getUuid()) . ' ' . $c->getStatus() . ' ' . $c->getData('external_status')
        . ($c->getLastError() ? ' ' . $c->getLastError() : ''));

    // ---- e-Arşiv internet sale (consumer), card payment through an intermediary; invoiced before the shipment ----
    $o2 = ei_order('TR', ['street' => 'Moda Cad. 45', 'city' => 'İstanbul', 'postcode' => '34710', 'email' => 'test-tr-e2e2@example.com',
        'details' => ['tax_id' => '10000000146']], [['TEST-SKU-TR1', 'Test Çanta', 1, 500.00, 20, 0]], 50.00, 20, 'checkmo', 'TRY', 2);
    $i2 = ei_invoice($o2);
    $a = ei_doc('invoice', (int)$i2->getId());
    $a = $service->generate($a, 'test');
    check($a->getStatus() === 'invalid' && str_contains($a->getLastError(), 'TR-SHIP'), 'not shipped yet: invalid (TR-SHIP) - ' . mb_substr($a->getLastError(), 0, 120));
    $ship($o2);
    $a = $service->load((int)$a->getId());
    check($a->getStatus() === 'pending', 'shipment created: e-invoice back to pending (' . $a->getStatus() . ')');
    $a = $service->generate($a, 'test');
    $axml = $a->getXmlPath() !== '' ? $flat($service->readXml($a)) : '';
    check($a->getStatus() === 'ready' && (bool)preg_match('#<cbc:ProfileID>EARSIVFATURA</cbc:ProfileID><cbc:ID>([A-Z0-9]{3})</cbc:ID>#', $axml, $as),
        'e-Arşiv, series ' . ($as[1] ?? '-') . ' ' . $a->getStatus() . ' ' . $a->getLastError());
    $today = date('d.m.Y');
    check(str_contains($axml, '<cbc:DocumentTypeCode>IS_INTERNET</cbc:DocumentTypeCode>')
        && str_contains($axml, '<cbc:ID>KREDIKARTI/BANKAKARTI</cbc:ID><cbc:IssueDate>' . date('Y-m-d') . '</cbc:IssueDate><cbc:DocumentTypeCode>INT_PAYMENT_METHOD</cbc:DocumentTypeCode>')
        && str_contains($axml, '<cbc:ID>Test Ödeme Kuruluşu</cbc:ID>')
        && str_contains($axml, '<cbc:ID>' . $carrierVkn . '</cbc:ID><cbc:IssueDate>' . date('Y-m-d') . '</cbc:IssueDate><cbc:DocumentTypeCode>INT_TRANSPORTER_REGISTER_NUMBER</cbc:DocumentTypeCode>')
        && str_contains($axml, '<cbc:ID>' . $today . '</cbc:ID><cbc:IssueDate>' . date('Y-m-d') . '</cbc:IssueDate><cbc:DocumentTypeCode>INT_TRANSPORT_DATE</cbc:DocumentTypeCode>'),
        'e-Arşiv internet sale details (payment type from the setting, intermediary, carrier VKN, dates)');
    file_put_contents(EI_OUT . '/golden/tr-earsiv-internet-sale.xml', $service->readXml($a));
    $a = $poll($service->load((int)$service->send($a, 'test')->getId()), 120);
    check((bool)preg_match($gibNumber, $a->getExternalId()) && in_array($a->getStatus(), ['sent', 'delivered'], true),
        'e-Arşiv accepted: ' . $a->getExternalId() . ' ETTN ' . strtoupper($a->getUuid()) . ' ' . $a->getStatus() . ' ' . $a->getData('external_status')
        . ($a->getLastError() ? ' ' . $a->getLastError() : ''));
    $html = $nilvera('/earchive/Invoices/' . strtoupper($a->getUuid()) . '/html');
    file_put_contents(EI_OUT . '/golden/tr-earsiv-internet-sale-nilvera.html', $html);
    $text = (string)preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string)preg_replace('#<(style|script)\b.*?</\1>#s', '', $html)), ENT_QUOTES, 'UTF-8'));
    check(str_contains($text, 'Bu satış internet üzerinden yapılmıştır') && str_contains($text, 'https://www.example.com.tr')
        && str_contains($text, 'KREDIKARTI/BANKAKARTI') && str_contains($text, 'Yurtiçi Kargo Servisi A.Ş.') && str_contains($text, $carrierVkn),
        'Nilvera\'s invoice view shows the internet sale details: ' . (preg_match('/Bu satış internet.{0,260}/u', $text, $mm) ? $mm[0] : '-'));

    // ---- its refund: return section / expense voucher (default), then e-Arşiv IADE issued by the store ----
    $cm2 = ei_creditmemo($i2, [1]);
    $r = $service->generate(ei_doc('creditmemo', (int)$cm2->getId()), 'test');
    check($r->getStatus() === 'external' && $r->getData('external_status') === 'return_voucher',
        'consumer refund: not sent (' . $r->getStatus() . ', ' . $r->getData('external_status') . ')');
    $setConfig(['tr/return_earchive' => 'store']);
    $r = $service->generate($r, 'test');
    $rxml = $r->getXmlPath() !== '' ? $flat($service->readXml($r)) : '';
    check($r->getStatus() === 'ready' && str_contains($rxml, '<cbc:ProfileID>EARSIVFATURA</cbc:ProfileID>') && str_contains($rxml, '<cbc:InvoiceTypeCode>IADE</cbc:InvoiceTypeCode>')
        && str_contains($rxml, '<cbc:ID>' . $a->getExternalId() . '</cbc:ID>'), 'issued by the store: e-Arşiv IADE referring to ' . $a->getExternalId());
    $r = $poll($service->load((int)$service->send($r, 'test')->getId()), 120);
    check((bool)preg_match($gibNumber, $r->getExternalId()) && in_array($r->getStatus(), ['sent', 'delivered'], true),
        'e-Arşiv return invoice accepted: ' . $r->getExternalId() . ' ' . $r->getStatus() . ' ' . $r->getData('external_status')
        . ($r->getLastError() ? ' ' . $r->getLastError() : ''));
} catch (\Throwable $e) {
    check(false, 'exception: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    foreach (array_keys($paths) as $p) { $writer->delete('commercegears_einvoicing/' . $p, 'stores', 2); }
    om()->get(\Magento\Framework\App\Cache\TypeListInterface::class)->cleanType('config');
    ei_cleanup();
    $db = om()->get(\Magento\Framework\App\ResourceConnection::class)->getConnection();
    foreach (array_unique($GLOBALS['tr_created_shipments'] ?? []) as $id) {
        if ($id > 0) { $db->delete('sales_shipment_grid', ['entity_id = ?' => $id]); }
    }
}
done();
