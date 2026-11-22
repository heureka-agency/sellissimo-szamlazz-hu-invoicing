<?php

namespace Sellissimo\SzamlazzHu\Tests\Fiscal;

use DomainException;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Sellissimo\Core\Money;
use Sellissimo\SzamlazzHu\Agent\Client;
use Sellissimo\SzamlazzHu\Agent\Rejected;
use Sellissimo\SzamlazzHu\Agent\RequestXml;
use Sellissimo\SzamlazzHu\Fiscal\InvoiceBuilder;
use Tests\TestCase;

final class InvoiceTest extends TestCase
{
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();
        app('translator')->addNamespace('szamlazz', dirname(__DIR__, 2).'/resources/lang');
        config(['cache.default' => 'array']);
    }

    private function sale(string $type = 'person'): array
    {
        $order = (object) ['id' => 'order-id', 'order_number' => 10001, 'currency' => 'HUF', 'total' => 415500,
            'status' => 'paid', 'gateway' => 'card'];
        $placement = ['mode' => 'test', 'digits' => 2, 'settings' => [
            'vat_codes' => ['normal' => '27', 'reduced' => '5', 'exempt' => 'AAM'], 'payment_methods' => ['card' => 'Bankkártya'],
        ], 'buyer' => ['type' => $type, 'name' => 'Teszt & Társ', 'country' => 'HU', 'postcode' => '1111',
            'city' => 'Budapest', 'address' => 'Példa utca 1.', 'email' => 'buyer@example.test',
            'tax_number' => $type === 'business' ? '12345678-1-42' : ''],
            'receipt' => ['currency' => 'HUF', 'total' => 415500, 'tax' => 70500,
                'lines' => [
                    ['key' => 'book', 'title' => 'Könyv <A>', 'qty' => 2, 'discount' => 25400,
                        'tax' => 54000, 'total' => 254000, 'tax_class' => 'normal', 'tax_rate' => 2700, 'tax_inclusive' => true],
                    ['key' => 'download', 'title' => 'Letöltés', 'qty' => 1, 'discount' => 0,
                        'tax' => 3000, 'total' => 63000, 'tax_class' => 'reduced', 'tax_rate' => 500, 'tax_inclusive' => false],
                ],
                'shipping' => ['label' => 'Szállítás', 'amount' => 63500, 'tax' => 13500, 'tax_class' => 'normal', 'tax_rate' => 2700, 'tax_inclusive' => false],
                'charges' => ['wrap' => ['label' => 'Csomagolás', 'tax' => 0, 'total' => 35000, 'tax_class' => 'exempt', 'tax_rate' => 0, 'tax_inclusive' => true]],
            ],
        ];

        return [$order, $placement, ['language' => 'hu', 'due_days' => 8]];
    }

    private function client(Response ...$responses): Client
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->requests));

        return new Client(new HttpClient(['handler' => $stack]));
    }

    private function reply(string $body): Response
    {
        return new Response(200, [], '<xmlszamlavalasz xmlns="http://www.szamlazz.hu/xmlszamlavalasz">'.$body.'</xmlszamlavalasz>');
    }

    private function record(): Response
    {
        $items = '';
        foreach ((new InvoiceBuilder)->build(...$this->sale())['lines'] as $line) {
            $items .= '<tetel><nev>'.htmlspecialchars($line['name'], ENT_XML1).'</nev><mennyiseg>'.$line['qty'].'</mennyiseg>
                <afakulcs>'.$line['vat_code'].'</afakulcs><netto>'.$line['net'].'</netto><afa>'.$line['vat'].'</afa><brutto>'.$line['gross'].'</brutto></tetel>';
        }

        return new Response(200, [], '<szamla xmlns="http://www.szamlazz.hu/szamla"><alap><szamlaszam>T-1</szamlaszam>
            <tipus>SZ</tipus><rendelesszam>10001</rendelesszam><devizanem>HUF</devizanem><teszt>true</teszt></alap>
            <vevo><nev>Teszt &amp; Társ</nev><cim><orszag>Magyarország</orszag><irsz>1111</irsz><telepules>Budapest</telepules>
            <cim>Példa utca 1.</cim></cim></vevo><tetelek>'.$items.'</tetelek><osszegek><totalossz><netto>3450</netto><afa>705</afa>
            <brutto>4155.00</brutto></totalossz></osszegek><pdf>'.base64_encode('%PDF-1.4 test').'</pdf></szamla>');
    }

    public function test_consumer_and_business_discounts_mixed_rates_shipping_and_fees(): void
    {
        foreach (['person', 'business'] as $type) {
            $payload = (new InvoiceBuilder)->build(...$this->sale($type));
            self::assertSame([3450, 705, 4155], [$payload['net'], $payload['vat'], $payload['gross']]);
            self::assertSame(['item:book', 'item:download', 'shipping', 'fee:wrap'], array_column($payload['lines'], 'key'));
            self::assertSame(['27', '5', '27', 'AAM'], array_column($payload['lines'], 'vat_code'));
            self::assertSame(25400, $payload['lines'][0]['discount_minor']);
            $xml = simplexml_load_string(RequestXml::invoice('fixture', $payload, 'stable-id'));
            self::assertSame('Könyv <A>', (string) $xml->tetelek->tetel[0]->megnevezes);
            self::assertSame('1000', (string) $xml->tetelek->tetel[0]->nettoEgysegar);
            self::assertSame($type === 'business' ? '1' : '-1', (string) $xml->vevo->adoalany);
            self::assertSame('false', (string) $xml->vevo->sendEmail);
        }
    }

    public function test_consumer_vat_on_the_half_forint_boundary_preserves_exact_gross(): void
    {
        [$order, $placement, $settings] = $this->sale();
        $order->total = 4000;
        $placement['receipt']['lines'] = [array_replace($placement['receipt']['lines'][0], [
            'qty' => 1, 'total' => 4000, 'tax' => 850, 'discount' => 0,
        ])];
        unset($placement['receipt']['shipping'], $placement['receipt']['charges']);
        $placement['receipt']['total'] = 4000;
        $placement['receipt']['tax'] = 850;
        $payload = (new InvoiceBuilder)->build($order, $placement, $settings);
        self::assertSame([31, 9, 40], [$payload['net'], $payload['vat'], $payload['gross']]);
    }

    public function test_scope_mapping_and_rounding_fail_closed(): void
    {
        foreach (['mapping', 'rate', 'currency', 'voucher', 'crossborder', 'legacy', 'rounding'] as $case) {
            [$order, $p, $settings] = $this->sale();
            match ($case) {
                'mapping' => $p['settings']['vat_codes']['exempt'] = 'unrecognised',
                'rate' => $p['receipt']['lines'][0]['tax_rate'] = 500,
                'currency' => $p['receipt']['currency'] = 'EUR',
                'voucher' => $p['receipt']['voucher'] = true,
                'crossborder' => $p['receipt']['shipping_address']['country'] = 'AT',
                'legacy' => $p['buyer']['type'] = null,
                'rounding' => $p['receipt']['lines'][0]['total']++,
            };
            if ($case === 'rounding') {
                $p['receipt']['total']++;
                $order->total++;
            }
            try {
                (new InvoiceBuilder)->build($order, $p, $settings);
                self::fail('Accepted an unsupported fiscal record: '.$case);
            } catch (DomainException $e) {
                self::assertNotSame('', $e->getMessage());
                if ($case === 'rounding') {
                    self::assertStringContainsString('-0.01', $e->getMessage());
                }
            }
        }
    }

    public function test_submit_validate_lookup_and_download_the_original(): void
    {
        $payload = (new InvoiceBuilder)->build(...$this->sale());
        $success = $this->reply('<sikeres>true</sikeres><szamlaszam>T-1</szamlaszam><szamlanetto>3450</szamlanetto>
            <szamlabrutto>4155</szamlabrutto><pdf>'.base64_encode('%PDF-1.4 test').'</pdf>');
        $client = $this->client($success->withHeader('Set-Cookie', 'JSESSIONID=fixture-cookie; Path=/; Secure'), $this->record(), $success);
        self::assertSame('T-1', $client->submit('fixture', $payload, 'stable-id')['number']);
        self::assertSame('%PDF-1.4 test', $client->lookup('fixture', $payload, null, 'stable-id')['pdf']);
        self::assertSame('%PDF-1.4 test', $client->pdf('fixture', 'T-1'));
        $fields = ['action-xmlagentxmlfile', 'action-szamla_agent_xml', 'action-szamla_agent_pdf'];
        foreach ($this->requests as $i => $request) {
            self::assertSame('POST', $request['request']->getMethod());
            self::assertSame('https://www.szamlazz.hu/szamla/', (string) $request['request']->getUri());
            self::assertStringContainsString($fields[$i], (string) $request['request']->getBody());
        }
        self::assertStringContainsString('<szamlaKulsoAzon>stable-id</szamlaKulsoAzon>', (string) $this->requests[1]['request']->getBody());
        self::assertStringNotContainsString('<rendelesSzam>', (string) $this->requests[1]['request']->getBody());
        self::assertSame('JSESSIONID=fixture-cookie', $this->requests[1]['request']->getHeaderLine('Cookie'));
    }

    public function test_correction_and_reversal_use_precise_original(): void
    {
        $payload = (new InvoiceBuilder)->build(...$this->sale());
        $payload['original_number'] = 'ORIG-1';
        $payload['kind'] = 'correction';
        $xml = simplexml_load_string(RequestXml::invoice('fixture', $payload, 'adjust-2'));
        self::assertSame('ORIG-1', (string) $xml->fejlec->helyesbitettSzamlaszam);
        self::assertSame('true', (string) $xml->fejlec->helyesbitoszamla);
        $payload['kind'] = 'reversal';
        $xml = simplexml_load_string(RequestXml::reversal('fixture', $payload));
        self::assertSame('ORIG-1', (string) $xml->fejlec->szamlaszam);
        self::assertFalse(isset($xml->beallitasok->szamlaKulsoAzon));
    }

    public function test_reconciliation_rejects_different_buyer(): void
    {
        $payload = (new InvoiceBuilder)->build(...$this->sale());
        $payload['buyer']['name'] = 'A different buyer';
        $this->expectException(RuntimeException::class);
        $this->client($this->record())->lookup('fixture', $payload, 'T-1', null);
    }

    public function test_equal_total_document_with_other_items_cannot_resolve_a_lost_reply(): void
    {
        $payload = (new InvoiceBuilder)->build(...$this->sale());
        $payload['lines'][1]['name'] = 'A different item with the same price';
        $this->expectException(RuntimeException::class);
        $this->client($this->record())->lookup('fixture', $payload, 'T-1', null);
    }

    public function test_changed_or_imported_sale_can_match_only_in_the_selected_mode(): void
    {
        [$order, $placement] = $this->sale();
        $order->receipt = json_encode(['address' => ['country' => 'HU', 'name' => 'Teszt & Társ',
            'postal_code' => '1111', 'city' => 'Budapest', 'street' => 'Példa utca 1.']]);
        $order->total = 4155 * 10 ** Money::fractionDigits('HUF');
        $placement['receipt']['total'] = 1700;
        $client = $this->client($this->record(), $this->record(), $this->record());
        self::assertSame('T-1', $client->matchOrder('fixture', $order, 'T-1', $placement, 'test')['number']);
        self::assertSame('T-1', $client->matchOrder('fixture', $order, 'T-1', null, 'test')['number']);
        $this->expectException(RuntimeException::class);
        $client->matchOrder('fixture', $order, 'T-1', null, 'live');
    }

    public function test_external_reversal_verifies_both_documents_and_signed_totals(): void
    {
        $source = $this->record();
        $body = (string) $source->getBody();
        $adjustment = str_replace(['<szamlaszam>T-1</szamlaszam>', '<tipus>SZ</tipus>', '<totalossz><netto>3450</netto><afa>705</afa>',
            '<brutto>4155.00</brutto>'], ['<szamlaszam>T-3</szamlaszam><hivszamlaszam>T-1</hivszamlaszam>',
                '<tipus>SS</tipus>', '<totalossz><netto>-3450</netto><afa>-705</afa>', '<brutto>-4155.00</brutto>'], $body);
        $amounts = ['net' => -3450, 'vat' => -705, 'gross' => -4155];
        $client = $this->client($source, new Response(200, [], $adjustment), $source, new Response(200, [], $adjustment));
        $result = $client->matchAdjustment('fixture', ['mode' => 'test'], 'T-1', 'T-3', 'reversal', $amounts);
        self::assertSame('T-3', $result['number']);
        self::assertSame(-4155, $result['gross']);
        $this->expectException(RuntimeException::class);
        $client->matchAdjustment('fixture', ['mode' => 'test'], 'T-1', 'T-3', 'reversal', ['net' => -10, 'vat' => -2, 'gross' => -12]);
    }

    public function test_invalid_xml_is_rejected_before_any_submission(): void
    {
        $client = $this->client();
        $payload = (new InvoiceBuilder)->build(...$this->sale());
        $payload['buyer']['address'] = "invalid\x01address";
        try {
            $client->submit('fixture', $payload, 'id');
            self::fail('An invalid control character was accepted.');
        } catch (Rejected) {
            self::assertCount(0, $this->requests);
        }
    }

    public static function errors(): array
    {
        return [['3', Rejected::class], ['71', RuntimeException::class], ['152', RuntimeException::class], ['999', RuntimeException::class]];
    }

    #[DataProvider('errors')]
    public function test_known_rejections_and_ambiguous_errors_are_distinct(string $code, string $class): void
    {
        $client = $this->client($this->reply('<sikeres>false</sikeres><hibakod>'.$code.'</hibakod><hibauzenet>secret-value</hibauzenet>'));
        try {
            $client->submit('fixture', (new InvoiceBuilder)->build(...$this->sale()), 'stable');
            self::fail('A provider error was accepted.');
        } catch (DomainException|RuntimeException $e) {
            self::assertSame($class, $e::class);
            self::assertStringNotContainsString('secret-value', $e->getMessage());
            self::assertCount(1, $this->requests);
        }
    }
}
