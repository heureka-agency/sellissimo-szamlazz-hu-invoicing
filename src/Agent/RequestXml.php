<?php

namespace Sellissimo\SzamlazzHu\Agent;

use DOMDocument;
use DOMElement;
use Sellissimo\Core\Money;

final class RequestXml
{
    private DOMDocument $document;

    private DOMElement $root;

    private function __construct(private string $schema, string $root)
    {
        $this->document = new DOMDocument('1.0', 'UTF-8');
        $this->root = $this->document->createElementNS('http://www.szamlazz.hu/'.$root, $root);
        $this->document->appendChild($this->root);
    }

    public static function invoice(#[\SensitiveParameter] string $key, array $p, string $externalId): string
    {
        if (! in_array($p['kind'], ['invoice', 'correction'], true) || $externalId === ''
            || ($p['kind'] === 'correction' && empty($p['original_number']))) {
            throw new Rejected('The invoice is missing its document reference.');
        }
        $xml = new self('invoice', 'xmlszamla');
        $xml->block('beallitasok', [
            'szamlaagentkulcs' => $key, 'eszamla' => $p['electronic'], 'szamlaLetoltes' => true,
            'valaszVerzio' => 2, 'szamlaKulsoAzon' => $externalId,
        ]);
        $xml->block('fejlec', [
            'keltDatum' => $p['issue_date'], 'teljesitesDatum' => $p['fulfilment_date'],
            'fizetesiHataridoDatum' => $p['due_date'], 'fizmod' => $p['payment_method'], 'penznem' => $p['currency'],
            'szamlaNyelve' => $p['language'], 'rendelesSzam' => $p['order_number'],
            ...($p['kind'] === 'correction' ? ['helyesbitoszamla' => true, 'helyesbitettSzamlaszam' => $p['original_number']] : []),
            'fizetve' => $p['paid'],
        ]);
        $xml->block('elado', []);
        $buyer = $p['buyer'];
        $xml->block('vevo', [
            'nev' => $buyer['name'], 'orszag' => 'Magyarország', 'irsz' => $buyer['postcode'], 'telepules' => $buyer['city'],
            'cim' => $buyer['address'], 'email' => $buyer['email'], 'sendEmail' => $p['send_email'],
            'adoalany' => $buyer['type'] === 'business' ? 1 : -1,
            ...($buyer['type'] === 'business' ? ['adoszam' => $buyer['tax_number']] : []),
        ]);
        $items = $xml->block('tetelek', []);
        foreach ($p['lines'] as $row) {
            $discount = $row['discount_minor'] ?? 0;
            $xml->block('tetel', [
                'megnevezes' => $row['name'], 'mennyiseg' => $row['qty'], 'mennyisegiEgyseg' => $row['unit'],
                'nettoEgysegar' => $row['unit_net'], 'afakulcs' => $row['vat_code'],
                'nettoErtek' => $row['net'], 'afaErtek' => $row['vat'], 'bruttoErtek' => $row['gross'],
                ...($discount > 0 ? ['megjegyzes' => trans('szamlazz::fiscal.discount', [], $p['language']).' '.Money::decimalWithDigits($discount, $p['digits']).' HUF'] : []),
            ], $items);
        }

        return $xml->validated();
    }

    public static function reversal(#[\SensitiveParameter] string $key, array $p): string
    {
        if (empty($p['original_number'])) {
            throw new Rejected('A reversal requires the original provider invoice number.');
        }
        $xml = new self('reversal', 'xmlszamlast');
        $xml->block('beallitasok', [
            'szamlaagentkulcs' => $key, 'eszamla' => $p['electronic'], 'szamlaLetoltes' => true, 'valaszVerzio' => 2,
        ]);
        $xml->block('fejlec', [
            'szamlaszam' => $p['original_number'], 'keltDatum' => $p['issue_date'],
            'teljesitesDatum' => $p['fulfilment_date'], 'tipus' => 'SS',
        ]);
        if ($p['send_email']) {
            $xml->block('vevo', ['email' => $p['buyer']['email']]);
        }

        return $xml->validated();
    }

    public static function lookup(#[\SensitiveParameter] string $key, ?string $number, ?string $externalId): string
    {
        if (blank($number) === blank($externalId)) {
            throw new Rejected('Use one exact invoice number or external document ID.');
        }
        $xml = new self('lookup', 'xmlszamlaxml');
        $xml->fields($xml->root, ['szamlaagentkulcs' => $key, ...($number ? ['szamlaszam' => $number] : []),
            'pdf' => true, ...($externalId ? ['szamlaKulsoAzon' => $externalId] : [])]);

        return $xml->validated();
    }

    public static function pdf(#[\SensitiveParameter] string $key, string $number): string
    {
        $xml = new self('pdf', 'xmlszamlapdf');
        $xml->fields($xml->root, ['szamlaagentkulcs' => $key, 'szamlaszam' => $number, 'valaszVerzio' => 2]);

        return $xml->validated();
    }

    private function block(string $tag, array $fields, ?DOMElement $parent = null): DOMElement
    {
        $block = $this->document->createElementNS($this->root->namespaceURI, $tag);
        ($parent ?? $this->root)->appendChild($block);
        $this->fields($block, $fields);

        return $block;
    }

    private function fields(DOMElement $parent, array $fields): void
    {
        foreach ($fields as $name => $value) {
            $node = $this->document->createElementNS($this->root->namespaceURI, $name);
            $value = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            if (! preg_match('/^[\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]*$/uD', $value)) {
                throw new Rejected(__('szamlazz::fiscal.xml_characters'));
            }
            $node->appendChild($this->document->createTextNode($value));
            $parent->appendChild($node);
        }
    }

    private function validated(): string
    {
        $previous = libxml_use_internal_errors(true);
        try {
            if (! $this->document->schemaValidate(__DIR__.'/../../resources/schemas/'.$this->schema.'.xsd')) {
                throw new Rejected(__('szamlazz::fiscal.xml_invalid'));
            }

            return $this->document->saveXML();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
