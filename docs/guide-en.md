# Invoicing a Sellissimo order

Free lets staff issue domestic Hungarian HUF invoices, download the provider PDF and manage adjustments. Pro adds automatic issuance, a site-specific exception queue and optional invoice notifications. Számlázz.hu bills its own Számla Agent fee separately for both editions. Its monthly charge depends on the previous month's document volume. [Current Agent fees](https://www.szamlazz.hu/szamla-agent-api)

## Connect a site

1. Open Sellissimo **Store settings → Számlázz.hu** and select the Statamic site. The page shows the saved account mode and the active Free or Pro edition.
2. Select **Test account** and enter the Agent key generated in a dedicated Számlázz.hu test account. Both modes contact the same provider endpoint. For live sales, select **Live account** and use the separate live account's key. Leave the Agent key blank on later saves to keep the saved key for the selected mode. Keep earlier account access available for document lookups. [Provider test account setup](https://tudastar.szamlazz.hu/gyik/teszt-fiok-fejleszteshez)
3. Choose Hungarian or English for the invoice, set the payment term in days and select the document format available on the issuing account.
4. Choose the invoice VAT code beside each applicable Sellissimo tax class. For example, select **27** for a class configured at 27%. Agree any exemption code with your bookkeeper. Leave a class blank while its treatment is undecided. The invoice checks the rate recorded at purchase, including rates on shipping and fees. [Provider VAT codes](https://docs.szamlazz.hu/agent/generating_invoice/vat-rates)
5. Enter the payment label that should appear on the invoice beside each gateway, such as **Bankkártya** for card payments or **Utánvét** for cash on delivery. Previously mapped methods and classes remain available for review. Save the settings before placing your first test order.

Assign **View invoices and PDFs** to staff who need document access on that site. Issuing, correcting and matching also require **Issue, adjust and reconcile invoices**. **Manage invoice settings and Agent keys** controls account access.

## From checkout to PDF

A domestic HUF buyer supplies a full billing address and chooses private person or business. Business buyers enter their registered name and Hungarian tax number. The bundled checkout and draft payment link collect these details. A custom checkout should render the quote's checkout step definitions, send the answers under `checkout_steps.szamlazz_buyer` and submit the final quote token. The fields are `buyer_type` (`person` or `business`), `business_name` and `tax_number`.

Open the order, then **Számlázz.hu → Invoice documents**. Set the date of supply and payment due date. Select **Review document** and check the issuer mode, recipient, payment status, VAT on each line and totals. Discounts stay on the affected items. Confirm **Issue invoice**. Open the issued number's original PDF to print, download or deliver it.

The provider requires whole HUF values for line net, VAT and gross. If the order cannot be reproduced, the preview identifies the discrepancy. Similarly, a voucher, cross-border sale, unresolved tax treatment or order without original fiscal data needs individual review. For a suitable invoice created directly in Számlázz.hu, enter the Sellissimo order reference on that invoice, then use **Verify and link** with the exact provider number. Matching verifies the available identity, currency and amount. [Provider rounding](https://docs.szamlazz.hu/agent/generating_invoice/settings_and_rules/rounding)

## Corrections and uncertain submissions

For a returned item or an applied amendment, open the original invoice's adjustment section. Review the commercial reason, signed amounts and original number, then confirm the linked correction. For an entire cancellation with no earlier correction, review a reversal. After a correction, the final adjustment credits the remaining balance. Staff approve each adjustment in both editions.

For an eligible externally issued correction or reversal, check the original invoice number displayed on the order, select the commercial reason, and enter the provider number and signed net, VAT and gross values printed on the adjustment. A matched external document without item-level evidence keeps further adjustments under staff management in Számlázz.hu.

**Needs reconciliation** means a submission may already have issued a document. Run the lookup on that existing operation. For a reversal, enter the exact reversal number from the issuing account. For a rejected request, read the error, resolve the cause and review the permitted retry. The provider's document register is the reference when evidence cannot be matched.

## Pro automation

Enable automation separately for each site after testing. Confirmed payment includes recorded cash collection. Final dispatch waits for the last physical parcel and records the payment status at dispatch. Digital-only orders use confirmed payment. To request the provider's invoice email, turn on the notification setting. A successful issuance response confirms the provider accepted the operation. Customer receipt of the email requires separate evidence.

**Invoices requiring attention** shows the affected order, the reason and the last attempt. Use it for unresolved refunds, exchanges and changed orders. Switching to Free pauses unsent automatic jobs. Re-enabling Pro starts a new window, with earlier orders available for individual review. The store's scheduler and queue worker must be running for background processing.

## Check your first test invoice

Compare the new order, its original PDF and the entry in the test account’s document register. Check the buyer details, totals, VAT, dates and order reference. For a correction, check the original invoice reference and the remaining balance after the adjustment.
