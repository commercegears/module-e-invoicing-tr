# Changelog

## 2.1.0 (2026-10-15)
New: German translation of the customer-facing texts.

## 2.0.1 (2026-10-13)
- Fix: return invoices (IADE) refer to the GİB number of the original invoice instead of the Magento invoice number or the ETTN.
- Improvement: product listing, FAQ and user guide.

## 2.0.0 (2026-10-11)
- Improvement: renamed to CommerceGears (new Composer package, admin menu and settings paths)

## 1.1.0 (2026-10-11)
- Improvement: settings have their own "E-Invoicing: Türkiye (Nilvera)" section; saved values are kept.

## 1.0.1 (2026-10-07)
- Improvement: settings explain Test and Live, where to find the Nilvera API key and the e-Fatura profiles.

## 1.0.0 (2026-10-07)
- New: UBL-TR e-Fatura and e-Arşiv for Türkiye through Nilvera (beta).
- New: taxpayer check, VKN/TCKN and tax office fields, and a suggested rule for Turkish sellers.
- New: uploads are retried after timeouts without creating duplicates.
