# Testing the addon

Use a fresh Statamic 6 development site. Install the addon using the [installation guide](installation.md), keep the site's PHPUnit development dependencies installed, and run the following command from its root. The suite creates its own test catalog, payment gateway and commerce database.

```sh
vendor/bin/phpunit vendor/sellissimo/szamlazz-hu-invoicing/tests
```

SQLite is the default. For the MySQL and PostgreSQL variants, provision a disposable database named `sellissimo_test` and set `SELLISSIMO_TEST_DRIVER` to `mysql` or `pgsql`, `SELLISSIMO_TEST_PORT` to its local port, and `SELLISSIMO_TEST_PASSWORD` if needed. The host fixture uses `root` for MySQL and `postgres` for PostgreSQL, and gives each test its own table prefix.

The default suite uses a simulated provider. It exercises both editions through checkout, CP preview, confirmation, delivery of the private PDF and recovery. Two independent PHP workers race to confirm the same invoice. Other tests cover duplicate or missed events, permissions, refunds and amendments, draft payment links and preservation of buyer privacy.

## Opt-in test-account demonstrations

The following two checks issue actual documents in a dedicated Számlázz.hu test account. Use a credential file readable only by the operator and an empty private evidence directory outside the checkout. The account determines whether a document is legally live. Both account types use the same Agent endpoint.

```sh
SZAMLAZZ_TEST_KEY_FILE="$private_test_key_file" \
SZAMLAZZ_TEST_EVIDENCE_DIR="$private_evidence_directory" \
vendor/bin/phpunit vendor/sellissimo/szamlazz-hu-invoicing/tests/OrderInvoicingTest.php \
  --filter 'test_free_checkout_review_issue_and_download|test_pro_payment_and_lost_reply_recovery_do_not_resubmit'
```

The Free check downloads the provider PDF from the order. The Pro check discards the reply after successful issuance, replays the payment work, switches to Free and verifies the existing document using its external ID. Each case expects exactly one submission. Customer notifications are disabled for these demonstrations. The evidence directory receives the PDFs, document numbers and checksums.
