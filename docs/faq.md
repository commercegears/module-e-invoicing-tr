# E-Invoicing: Türkiye for Magento 2: FAQ

**What does the module do?**
It turns your Magento invoices into UBL-TR 1.2 e-Fatura or e-Arşiv invoices and your credit memos into return invoices
(IADE), checks them against the UBL 2.1 schema and the UBL-TR rules, and sends them through the GİB private integrator
Nilvera.

**What do I need?**
A contract with Nilvera and an API key. For testing, Nilvera's test portal gives you an API key for its test system,
whose invoices are not reported to GİB. Your seller details need your VKN and tax office.

**Can it send through the GİB e-Arşiv portal or another integrator?**
No. The module sends through Nilvera only. You can still download the UBL-TR XML (single files or a ZIP from the grid).

**Who gets an e-Fatura and who an e-Arşiv invoice?**
Before a document is generated, the buyer's VKN or TCKN is checked in GİB's e-Fatura list through Nilvera. Registered
buyers get an e-Fatura with the profile you choose (TICARIFATURA or TEMELFATURA), everyone else an e-Arşiv invoice.

**Which tax IDs do customers enter?**
For a Turkish billing address the checkout asks for the tax number (VKN, 10 digits) or T.C. identity number (TCKN,
11 digits), also for private buyers; both are checked with the official checksum. Business buyers also enter their tax
office.

**How is the invoice number made?**
Nilvera assigns it: the store sends the 3-character series (from the settings, or your account's default series) and
Nilvera adds the year and the next number in GİB's date order, e.g. EAR2026000000001. The module stores this GİB number
and shows it on the document page and in the customer account. The ETTN is the document's UUID.

**What happens with refunds?**
By default the module follows GİB practice: an e-Fatura buyer issues the return invoice (IADE) to you, and a consumer
signs the return section of the internet sale invoice (or you issue an expense voucher). The credit memo's document then
shows "Not sent (documented outside)" and you record that document's number and date. If you prefer, the settings
"Return Invoices" make the module send a return invoice (IADE) that refers to the GİB number of the original invoice.
Please confirm the setting with your accountant.

**Does it add the internet sale details GİB requires?**
Yes. For online sales the invoices carry the web address, payment type and date, the carrier with its VKN/TCKN and the
shipment date. Set your carriers' VKNs once; an invoice made before the shipment is generated again when you ship.

**Can an invoice be sent twice?**
No. If an upload times out and is repeated, Nilvera recognises the same ETTN and the module records the document as
sent instead of creating a duplicate. A delivered document cannot be sent again or changed.

**Why is the module marked beta?**
The Nilvera connection is tested end to end against Nilvera's test system (e-Arşiv, e-Fatura and return invoices),
but not yet with a live account. Test with your Nilvera test account before going live.

**Why are the documents in the demo not sent?**
The demo store has no Nilvera connection. Its Turkish documents were generated for a fictitious Turkish seller and
checked in the store. Without a connection the e-Fatura list cannot be checked, so they are all e-Arşiv invoices.

**What is not included?**
e-Arşiv internet-sale details (web address, payment and carrier data) and withholding (tevkifat) codes. The GİB number
uses the Magento invoice number, which does not restart every year.

**Does it work with Hyvä?**
Yes. The customer account downloads have Hyvä templates. The checkout fields are part of the Luma checkout, which Hyvä
stores use through Hyva_LumaCheckout.
