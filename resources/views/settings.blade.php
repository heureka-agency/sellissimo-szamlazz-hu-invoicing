@extends('szamlazz::shell')
@section('title', __('szamlazz::ui.settings'))

@push('head')
<style>
.sz-settings .sz-settings-header { display: grid; gap: 1rem; }
.sz-settings .sz-site-switch { display: flex; flex-wrap: wrap; align-items: end; gap: .6rem; margin: 0; }
.sz-settings .sz-context { display: flex; flex-wrap: wrap; gap: .75rem 2.5rem; }
.sz-settings .sz-context dt, .sz-settings .sz-help, .sz-settings .sz-handle { font-size: .875rem; }
.sz-settings .sz-context dd { font-weight: 600; }
.sz-settings .sz-settings-form { gap: 1rem; }
.sz-settings .sz-settings-form section { margin: 0; }
.sz-settings .sz-settings-form h2 { margin: 0; }
.sz-settings .sz-help { margin: 0; }
.sz-settings .sz-account-states { display: flex; flex-wrap: wrap; gap: .5rem 2rem; }
.sz-settings .sz-account-states dd { font-size: .875rem; }
.sz-settings .sz-map { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: .5rem 1.25rem; align-items: center; border-top: 1px solid #8884; padding-top: .8rem; }
.sz-settings .sz-map-name { overflow-wrap: anywhere; }
.sz-settings .sz-map-name strong, .sz-settings .sz-map-name small { display: block; }
.sz-settings .sz-map label { font-size: .875rem; }
.sz-settings .sz-field-error { font-size: .875rem; font-weight: 600; }
.sz-settings [aria-invalid=true] { border: 2px solid #b54b42; }
.sz-settings .sz-button:focus-visible, .sz-settings :is(input,select):focus-visible { outline: 2px solid currentColor; outline-offset: 2px; }
@media (max-width: 34rem) {
    .sz-settings .sz-map { grid-template-columns: minmax(0, 1fr); }
}
</style>
@endpush

@section('invoice-content')
@php
    // Only scalar, non-secret form values are safe to render after validation.
    $display = static fn ($value) => is_scalar($value) ? (string) $value : '';
    $fieldValue = static fn ($key) => $display(old($key, $settings[$key] ?? ''));
    $checked = static fn ($key) => session()->hasOldInput('mode')
        ? old($key) === '1' || old($key) === 1 || old($key) === true
        : (bool) $settings[$key];
    $inlineErrors = [];
    $fieldError = static function (string ...$paths) use ($errors, &$inlineErrors) {
        $messages = [];
        foreach ($paths as $path) {
            if ($errors->has($path)) {
                $inlineErrors[$path] = true;
                $messages = array_merge($messages, $errors->get($path));
            }
        }
        return implode(' ', array_unique($messages));
    };
    $groups = [
        'vat_codes' => ['choices' => $vatClasses, 'inactive' => $inactiveVat, 'heading' => 'vat_codes', 'help' => 'vat_help', 'empty' => 'no_tax_classes'],
        'payment_methods' => ['choices' => $gateways, 'inactive' => $inactiveGateways, 'heading' => 'payment_methods', 'help' => 'payment_help', 'empty' => 'no_gateways'],
    ];
@endphp
<div class="sz-settings">
    <header class="sz-settings-header">
        <a href="{{ cp_route('sellissimo.settings', ['site' => $site]) }}">{{ __('szamlazz::ui.store_settings') }}</a>
        <h1>{{ __('szamlazz::ui.settings') }}</h1>
        @if(count($sites) > 1)
            <form class="sz-site-switch" method="get" action="{{ cp_route('szamlazz.settings') }}">
                <label>{{ __('szamlazz::ui.site') }}
                    <select name="site">
                        @foreach($sites as $handle => $name)
                            <option value="{{ $handle }}" @selected($handle === $site)>{{ $name }}</option>
                        @endforeach
                    </select>
                </label>
                <button class="sz-button" type="submit">{{ __('szamlazz::ui.switch_site') }}</button>
            </form>
        @endif
        <dl class="sz-context">
            @if(count($sites) <= 1)
                <div><dt>{{ __('szamlazz::ui.site') }}</dt><dd>{{ $sites[$site] ?? $site }}</dd></div>
            @endif
            <div><dt>{{ __('szamlazz::ui.edition') }}</dt><dd>{{ $pro ? 'Pro' : 'Free' }}</dd></div>
            <div><dt>{{ __('szamlazz::ui.active_account') }}</dt>
                <dd>{{ __('szamlazz::ui.'.$settings['mode']) }} · {{ __('szamlazz::ui.'.($settings['account_id'] ? 'key_saved' : 'key_missing')) }}</dd>
            </div>
        </dl>
    </header>

    <form class="sz-settings-form" method="post" action="{{ cp_route('szamlazz.save', ['site' => $site]) }}">
        @csrf
        <section class="sz-card sz-fields" aria-labelledby="sz-agent-heading">
            <h2 id="sz-agent-heading">{{ __('szamlazz::ui.connection') }}</h2>
            <div class="sz-grid">
                <label>{{ __('szamlazz::ui.mode') }}
                    <select name="mode" @if($errors->has('mode')) aria-invalid="true" aria-describedby="sz-mode-error" @endif>
                        @foreach(['test', 'live'] as $mode)
                            <option value="{{ $mode }}" @selected($fieldValue('mode') === $mode)>{{ __('szamlazz::ui.'.$mode) }}</option>
                        @endforeach
                    </select>
                    @if($message = $fieldError('mode'))<small class="sz-field-error" id="sz-mode-error">{{ $message }}</small>@endif
                </label>
                <label>{{ __('szamlazz::ui.key') }}
                    <input type="password" name="credential" autocomplete="new-password" spellcheck="false" aria-describedby="sz-key-help{{ $errors->has('credential') ? ' sz-key-error' : '' }}"
                        @if($errors->has('credential')) aria-invalid="true" @endif>
                    @if($message = $fieldError('credential'))<small class="sz-field-error" id="sz-key-error">{{ $message }}</small>@endif
                </label>
            </div>
            <p class="sz-help" id="sz-key-help">{{ __('szamlazz::ui.key_hint') }}</p>
            <dl class="sz-account-states">
                @foreach(['test', 'live'] as $mode)
                    <div><dt>{{ __('szamlazz::ui.'.$mode) }}</dt>
                        <dd>{{ __('szamlazz::ui.'.(isset($settings['accounts'][$mode]) ? 'key_saved' : 'key_missing')) }}</dd>
                    </div>
                @endforeach
            </dl>
        </section>

        <section class="sz-card sz-fields" aria-labelledby="sz-defaults-heading">
            <h2 id="sz-defaults-heading">{{ __('szamlazz::ui.defaults') }}</h2>
            <div class="sz-grid">
                <label>{{ __('szamlazz::ui.language') }}
                    <select name="language" @if($errors->has('language')) aria-invalid="true" aria-describedby="sz-language-error" @endif>
                        @foreach(['hu' => 'Magyar', 'en' => 'English'] as $value => $label)
                            <option value="{{ $value }}" @selected($fieldValue('language') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @if($message = $fieldError('language'))<small class="sz-field-error" id="sz-language-error">{{ $message }}</small>@endif
                </label>
                <label>{{ __('szamlazz::ui.due_days') }}
                    <input type="number" min="0" max="365" name="due_days" required value="{{ $fieldValue('due_days') }}"
                        @if($errors->has('due_days')) aria-invalid="true" aria-describedby="sz-days-error" @endif>
                    @if($message = $fieldError('due_days'))<small class="sz-field-error" id="sz-days-error">{{ $message }}</small>@endif
                </label>
            </div>
            <input type="hidden" name="electronic" value="0">
            <label class="sz-check">
                <input type="checkbox" name="electronic" value="1" @checked($checked('electronic')) @if($errors->has('electronic')) aria-invalid="true" aria-describedby="sz-electronic-error" @endif>
                {{ __('szamlazz::ui.electronic') }}
            </label>
            @if($message = $fieldError('electronic'))<small class="sz-field-error" id="sz-electronic-error">{{ $message }}</small>@endif
        </section>

        @foreach($groups as $field => $group)
            @php
                $isVat = $field === 'vat_codes';
                $submitted = old($field);
                $rowsByHandle = [];
                if (is_string($submitted)) {
                    // Validation can redirect an older text-based form into this row editor.
                    foreach (preg_split('/\R/', $submitted, -1, PREG_SPLIT_NO_EMPTY) as $index => $line) {
                        $pair = array_map('trim', explode('=', $line, 2));
                        if (count($pair) === 2 && array_key_exists($pair[0], $group['choices'])) {
                            $rowsByHandle[$pair[0]] ??= ['index' => $index, 'value' => $pair[1]];
                        }
                    }
                }
                foreach (is_array($submitted) ? $submitted : [] as $index => $row) {
                    if (is_int($index) && is_array($row) && is_string($row['handle'] ?? null)) {
                        $rowsByHandle[$row['handle']] ??= ['index' => $index, 'value' => $row['value'] ?? ''];
                    }
                }
            @endphp
            <section class="sz-card sz-fields" aria-labelledby="sz-{{ $field }}">
                <h2 id="sz-{{ $field }}">{{ __('szamlazz::ui.'.$group['heading']) }}</h2>
                @if(count($group['choices']))
                    <p class="sz-help">{{ __('szamlazz::ui.'.$group['help']) }}</p>
                @endif
                @forelse($group['choices'] as $handle => $label)
                    @php
                        $i = $loop->index;
                        $prior = $rowsByHandle[$handle] ?? null;
                        $value = $display($prior['value'] ?? $settings[$field][$handle] ?? '');
                        $path = $prior ? $field.'.'.$prior['index'] : null;
                        $rowError = $path ? $fieldError($path.'.value', $path.'.handle', $path) : '';
                    @endphp
                    <div class="sz-map">
                        <div class="sz-map-name" id="sz-{{ $field }}-{{ $i }}-name">
                            <strong>{{ $label }}</strong>
                            @if($label !== (string) $handle)<small class="sz-handle">{{ $handle }}</small>@endif
                            @if(in_array($handle, $group['inactive'], true))<small>{{ __('szamlazz::ui.inactive_mapping') }}</small>@endif
                        </div>
                        <input type="hidden" name="{{ $field }}[{{ $i }}][handle]" value="{{ $handle }}">
                        <label>
                            <span id="sz-{{ $field }}-{{ $i }}-label">{{ __('szamlazz::ui.'.($isVat ? 'vat_code' : 'invoice_payment_name')) }}</span>
                            @if($isVat)
                                <select name="{{ $field }}[{{ $i }}][value]" aria-labelledby="sz-{{ $field }}-{{ $i }}-label sz-{{ $field }}-{{ $i }}-name"
                                    @if($rowError) aria-invalid="true" aria-describedby="sz-{{ $field }}-{{ $i }}-error" @endif>
                                    <option value="" @selected($value === '')>{{ __('szamlazz::ui.unmapped') }}</option>
                                    @if($value !== '' && !in_array($value, \Sellissimo\SzamlazzHu\Fiscal\InvoiceBuilder::VAT_CODES, true))
                                        <option value="{{ $value }}" selected>{{ $value }} · {{ __('szamlazz::ui.unsupported_code') }}</option>
                                    @endif
                                    @foreach(\Sellissimo\SzamlazzHu\Fiscal\InvoiceBuilder::VAT_CODES as $code)
                                        <option value="{{ $code }}" @selected($value === $code)>{{ ctype_digit($code) ? $code.'%' : $code }}</option>
                                    @endforeach
                                </select>
                            @else
                                <input type="text" maxlength="80" name="{{ $field }}[{{ $i }}][value]" value="{{ $value }}"
                                    aria-labelledby="sz-{{ $field }}-{{ $i }}-label sz-{{ $field }}-{{ $i }}-name"
                                    @if($rowError) aria-invalid="true" aria-describedby="sz-{{ $field }}-{{ $i }}-error" @endif>
                            @endif
                            @if($rowError)<small class="sz-field-error" id="sz-{{ $field }}-{{ $i }}-error">{{ $rowError }}</small>@endif
                        </label>
                    </div>
                @empty
                    <p class="sz-help">{{ __('szamlazz::ui.'.$group['empty']) }}</p>
                @endforelse
            </section>
        @endforeach

        @if($pro)
            <section class="sz-card sz-fields" aria-labelledby="sz-automation-heading">
                <h2 id="sz-automation-heading">{{ __('szamlazz::ui.automation') }}</h2>
                <input type="hidden" name="automation_enabled" value="0">
                <label class="sz-check">
                    <input type="checkbox" name="automation_enabled" value="1" @checked($checked('automation_enabled')) @if($errors->has('automation_enabled')) aria-invalid="true" aria-describedby="sz-automation_enabled-error" @endif>
                    {{ __('szamlazz::ui.enable_automation') }}
                </label>
                @if($message = $fieldError('automation_enabled'))<small class="sz-field-error" id="sz-automation_enabled-error">{{ $message }}</small>@endif
                <label>{{ __('szamlazz::ui.trigger') }}
                    <select name="trigger" @if($errors->has('trigger')) aria-invalid="true" aria-describedby="sz-trigger-error" @endif>
                        @foreach(['payment', 'dispatch'] as $trigger)
                            <option value="{{ $trigger }}" @selected($fieldValue('trigger') === $trigger)>{{ __('szamlazz::ui.'.$trigger) }}</option>
                        @endforeach
                    </select>
                    @if($message = $fieldError('trigger'))<small class="sz-field-error" id="sz-trigger-error">{{ $message }}</small>@endif
                </label>
                <input type="hidden" name="send_email" value="0">
                <label class="sz-check">
                    <input type="checkbox" name="send_email" value="1" @checked($checked('send_email')) @if($errors->has('send_email')) aria-invalid="true" aria-describedby="sz-send_email-error" @endif>
                    {{ __('szamlazz::ui.send_email') }}
                </label>
                @if($message = $fieldError('send_email'))<small class="sz-field-error" id="sz-send_email-error">{{ $message }}</small>@endif
                @can(\Sellissimo\SzamlazzHu\ServiceProvider::VIEW)
                    <a href="{{ cp_route('szamlazz.queue', ['site' => $site]) }}">{{ __('szamlazz::ui.queue') }}</a>
                @endcan
            </section>
        @endif
        <button class="sz-button sz-primary" type="submit">{{ __('szamlazz::ui.save') }}</button>
    </form>
</div>
@endsection

@section('validation-summary')
    @if($inlineErrors)<p>{{ __('szamlazz::ui.review_fields') }}</p>@endif
    @foreach(array_diff_key($errors->getMessages(), $inlineErrors) as $messages)
        @foreach($messages as $message)<p>{{ $message }}</p>@endforeach
    @endforeach
@endsection
