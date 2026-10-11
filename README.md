# Softaware E-Invoicing: Türkiye (beta)

`softaware/module-e-invoicing-tr` (Magento module `Softaware_EInvoicingTr`), requires
`softaware/module-e-invoicing-core`.

e-Fatura / e-Arşiv through the private integrator Nilvera.

## Features

- **UBL-TR 1.2** (`ubl_tr`): e-Fatura (TICARIFATURA or TEMELFATURA) for buyers registered in GİB's e-Fatura list,
  otherwise e-Arşiv (EARSIVFATURA); return invoices (IADE) for credit memos; ETTN = document UUID; GİB number from the
  series prefix + year + Magento invoice number. Unsigned (Nilvera signs).
- **Nilvera** channel (`tr_nilvera`): taxpayer check (GİB list), XML upload to e-Fatura or e-Arşiv, status polling,
  duplicate protection (same ETTN is not sent twice). Endpoints taken from Nilvera's published OpenAPI.
- Checkout / order fields for Turkish buyers: VKN (10 digits) or TCKN (11 digits) with checksum, tax office.
- Suggested rule: Turkish sellers → UBL-TR through Nilvera for all buyers.

## Configuration

Stores > Configuration > Softaware > E-Invoicing > E-Invoicing: Türkiye (Nilvera) (`softaware_einvoicing/tr/*`, website scope): environment
(test / live), API key (encrypted), invoice series prefix, e-Fatura profile, Test Connection. Seller settings: tax
number (VKN) and tax office.

## What you need

A contract with Nilvera (API key; their test portal for trying it out).

## Limitations (beta)

- Not yet tested against Nilvera's test system (no account available while building); tested with recorded
  responses and the UBL 2.1 schema.
- e-Arşiv internet-sale details (web address, payment and carrier data) and withholding (tevkifat) codes are not
  written yet.
- The GİB number uses the Magento invoice number, which does not restart every year.

## Tests

`Test/Live/TrTest.php` (run as the web server user).
