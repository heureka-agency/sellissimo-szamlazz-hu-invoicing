@extends('szamlazz::shell')
@section('title', __('szamlazz::ui.documents'))
@section('invoice-content')
<header><a href="{{ cp_route('sellissimo.order',$order->id) }}">{{ __('szamlazz::ui.order') }} {{ $order->order_number }}</a>
<h1>{{ __('szamlazz::ui.documents') }}</h1><p>{{ $order->site }} · {{ $pro ? 'Pro' : 'Free' }} · {{ __('szamlazz::ui.'.$settings['mode']) }}</p></header>
@forelse($history->groupBy('mode') as $mode=>$rows)
<section><h2>{{ __('szamlazz::ui.'.$mode) }}</h2>
@foreach($rows as $row)
<article class="sz-card"><strong>{{ __('szamlazz::ui.'.$row->kind) }} {{ $row->number }}</strong>
<p>{{ __('szamlazz::ui.'.($row->status==='uncertain'?'uncertain_state':$row->status)) }} · {{ $row->updated_at }} · {{ substr($row->account_id,0,8) }}</p>
@if(isset($row->payload['original_number']))<p>{{ __('szamlazz::ui.original') }} {{ $row->payload['original_number'] }}</p>@endif
@if($row->email_requested)<p>{{ __('szamlazz::ui.email_requested') }} · {{ $row->email_response==='issuance_accepted' ? __('szamlazz::ui.issuance_accepted') : __('szamlazz::ui.no_delivery_evidence') }}</p>@endif
@if($row->reason)<p class="sz-message">{{ $row->reason }}</p>@endif
@if($row->status==='issued')<a href="{{ cp_route('szamlazz.pdf',$row->id) }}">{{ __('szamlazz::ui.pdf') }}</a>@endif
@can(\Sellissimo\SzamlazzHu\ServiceProvider::ISSUE)
@if(in_array($row->status,['submitting','uncertain']))
<form method="post" action="{{ cp_route('szamlazz.lookup',$row->id) }}">@csrf
<label>{{ __('szamlazz::ui.number') }}<input name="number" @required($row->kind==='reversal') maxlength="128"></label>
<button class="sz-button">{{ __('szamlazz::ui.lookup') }}</button></form>
@endif @endcan</article>
@endforeach</section>
@empty<p>{{ __('szamlazz::ui.no_documents') }}</p>@endforelse
@can(\Sellissimo\SzamlazzHu\ServiceProvider::ISSUE)
@if(!$original && !$history->contains(fn($r)=>$r->kind==='invoice' && $r->status!=='rejected'))
<section class="sz-card"><h2>{{ __('szamlazz::ui.prepare') }}</h2>
<form method="post" action="{{ cp_route('szamlazz.preview',$order->id) }}">@csrf<input type="hidden" name="kind" value="invoice">
<div class="sz-grid">@foreach(['fulfilment_date','due_date'] as $f)<label>{{ __('szamlazz::ui.'.$f) }}<input type="date" name="{{ $f }}" value="{{ now('Europe/Budapest')->addDays($f==='due_date'?(int)$settings['due_days']:0)->toDateString() }}"></label>@endforeach</div>
<button class="sz-button">{{ __('szamlazz::ui.preview') }}</button></form></section>
<section class="sz-card"><h2>{{ __('szamlazz::ui.external') }}</h2>
<form method="post" action="{{ cp_route('szamlazz.match',$order->id) }}">@csrf<label>{{ __('szamlazz::ui.number') }}<input name="number" required maxlength="128"></label><button class="sz-button">{{ __('szamlazz::ui.match') }}</button></form>
</section>
@elseif($original && !empty($original->payload['lines']))
<section class="sz-card sz-fields"><h2>{{ __('szamlazz::ui.adjust') }}</h2>
@foreach($candidates as $source=>$candidate)
<p>{{ $candidate['reason'] }}</p>
@if($candidate['eligible'])<form method="post" action="{{ cp_route('szamlazz.preview',$order->id) }}">@csrf<input type="hidden" name="kind" value="{{ $candidate['kind'] }}"><input type="hidden" name="source" value="{{ $source }}"><button class="sz-button">{{ __('szamlazz::ui.preview') }}</button></form>@endif
@endforeach
<form method="post" action="{{ cp_route('szamlazz.preview',$order->id) }}">@csrf<input type="hidden" name="kind" value="reversal"><input type="hidden" name="source" value="manual-reversal">
<button class="sz-button">{{ __('szamlazz::ui.preview_reversal') }}</button></form></section>
@endif
@if($original)<section class="sz-card"><h2>{{ __('szamlazz::ui.external_adjustment') }}</h2>
<form method="post" action="{{ cp_route('szamlazz.match',$order->id) }}">@csrf
<div class="sz-grid"><label>{{ __('szamlazz::ui.number') }}<input name="number" maxlength="128" required></label>
<label>{{ __('szamlazz::ui.documents') }}<select name="kind">@foreach(['correction','reversal'] as $kind)<option value="{{ $kind }}">{{ __('szamlazz::ui.'.$kind) }}</option>@endforeach</select></label></div>
<label>{{ __('szamlazz::ui.source') }}<select name="source">@foreach($candidates as $source=>$candidate)<option value="{{ $source }}">{{ $candidate['reason'] }} · {{ $source }}</option>@endforeach<option value="manual-external">{{ __('szamlazz::ui.external') }}</option></select></label>
<div class="sz-grid">@foreach(['net','vat','gross'] as $field)<label>{{ __('szamlazz::ui.'.$field) }} (HUF)<input name="{{ $field }}" type="number" step="1" required></label>@endforeach</div>
<button class="sz-button">{{ __('szamlazz::ui.match') }}</button></form></section>@endif
@endcan
@if($pro)<a href="{{ cp_route('szamlazz.queue',['site'=>$order->site]) }}">{{ __('szamlazz::ui.queue') }}</a>@endif
@endsection
