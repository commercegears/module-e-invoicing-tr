# Changelog

## 1.0.1 - 2026-10-07

### Changed
- Settings: Test and Live explained, a "Where to find it" box for the Nilvera API key (test-portal key for Test), the
  e-Fatura profiles explained, defaults and "Use system value" on environment and e-Fatura profile. Requires
  E-Invoicing Core 1.1.

## 1.0.0 - 2026-10-07

### Added
- UBL-TR 1.2 e-Fatura / e-Arşiv (beta).
- Nilvera channel: taxpayer check, upload, status (beta).
- VKN/TCKN and tax office fields; suggested rule for Turkish sellers.

### Changed
- Uploads to Nilvera are retried after timeouts and gateway errors: Nilvera refuses a second invoice with the same
  ETTN, so this cannot create a duplicate.
