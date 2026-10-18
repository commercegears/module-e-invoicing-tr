# CommerceGears E-Invoicing: Türkiye (beta)

`commercegears/module-e-invoicing-tr` (Magento module `CommerceGears_EInvoicingTr`), requires
`commercegears/module-e-invoicing-core`.

e-Fatura / e-Arşiv through the private integrator Nilvera.

## Features

- **UBL-TR 1.2** (`ubl_tr`): e-Fatura (TICARIFATURA or TEMELFATURA) for buyers registered in GİB's e-Fatura list,
  otherwise e-Arşiv (EARSIVFATURA); return invoices (IADE, always TEMELFATURA for e-Fatura buyers) for credit memos,
  referring to the GİB number of the original; ETTN = document UUID. Through Nilvera the XML carries only the 3-character
  series and Nilvera assigns the GİB number (series + year + sequence, in GİB's date order); the number is stored on the
  document and shown in the admin and the customer account. Unsigned (Nilvera signs).
- **Nilvera** channel (`tr_nilvera`): taxpayer check (GİB list, receiving mailbox of private and public buyers), XML
  upload to e-Fatura or e-Arşiv, status polling until final (e-Fatura: GİB envelope status, e.g. 1300, and the buyer's
  answer to a TICARIFATURA; e-Arşiv: created, reported to GİB), duplicate protection (same ETTN is not sent twice).
  Tested end to end against Nilvera's test system (apitest.nilvera.com).
- **Internet sales** (setting "This Store Sells Online", default Yes; VUK General Communiqué 509, IV.2.4.5): web address
  (store base URL or a set address), GİB payment type (KREDIKARTI/BANKAKARTI, EFT/HAVALE, KAPIDAODEME, ODEMEARACISI,
  DIGER) per Magento payment method with optional payment intermediary, payment date (date of the paid Magento invoice),
  carrier company and VKN/TCKN (rows by Magento carrier code or a word of the tracking title, `*` for all others) and
  shipment date (services: invoice date). e-Arşiv: written as Nilvera's `AdditionalDocumentReference` codes
  (IS_INTERNET, INT_WEBSITE, INT_PAYMENT_METHOD, INT_PAYMENTDATE, INT_PAYMENT_AGENT_NAME, INT_TRANSPORTER,
  INT_TRANSPORTER_REGISTER_NUMBER, INT_TRANSPORT_DATE), which Nilvera reports to GİB; e-Fatura: invoice notes plus
  `Delivery/Despatch/ActualDespatchDate`; both: supplier `WebsiteURI` and `PaymentMeans`. Missing data stops the
  invoice (checks TR-WEB, TR-PAYDATE, TR-SHIP, TR-CARRIER); an invoice made before the shipment is generated again
  automatically when the shipment is saved.
- **Returns** (credit memos), settings "Return Invoices": e-Fatura buyers - "Issued by the buyer" (default; GİB UBL-TR:
  IADE is issued by the buyer, nothing is sent, the admin records the buyer's return invoice) or "Issued by the store"
  (IADE, TEMELFATURA); e-Arşiv buyers - "No return invoice" (default; the signed return section of the internet sale
  invoice replaces the expense voucher, VUK 509 IV.2.4.5) or "e-Arşiv return invoice issued by the store" (IADE).
  Documents not sent get the status "Not sent (documented outside)" (EInvoicingCore 2.3.0) with a form to record the
  received document's number and date.
- Checkout / order fields for Turkish buyers: VKN (10 digits) or TCKN (11 digits) with checksum, tax office.
- Suggested rule: Turkish sellers → UBL-TR through Nilvera for all buyers.

## Configuration

Stores > Configuration > CommerceGears > E-Invoicing > E-Invoicing: Türkiye (Nilvera) (`commercegears_einvoicing/tr/*`, website scope): environment
(test / live), API key (encrypted), e-Arşiv series and e-Fatura series (empty = the default series of the Nilvera
account; a series must exist in the Nilvera account), e-Fatura profile, Test Connection; Internet Sales (this store sells online, web address, payment types, carriers);
Return Invoices (e-Fatura buyers, e-Arşiv buyers). Seller settings: tax
number (VKN) and tax office.

## What you need

A contract with Nilvera (API key; their test portal for trying it out).

## Limitations (beta)

- Tested against Nilvera's test system (e-Arşiv, e-Fatura, return invoices); not yet used with a live account.
- Withholding (tevkifat) codes are not written yet. Carrier VKNs are not shipped with the module: enter them.
- e-Gider Pusulası (electronic expense voucher) is not created by the module; record its number on the credit memo's
  document.
- The customer downloads the XML the store sent (series only); the signed invoice with the GİB number is in the
  Nilvera portal.
- Documents without Nilvera (no channel) keep the old number: prefix (default MAG) + year + Magento invoice number.

## Documentation

User guide, FAQ and product listing: [docs/](docs/user-guide.md).

## Tests

`Test/Unit` (PHPUnit), `Test/Live/TrTest.php` and, against Nilvera's test system, `Test/Live/TrNilveraE2eTest.php remote`
(run as the web server user).
