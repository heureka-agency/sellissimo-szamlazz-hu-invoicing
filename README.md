# Számlázz.hu for Sellissimo

Issue Hungarian HUF invoices from orders in a Statamic 6 store. Számlázz.hu assigns each invoice number and supplies the original PDF, which authorized staff can open from the order.

## Editions

| Workflow | Free | Pro |
| --- | --- | --- |
| Invoice a domestic Hungarian order | Review the buyer, dates, VAT and totals, then issue the invoice. | Also issue automatically on confirmed payment or final physical dispatch. Digital orders use confirmed payment. |
| Deliver an invoice | Download or print the original PDF. | Optionally ask Számlázz.hu to email the buyer on issuance. |
| Correct an invoice | Review and issue a linked correction or reversal. | Also surface settled refunds and order changes for staff review. Staff confirm each adjustment. |
| Resolve an exception | Check an individual order and verify an existing provider document. | Review exceptions across the site in one queue. |

Both editions support separate test and live accounts, per-site VAT mappings, payment labels, invoice defaults, staff permissions and document history. The issuing account must have Számla Agent access. Provider charges apply separately. [Számla Agent fees](https://www.szamlazz.hu/szamla-agent-api)

## Installation

Install in a Statamic 6 site running PHP 8.3 or later. Composer installs Sellissimo Core 1.0.1 or later in the 1.x series.

```sh
composer require sellissimo/szamlazz-hu-invoicing
```

Follow the [installation guide](docs/installation.md) to run the commerce migrations. Open **Store → Settings → Számlázz.hu**, connect a dedicated Számlázz.hu test account, and map the tax classes and payment methods before placing your first test order. Both editions use the same package. Free is the default.

## Merchant guides

- [Sellissimo invoicing documentation](https://sellissimo.heureka-kreativ.hu/docs/szamlazz-hu-invoicing)
- [Magyar útmutató](docs/guide-hu.md)
- [English operator guide](docs/guide-en.md)

The addon supports domestic HUF sales to private individuals and Hungarian businesses. Orders with missing original fiscal details or unsupported treatments need individual review. For a document issued directly in Számlázz.hu, staff can verify and link its provider number to the order.
