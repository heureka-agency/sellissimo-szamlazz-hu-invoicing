@extends('szamlazz::shell')
@section('title', __('szamlazz::ui.queue'))
@section('invoice-content')
<header><h1>{{ __('szamlazz::ui.queue') }}</h1><p>{{ $site }} · Pro</p></header>
@forelse($rows as $row)<article class="sz-card"><a href="{{ cp_route('szamlazz.order',$row->order_id) }}">{{ __('szamlazz::ui.order') }} {{ $row->order_number ?? $row->order_id }}</a>
<p>{{ $row->reason }}</p><small>{{ __('szamlazz::ui.last_attempt') }} · {{ $row->last_attempt_at }}</small>
@can(\Sellissimo\SzamlazzHu\ServiceProvider::ISSUE)<form method="post" action="{{ cp_route('szamlazz.review',$row->order_id) }}">@csrf<button class="sz-button">{{ __('szamlazz::ui.recheck') }}</button></form>@endcan</article>
@empty<p>{{ __('szamlazz::ui.clear') }}</p>@endforelse
{{ $rows->links() }}
@endsection
