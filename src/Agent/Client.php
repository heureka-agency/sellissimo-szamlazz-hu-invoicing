<?php

namespace Sellissimo\SzamlazzHu\Agent;

use Brick\Math\BigDecimal;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;
use Sellissimo\Core\Money;
use Sellissimo\Core\OrderNumber;
use SimpleXMLElement;

class Client
{
    private const ENDPOINT = 'https://www.szamlazz.hu/szamla/';

    private const RESPONSE_NS = 'http://www.szamlazz.hu/xmlszamlavalasz';

    public function __construct(private ?ClientInterface $http = null) {}

    public function submit(#[\SensitiveParameter] string $key, array $payload, string $externalId): array
    {
        $storno = $payload['kind'] === 'reversal';
        $request = $storno ? RequestXml::reversal($key, $payload) : RequestXml::invoice($key, $payload, $externalId);
        $reply = $this->request($key, $storno ? 'action-szamla_agent_st' : 'action-xmlagentxmlfile', $request);
        $this->success($reply);
        if (! $this->amount($reply->szamlanetto, $payload['net']) || ! $this->amount($reply->szamlabrutto, $payload['gross'])) {
            throw new RuntimeException('The issued document totals require reconciliation.');
        }

        return $this->result((string) $reply->szamlaszam, (string) $reply->pdf, $payload['send_email'])
            + ['email_response' => $payload['send_email'] ? 'issuance_accepted' : null];
    }

    public function lookup(#[\SensitiveParameter] string $key, array $payload, ?string $number, ?string $externalId): array
    {
        if ($payload['kind'] === 'reversal' && blank($number)) {
            throw new RuntimeException('Reversals must be reconciled by the provider number of the reversal.');
        }
        $reply = $this->query($key, $number, $number ? null : $externalId);
        $type = ['invoice' => 'SZ', 'correction' => 'HS', 'reversal' => 'SS'][$payload['kind']];
        $base = $reply->alap;
        $totals = $reply->osszegek->totalossz;
        $orderMatches = (string) $base->rendelesszam === (string) $payload['order_number'];
        // Storno may not copy the order number. Its exact issued reference and the original are both required.
        $refMatches = $type === 'SZ' || (string) $base->hivszamlaszam === $payload['original_number'];
        if (! $this->modeMatches($base, $payload['mode']) || ($type !== 'SS' && ! $this->itemsMatch($reply, $payload['lines']))
            || ($type !== 'SS' && ! $orderMatches) || ! $refMatches || (string) $base->tipus !== $type
            || (string) $base->devizanem !== $payload['currency'] || ! $this->buyerMatches($reply->vevo, $payload['buyer'])
            || ! $this->amount($totals->netto, $payload['net']) || ! $this->amount($totals->afa, $payload['vat'])
            || ! $this->amount($totals->brutto, $payload['gross'])) {
            throw new RuntimeException('The document identity, original reference or amounts do not match the frozen request.');
        }

        return $this->result((string) $base->szamlaszam, (string) $reply->pdf, $payload['send_email']);
    }

    /** Verify an independently issued invoice against the original commercial order, including foreign-currency orders. */
    public function matchOrder(#[\SensitiveParameter] string $key, object $order, string $number, ?array $placement = null, ?string $selectedMode = null): array
    {
        $reply = $this->query($key, $number, null);
        $base = $reply->alap;
        $address = json_decode($order->receipt, true, flags: JSON_THROW_ON_ERROR)['address'] ?? [];
        $buyer = in_array($placement['buyer']['type'] ?? null, ['business', 'person'], true) ? $placement['buyer'] : [
            'name' => ($address['company'] ?? '') ?: ($address['name'] ?? ''), 'postcode' => $address['postal_code'] ?? '',
            'city' => $address['city'] ?? '', 'address' => trim(($address['street'] ?? '').' '.($address['street2'] ?? '')),
            'country' => $address['country'] ?? '',
        ];
        $gross = Money::decimal((int) $order->total, $order->currency);
        $mode = $selectedMode ?? $placement['mode'] ?? '';
        if ((string) $base->tipus !== 'SZ' || (string) $base->rendelesszam !== ltrim(OrderNumber::display($order), '#')
            || (! in_array($mode, ['test', 'live'], true) || ! $this->modeMatches($base, $mode))
            || (string) $base->devizanem !== $order->currency || ! $this->buyerMatches($reply->vevo, $buyer)
            || ! $this->amount($reply->osszegek->totalossz->brutto, $gross)) {
            throw new RuntimeException('The external invoice does not match the order reference, buyer, currency and total.');
        }

        return $this->result((string) $base->szamlaszam, (string) $reply->pdf, false);
    }

    /** Link a correction issued in the provider UI without inferring its fiscal lines from the order. */
    public function matchAdjustment(#[\SensitiveParameter] string $key, array $original, string $originalNumber, string $number,
        string $kind, array $expected): array
    {
        if (! in_array($kind, ['correction', 'reversal'], true) || $number === $originalNumber
            || ! is_int($expected['net'] ?? null) || ! is_int($expected['vat'] ?? null) || ! is_int($expected['gross'] ?? null)
            || $expected['gross'] !== $expected['net'] + $expected['vat']) {
            throw new RuntimeException('A linked adjustment needs its original reference and signed HUF totals.');
        }
        $source = $this->query($key, $originalNumber, null, allowReversed: true);
        $document = $this->query($key, $number, null);
        $base = $document->alap;
        $orderNumber = (string) $source->alap->rendelesszam;
        $orderMatches = ($kind === 'reversal' && (string) $base->rendelesszam === '')
            || ($orderNumber !== '' && (string) $base->rendelesszam === $orderNumber);
        if ((string) $source->alap->tipus !== 'SZ' || (string) $source->alap->devizanem !== 'HUF'
            || ! $this->modeMatches($source->alap, $original['mode']) || ! $this->modeMatches($base, $original['mode'])
            || (string) $base->tipus !== ($kind === 'reversal' ? 'SS' : 'HS') || (string) $base->devizanem !== 'HUF'
            || ! $orderMatches || (string) $base->hivszamlaszam !== $originalNumber) {
            throw new RuntimeException('The adjustment does not reference the expected original invoice.');
        }
        if (isset($original['buyer']) && ! $this->buyerMatches($source->vevo, $original['buyer'])) {
            throw new RuntimeException('The original invoice buyer differs from the order.');
        }
        $buyer = ['country' => 'HU', 'name' => (string) $source->vevo->nev, 'postcode' => (string) $source->vevo->cim->irsz,
            'city' => (string) $source->vevo->cim->telepules, 'address' => (string) $source->vevo->cim->cim,
            'tax_number' => (string) $source->vevo->adoszam];
        if (! $this->buyerMatches($source->vevo, $buyer) || ! $this->buyerMatches($document->vevo, $buyer)) {
            throw new RuntimeException('The adjustment buyer does not match the original domestic invoice.');
        }
        foreach (['net' => 'netto', 'vat' => 'afa', 'gross' => 'brutto'] as $field => $xmlKey) {
            if (! $this->amount($document->osszegek->totalossz->{$xmlKey}, $expected[$field])
                || ($kind === 'reversal' && ! $this->amount($source->osszegek->totalossz->{$xmlKey}, -$expected[$field]))) {
                throw new RuntimeException('The signed adjustment amounts do not match.');
            }
        }

        return $this->result($number, (string) $document->pdf, false) + ['kind' => $kind, 'original_number' => $originalNumber,
            'currency' => 'HUF', 'net' => $expected['net'], 'vat' => $expected['vat'], 'gross' => $expected['gross']];
    }

    public function pdf(#[\SensitiveParameter] string $key, string $number): string
    {
        $reply = $this->request($key, 'action-szamla_agent_pdf', RequestXml::pdf($key, $number));
        $this->success($reply);
        if ((string) $reply->szamlaszam !== $number) {
            throw new RuntimeException('Számla Agent returned a different document.');
        }
        $result = $this->result($number, (string) $reply->pdf, false);

        return $result['pdf'] ?? throw new RuntimeException('Számla Agent did not return the original PDF.');
    }

    private function query(#[\SensitiveParameter] string $key, ?string $number, ?string $externalId, bool $allowReversed = false): SimpleXMLElement
    {
        $reply = $this->request($key, 'action-szamla_agent_xml', RequestXml::lookup($key, $number, $externalId));
        if ($reply->getName() !== 'szamla' || ($reply->getDocNamespaces()[''] ?? '') !== 'http://www.szamlazz.hu/szamla'
            || ($number !== null && (string) $reply->alap->szamlaszam !== $number)
            || (! $allowReversed && in_array((string) $reply->alap->sztornozott, ['true', '1'], true))) {
            throw new RuntimeException('Számla Agent could not verify an active document with that reference.');
        }

        return $reply;
    }

    private function modeMatches(SimpleXMLElement $base, string $mode): bool
    {
        $marker = (string) $base->teszt;

        return $mode === 'test' ? in_array($marker, ['true', '1'], true) : in_array($marker, ['false', '0'], true);
    }

    private function itemsMatch(SimpleXMLElement $document, array $expected): bool
    {
        $items = $document->tetelek->tetel;
        if (count($items) !== count($expected)) {
            return false;
        }
        foreach ($expected as $i => $line) {
            $actual = $items[$i];
            $code = $line['vat_code'];
            $rateMatches = ctype_digit($code) ? $this->amount($actual->afakulcs, $code) : (string) $actual->afakulcs === $code;
            if (! $rateMatches || $this->normalized((string) $actual->nev) !== $this->normalized($line['name'])
                || ! $this->amount($actual->mennyiseg, $line['qty']) || ! $this->amount($actual->netto, $line['net'])
                || ! $this->amount($actual->afa, $line['vat']) || ! $this->amount($actual->brutto, $line['gross'])) {
                return false;
            }
        }

        return true;
    }

    private function buyerMatches(SimpleXMLElement $actual, array $expected): bool
    {
        $country = strtoupper($expected['country'] ?? '');
        if (! preg_match('/^[A-Z]{2}$/D', $country)) {
            return false;
        }
        $countries = [$country];
        foreach (['hu', 'en'] as $locale) {
            $countries[] = \Locale::getDisplayRegion('und_'.$country, $locale);
        }
        if (! in_array($this->normalized((string) $actual->cim->orszag), array_map($this->normalized(...), $countries), true)) {
            return false;
        }
        foreach (['name' => $actual->nev, 'postcode' => $actual->cim->irsz, 'city' => $actual->cim->telepules,
            'address' => $actual->cim->cim] as $key => $field) {
            if (blank($expected[$key] ?? '') || $this->normalized((string) $field) !== $this->normalized($expected[$key])) {
                return false;
            }
        }
        if (array_key_exists('tax_number', $expected)) {
            return preg_replace('/[\s-]+/', '', (string) $actual->adoszam) === preg_replace('/[\s-]+/', '', $expected['tax_number']);
        }

        return true;
    }

    private function normalized(string $s): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($s)));
    }

    private function amount(SimpleXMLElement $value, int|string $expected): bool
    {
        $value = (string) $value;

        return preg_match('/^-?\d+(?:\.\d+)?$/D', $value) === 1 && BigDecimal::of($value)->isEqualTo($expected);
    }

    private function result(string $number, #[\SensitiveParameter] string $encodedPdf, bool $email): array
    {
        if (trim($number) === '') {
            throw new RuntimeException('Számla Agent did not identify the issued document.');
        }
        $pdf = $encodedPdf === '' ? null : base64_decode($encodedPdf, true);
        if ($pdf === false || ($pdf !== null && ! str_starts_with($pdf, '%PDF-'))) {
            throw new RuntimeException('The returned PDF could not be verified.');
        }

        return ['number' => $number, 'pdf' => $pdf, 'email_requested' => $email];
    }

    private function success(SimpleXMLElement $reply): void
    {
        if ($reply->getName() !== 'xmlszamlavalasz' || ($reply->getDocNamespaces()[''] ?? '') !== self::RESPONSE_NS
            || ! in_array((string) $reply->sikeres, ['true', '1'], true)) {
            throw new RuntimeException('Számla Agent did not confirm successful issuance.');
        }
    }

    private function request(#[\SensitiveParameter] string $key, string $field, #[\SensitiveParameter] string $xml): SimpleXMLElement
    {
        $cacheKey = 'sellissimo.szamlazz.session.'.hash('sha256', $key);
        $jar = new CookieJar;
        if ($session = Cache::get($cacheKey)) {
            $jar = CookieJar::fromArray(['JSESSIONID' => Crypt::decryptString($session)], 'www.szamlazz.hu');
        }
        try {
            $response = ($this->http ??= new HttpClient)->request('POST', self::ENDPOINT, [
                'connect_timeout' => 5, 'timeout' => 35, 'allow_redirects' => false, 'http_errors' => false, 'cookies' => $jar,
                'multipart' => [['name' => $field, 'filename' => 'request.xml', 'contents' => $xml, 'headers' => ['Content-Type' => 'application/xml']]],
            ]);
        } catch (GuzzleException) {
            // Never attach the transport exception: it can carry the entire authenticated request.
            throw new RuntimeException('No confirmed reply from Számla Agent. Reconcile before resubmission.');
        }
        if ($cookie = $jar->getCookieByName('JSESSIONID')) {
            Cache::put($cacheKey, Crypt::encryptString($cookie->getValue()), now()->addMinutes(85));
        }
        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException('Számla Agent returned HTTP '.$response->getStatusCode().'. Reconcile before resubmission.');
        }
        if ($code = $response->getHeaderLine('szlahu_error_code')) {
            $this->failure($code);
        }
        $raw = (string) $response->getBody();
        if (stripos($raw, '<!DOCTYPE') !== false) {
            throw new RuntimeException('Számla Agent returned an unexpected XML document.');
        }
        $reply = simplexml_load_string($raw, options: LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        if ($reply === false) {
            throw new RuntimeException('Számla Agent returned an unreadable response. Reconcile before resubmission.');
        }
        if ($reply->getName() === 'xmlszamlavalasz' && ($reply->getDocNamespaces()[''] ?? '') === self::RESPONSE_NS
            && in_array((string) $reply->sikeres, ['false', '0'], true)) {
            $this->failure((string) $reply->hibakod);
        }

        return $reply;
    }

    private function failure(string $code): never
    {
        // A duplicate reference, an unrecognised error, or a server-side failure cannot prove that no document exists.
        if (in_array($code, ['3', '53', '54', '57', '135', '136', '164', '202', '259', '260', '261', '262', '263', '264', '491'], true)) {
            $reason = match ($code) {
                '3', '135', '136', '164' => 'agent_auth',
                '54' => 'agent_format',
                '202' => 'agent_prefix',
                '259', '260', '261', '262', '263', '264' => 'agent_amounts',
                '491' => 'agent_business',
                default => 'agent_xml',
            };
            throw new Rejected(__('szamlazz::fiscal.'.$reason, ['code' => $code]));
        }
        $safeCode = ctype_digit($code) && strlen($code) <= 6 ? $code : 'unknown';
        throw new RuntimeException('Számla Agent requires reconciliation ('.$safeCode.').');
    }
}
