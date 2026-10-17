# E-Invoicing: Türkiye for Magento 2: User Guide

Create **UBL-TR 1.2** e-Fatura and e-Arşiv invoices and return invoices (IADE) from your Magento invoices and credit
memos, collect the buyer's **VKN or TCKN** and **tax office** at checkout, and send the documents through **Nilvera**,
a GİB private integrator (özel entegratör).

This package adds Türkiye to **E-Invoicing Core** (`commercegears/module-e-invoicing-core`, free), which provides the
queue, the admin grid, the document page, validation, the checkout fields and the customer account downloads.

- Storefront demo, checkout: https://demo.commercegears.com/fusion-backpack.html (add the backpack to the cart, go to
  the checkout and choose Türkiye as the country)
- Storefront demo, My Account: https://demo.commercegears.com/customer/account/login/ and on Hyvä
  https://demo.commercegears.com/hyva/customer/account/login/ (sign in as the demo customer, then My Orders)
- Admin demo: https://demo.commercegears.com/try-admin/e-invoicing-tr
- Changelog: [CHANGELOG.md](../CHANGELOG.md) · Questions: [FAQ](faq.md)

**About the demo.** The demo store is connected to Nilvera's **test** system (nothing is reported to GİB as a real
invoice). Its Turkish documents were sent for Nilvera's shared test company (VKN 1234567801, tax office Karesi): an
e-Arşiv invoice to a private buyer, an e-Fatura to a company in GİB's e-Fatura list (GİB status 1300) and a return
invoice that refers to the GİB number of its original. Each shows the GİB number Nilvera assigned and the ETTN.

**Status: beta.** The Nilvera connection is tested end to end against Nilvera's test system (e-Arşiv, e-Fatura and
return invoices). Test with your own Nilvera test account before you go live.

## 1. Requirements

| | |
| --- | --- |
| Magento | Open Source or Adobe Commerce 2.4.7 to 2.4.9 |
| PHP | 8.2 to 8.5, with the extensions dom, libxml, openssl and zlib |
| Themes | Luma and Hyvä for the customer account downloads; checkout fields in the Luma checkout (Hyvä through Hyva_LumaCheckout) |
| Other | `commercegears/module-e-invoicing-core` and `commercegears/module-core` (installed automatically) |
| Nilvera | A contract with Nilvera and an API key (for testing: an API key from Nilvera's test portal) |

The module sends through Nilvera only. It does not use the GİB e-Arşiv portal directly.

## 2. Installation

With Composer. Use the Composer keys from your account on commercegears.com (see
https://commercegears.com/composer-access/):

```bash
composer config repositories.commercegears composer https://repo.commercegears.com
composer config --auth http-basic.repo.commercegears.com PUBLIC_KEY PRIVATE_KEY
composer require commercegears/module-e-invoicing-tr
bin/magento setup:upgrade
bin/magento setup:di:compile            # production mode only
bin/magento setup:static-content:deploy # production mode only
bin/magento cache:flush
```

E-Invoicing Core is installed with it. Magento's cron must run: documents are generated, sent and checked by the cron
job every 5 minutes.

## 3. Quick start

1. **Stores > Configuration > CommerceGears > E-Invoicing > General > Enabled = Yes**.
2. In **Seller (Your Company)** fill in your legal name, address, **Country = Türkiye**, your VKN in **Tax Number**
   and your **Tax Office** (vergi dairesi).
3. **Stores > Configuration > CommerceGears > E-Invoicing: Türkiye (Nilvera)**: choose the **Environment** (Test first),
   enter the **API Key**, check the **e-Arşiv Series**, **e-Fatura Series** and the **e-Fatura Profile**, save and click
   **Test Connection**.
4. Invoice an order of a Turkish customer. Cron generates the UBL-TR document, checks it and sends it through Nilvera.

## 4. Configuration

**E-Invoicing: Türkiye (Nilvera)** (section `commercegears_einvoicing_tr`, paths `commercegears_einvoicing/tr/*`,
default and website scope):

| Setting | What it does |
| --- | --- |
| Environment | **Test** (apitest.nilvera.com, default): Nilvera's test system, invoices are not reported to GİB. **Live**: legally binding invoices. The API key must belong to the same environment. |
| API Key | Created in the Nilvera portal; for Test, a key from Nilvera's test portal. Stored encrypted. |
| e-Arşiv Series | 3 capital letters or digits. The series must exist and be active in your Nilvera account; Nilvera gives each invoice the next GİB number of the series (e.g. `EAR2026000000001`), in GİB's date order. Empty (default): the default e-Arşiv series of your Nilvera account. |
| e-Fatura Series | The same for e-Fatura invoices. Empty (default): the default e-Fatura series of your Nilvera account. |
| e-Fatura Profile | **TICARIFATURA** (default) lets the buyer accept or reject the invoice in GİB; **TEMELFATURA** does not. Return invoices and invoices to public administrations are always TEMELFATURA (GİB rule). |
| Connection | **Test Connection** calls Nilvera with the saved API key. |

**E-Invoicing** (Core settings, section `commercegears_einvoicing`): **Enabled**, **Send Automatically** (default Yes:
valid documents are sent by cron; No: send them from the grid), the seller details (**Tax Number** = VKN, **Tax
Office**), the rules under **Countries, Formats and Delivery**, payment terms, **Ask for E-Invoice Details** at
checkout and **Download in Customer Account**.

**Suggested rule** (used until you save your own rules): Turkish sellers send UBL-TR through Nilvera for all buyers,
consumers included (e-Arşiv invoices are needed for them too).

## 5. Buyer tax IDs at checkout and on the order

- **Tax number (VKN) or T.C. identity number (TCKN)**: shown at checkout (payment step) for every Turkish billing
  address, also for private buyers. VKN has 10 digits, TCKN 11 digits; both are checked with the official checksum on
  the server, and an invalid number is refused with a clear message.
- **Tax office**: shown when the customer ticks **This is a business purchase (electronic invoice)**.
- **Order reference**: the optional "Your order reference" for business buyers.
- **On the order page**: the E-Invoicing box edits the buyer details. Regenerate documents that were not sent yet after
  a change.

## 6. The UBL-TR document

- **Profile**: e-Fatura (TICARIFATURA or TEMELFATURA, from the setting) for buyers registered in GİB's e-Fatura list,
  otherwise e-Arşiv (EARSIVFATURA). The list is checked through Nilvera when the document is generated; without a
  Nilvera connection every document is e-Arşiv.
- **ETTN** = the document's UUID; **GİB number**: the XML carries the 3-character series and Nilvera assigns the
  number (series + year + 9-digit sequence). The module stores it on the document: **GİB invoice number** on the
  document page and in the customer account.
- Seller and buyer with VKN or TCKN, name (or first and last name for persons), address and tax office; lines,
  shipping, the KDV breakdown and totals; payment due date for payment methods that are paid later.
- **Return invoices (IADE)** for credit memos, referring to the GİB number and date of the original invoice (send the
  original first). To e-Fatura buyers they are TEMELFATURA, as GİB does not accept a TICARIFATURA return invoice.
- The document is unsigned: Nilvera signs it.

**Checks before sending**: the UBL 2.1 schema (shipped) and the UBL-TR rules: the seller VKN (TR-SELLER) and tax office
(TR-TAXOFFICE) are present, the series has 3 characters (TR-SERIES) and a return invoice's original has its GİB number
(TR-REF). Problems are listed on the document page
with the rule ID.

## 7. Sending through Nilvera

1. Cron (or **Send** in the grid or on the document page) uploads the XML to Nilvera's e-Fatura service (with the
   buyer's alias) or e-Arşiv service, depending on the profile.
   Nilvera answers with the GİB number, which the module stores on the document.
2. Cron asks Nilvera for the status until it is final:
   - **e-Fatura**: **Sent** once GİB delivered it to the buyer (GİB code 1300); a TICARIFATURA becomes **Delivered**
     when the buyer accepts it and **Rejected** when the buyer rejects it; a TEMELFATURA is **Delivered** at 1300.
   - **e-Arşiv**: **Sent** once Nilvera has created and signed it, **Delivered** once it is in Nilvera's daily report
     to GİB.
   - An error reported by Nilvera or GİB marks the document **Rejected** with the message.
3. If Nilvera already has a document with the same ETTN (for example after a timeout), the module reads its GİB number
   and records it instead of uploading it again, so an invoice is never sent twice.

The API key is masked in the audit trail. Documents of Nilvera's test environment are not reported to GİB.

## 8. Daily use

**E-Invoices grid** (**CommerceGears > E-Invoicing > E-Invoices**): format, delivery, status and problem of every
document, with search, filters and the mass actions **Generate / Regenerate (Not Sent Yet)**, **Send**, **Check
Delivery Status**, **Cancel (Not Sent Yet)** and **Download Files (ZIP)**.

**Document page**: status, Nilvera's status (e.g. `succeed:1300`), the **GİB invoice number**, the **UUID (ETTN)**, SHA-256 checksums of the files, the validation result and the audit
trail with the API calls.

**Order page**: the E-Invoicing box lists the order's documents, creates one by hand in any installed format and
delivery, and edits the buyer details.

**Customer account**: logged-in customers see the GİB invoice number and download the UBL-TR XML under **Electronic
Invoices** on the Invoices and Refunds tabs of their orders, on Luma and Hyvä.

## 9. CLI

```bash
bin/magento commercegears:einvoice:test-connection tr_nilvera   # call Nilvera with the saved API key
bin/magento commercegears:einvoice:process                      # what the cron does
bin/magento commercegears:einvoice:send 42 [--status]           # send one document or check its status
bin/magento commercegears:einvoice:validate 42                  # checksum and XML schema of a document
```

## 10. Permissions (ACL)

Under **System > Permissions > User Roles**, **CommerceGears > E-Invoicing**: **View E-Invoices and Download Files**,
**Generate, Send and Cancel E-Invoices**, **Edit Buyer E-Invoice Details on Orders** and **Settings** (also for the
E-Invoicing: Türkiye (Nilvera) section).

## 11. Troubleshooting

- **Documents stay "Ready"**: no Nilvera API key is set, or **Send Automatically** is No.
- **"TR-SELLER: The seller VKN (10 digits) is missing"** or **"TR-TAXOFFICE"**: fill in Tax Number and Tax Office in
  Seller (Your Company) and regenerate.
- **"TR-SERIES"**: set a 3-character e-Arşiv / e-Fatura Series, or mark a default series in your Nilvera account.
- **Nilvera refuses the series (error 3009)**: the series does not exist or is not active in your Nilvera account.
- **Nilvera refuses the date**: Nilvera numbers each series in date order; an invoice dated before the last invoice of
  the series is refused. Send invoices on the day they are created.
- **"TR-REF"**: the original invoice of a return invoice has no GİB number yet: send the original, then regenerate.
- **Every document is e-Arşiv**: the taxpayer check needs a working Nilvera connection; check it with Test Connection.
- **Test Connection fails**: the API key belongs to the other environment (test key with Test, live key with Live).

## 12. Limitations

- Tested against Nilvera's test system, not yet with a live account (beta, see above).
- e-Arşiv internet-sale details (web address, payment and carrier data) and withholding (tevkifat) codes are not
  written yet.
- The customer downloads the XML the store sent (series only); the signed invoice with the GİB number is in the
  Nilvera portal.

## 13. Uninstall

```bash
composer remove commercegears/module-e-invoicing-tr
bin/magento setup:upgrade
```

Documents already created stay in E-Invoicing Core. Keep them for the legal retention period: the module never deletes
documents or files.
