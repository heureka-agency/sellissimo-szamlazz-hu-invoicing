<?php

namespace Sellissimo\SzamlazzHu\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Sellissimo\Core\StaffSites;
use Sellissimo\Core\Store;
use Sellissimo\SzamlazzHu\Automation\Adjustments;
use Sellissimo\SzamlazzHu\Automation\Workflow;
use Sellissimo\SzamlazzHu\InvoiceMappings;
use Sellissimo\SzamlazzHu\Ledger;
use Sellissimo\SzamlazzHu\ServiceProvider as Access;
use Sellissimo\SzamlazzHu\Settings;

class InvoicingController
{
    public function __construct(private Store $store, private Settings $config, private Ledger $ledger) {}

    private function site(Request $request): string
    {
        $site = $request->query('site') ?? StaffSites::selected();
        abort_unless(is_string($site), 404);
        StaffSites::authorize($site);

        return $site;
    }

    private function authorizedOrder(string $id, bool $write = false): object
    {
        Gate::authorize(Access::VIEW);
        if ($write) {
            Gate::authorize(Access::ISSUE);
        }
        $order = $this->store->table('orders')->where('id', $id)->firstOrFail();
        StaffSites::authorize($order->site);

        return $order;
    }

    public function settings(Request $request, InvoiceMappings $mappings)
    {
        Gate::authorize(Access::MANAGE);
        $site = $this->site($request);
        $settings = $this->config->forSite($site);

        return view('szamlazz::settings', [
            'site' => $site,
            'sites' => StaffSites::allowed()->mapWithKeys(fn ($allowed) => [$allowed->handle() => $allowed->name()])->all(),
            'settings' => $settings,
            'pro' => $this->config->pro(),
            ...$mappings->options($site, $settings),
        ]);
    }

    public function save(Request $request)
    {
        Gate::authorize(Access::MANAGE);
        $site = $this->site($request);
        $credential = $request->input('credential');
        // Laravel flashes request input on validation errors, including application/json submissions.
        $request->request->remove('credential');
        $request->query->remove('credential');
        if ($request->isJson()) {
            $request->json()->remove('credential');
        }
        $url = cp_route('szamlazz.settings', ['site' => $site]);
        try {
            if ($credential !== null && (! is_string($credential) || ! preg_match('/^[a-zA-Z0-9_-]{8,200}$/D', $credential))) {
                throw ValidationException::withMessages(['credential' => __('szamlazz::ui.invalid_key')]);
            }
            $data = $request->validate([
                'mode' => 'bail|required|in:test,live', 'language' => 'bail|required|in:hu,en',
                'due_days' => 'bail|required|integer|between:0,365', 'electronic' => 'sometimes|boolean',
                'trigger' => 'sometimes|in:payment,dispatch', 'send_email' => 'sometimes|boolean',
                'automation_enabled' => 'sometimes|boolean',
            ], __('szamlazz::settings_validation'));
            $data['due_days'] = (int) $data['due_days'];
            foreach (['electronic', 'send_email', 'automation_enabled'] as $field) {
                $data[$field] = $request->boolean($field);
            }
            $this->config->save($site, $data + $request->only(['vat_codes', 'payment_methods']), $credential, mappingForm: true);
        } catch (ValidationException $error) {
            throw $error->redirectTo($url);
        }

        return redirect($url)->with('notice', __('szamlazz::ui.saved'));
    }

    public function order(string $order)
    {
        $order = $this->authorizedOrder($order);
        $settings = $this->config->forSite($order->site);
        $history = $this->ledger->history($order->id);
        $original = $history->first(fn ($r) => $r->status === 'issued' && $r->kind === 'invoice');
        $candidates = $original && Gate::allows(Access::ISSUE) ? app(Adjustments::class)->candidates($order) : [];

        return view('szamlazz::order', compact('order', 'history', 'settings', 'original', 'candidates') + ['pro' => $this->config->pro()]);
    }

    private function operation(Request $request): array
    {
        return $request->validate(['kind' => 'required|in:invoice,correction,reversal', 'source' => 'nullable|string|max:100',
            'fulfilment_date' => 'nullable|date_format:Y-m-d', 'due_date' => 'nullable|date_format:Y-m-d']);
    }

    public function preview(Request $request, string $order)
    {
        $order = $this->authorizedOrder($order, true);
        $fields = $this->operation($request);
        $payload = $this->ledger->preview($order, $fields['kind'], $fields['source'] ?? null, array_filter($fields));

        return view('szamlazz::preview', compact('order', 'fields', 'payload') + ['digest' => Ledger::fingerprint($payload)]);
    }

    public function issue(Request $request, string $order)
    {
        $record = $this->authorizedOrder($order, true);
        $fields = $this->operation($request);
        $request->validate(['confirm' => 'required|accepted', 'digest' => 'required|regex:/^[0-9a-f]{64}$/D']);
        $this->ledger->issue($order, $fields['kind'], $fields['source'] ?? null, array_filter($fields), $request->input('digest'),
            (string) $request->user()->getAuthIdentifier(), retry: true);

        return redirect(cp_route('szamlazz.order', $record->id));
    }

    public function match(Request $request, string $order)
    {
        $order = $this->authorizedOrder($order, true);
        $data = $request->validate(['number' => 'required|string|max:128']);
        $actor = (string) $request->user()->getAuthIdentifier();
        if ($request->filled('kind')) {
            $match = $request->validate(['kind' => 'required|in:correction,reversal', 'source' => 'required|string|max:100',
                'net' => 'required|integer|between:-999999999999,999999999999',
                'vat' => 'required|integer|between:-999999999999,999999999999',
                'gross' => 'required|integer|between:-999999999999,999999999999']);
            $amounts = array_map('intval', array_intersect_key($match, array_flip(['net', 'vat', 'gross'])));
            $this->ledger->matchAdjustment($order, $data['number'], $match['kind'], $match['source'], $amounts, $actor);
        } else {
            $this->ledger->match($order, $data['number'], $actor);
        }

        return redirect(cp_route('szamlazz.order', $order->id));
    }

    private function document(string $id, bool $write = false): object
    {
        $row = $this->store->table('sz_documents')->where('id', $id)->firstOrFail(['id', 'order_id', 'number']);
        $this->authorizedOrder($row->order_id, $write);

        return $row;
    }

    public function lookup(Request $request, string $document)
    {
        $row = $this->document($document, true);
        $data = $request->validate(['number' => 'nullable|string|max:128']);
        $this->ledger->reconcile($document, $data['number'] ?? null);

        return redirect(cp_route('szamlazz.order', $row->order_id));
    }

    public function pdf(string $document)
    {
        $row = $this->document($document);

        return response($this->ledger->pdf($document), 200, ['Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.preg_replace('/[^a-zA-Z0-9_.-]/', '_', $row->number ?? $document).'.pdf"',
            'X-Content-Type-Options' => 'nosniff']);
    }

    public function queue(Request $request)
    {
        Gate::authorize(Access::VIEW);
        abort_unless($this->config->pro(), 403);
        $site = $this->site($request);
        $rows = $this->store->table('szamlazz_reviews')->join('sellissimo_orders', 'sellissimo_orders.id', '=', 'sellissimo_szamlazz_reviews.order_id')
            ->where('sellissimo_szamlazz_reviews.site', $site)->where('sellissimo_orders.site', $site)->whereNull('resolved_at')
            ->orderByDesc('last_attempt_at')->paginate(40, ['sellissimo_szamlazz_reviews.*', 'sellissimo_orders.order_number'])->withQueryString();

        return view('szamlazz::queue', compact('rows', 'site'));
    }

    public function review(string $order)
    {
        $record = $this->authorizedOrder($order, true);
        abort_unless($this->config->pro(), 403);
        app(Workflow::class)->enqueue($record->id);

        return redirect(cp_route('szamlazz.queue', ['site' => $record->site]))->with('notice', __('szamlazz::ui.queued'));
    }
}
