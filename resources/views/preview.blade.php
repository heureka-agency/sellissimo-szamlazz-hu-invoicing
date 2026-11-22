@extends('szamlazz::shell')
@section('title', __('szamlazz::ui.preview'))
@section('invoice-content')
<header><a href="{{ cp_route('szamlazz.order',$order->id) }}">{{ __('szamlazz::ui.order') }} {{ $order->order_number }}</a>
<h1>{{ __('szamlazz::ui.preview') }} · {{ __('szamlazz::ui.'.$payload['kind']) }}</h1>
<p>{{ __('szamlazz::ui.'.$payload['mode']) }} · {{ $order->site }} · {{ substr($payload['account_id'],0,8) }}</p></header>
<section class="sz-card sz-grid"><div><h2>{{ __('szamlazz::ui.buyer') }}</h2>
@foreach(['name','tax_number','postcode','city','address','country','email'] as $f)<p>{{ $payload['buyer'][$f] ?? '' }}</p>@endforeach</div><div><h2>{{ __('szamlazz::ui.details') }}</h2>
@foreach(['issue_date','fulfilment_date','due_date','language','payment_method','original_number'] as $f)@isset($payload[$f])<p>{{ __('szamlazz::ui.'.$f) }} · <strong>{{ $payload[$f] }}</strong></p>@endisset @endforeach
<p>{{ __('szamlazz::ui.'.($payload['paid'] ? 'paid' : 'unpaid')) }}</p><p>{{ __('szamlazz::ui.'.($payload['electronic'] ? 'electronic' : 'paper')) }}</p>
@if($payload['send_email'])<p>{{ __('szamlazz::ui.send_email') }}</p>@endif</div></section>
<section class="sz-card sz-table"><table><thead><tr>@foreach(['item','qty','discount','vat_code','net','vat','gross'] as $f)<th>{{ __('szamlazz::ui.'.$f) }}</th>@endforeach</tr></thead>
<tbody>@foreach($payload['lines'] as $line)<tr><td>{{ $line['name'] }}</td><td>{{ $line['qty'] }}</td><td>{{ \Sellissimo\Core\Money::decimalWithDigits((int)($line['discount_minor']??0),$payload['digits']) }}</td><td>{{ $line['vat_code'] }}</td><td>{{ $line['net'] }}</td><td>{{ $line['vat'] }}</td><td>{{ $line['gross'] }}</td></tr>@endforeach</tbody>
<tfoot><tr><th colspan="4">HUF</th><th>{{ $payload['net'] }}</th><th>{{ $payload['vat'] }}</th><th>{{ $payload['gross'] }}</th></tr></tfoot></table></section>
<form method="post" action="{{ cp_route('szamlazz.issue',$order->id) }}">@csrf
@foreach(array_filter($fields) as $k=>$v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
<input type="hidden" name="digest" value="{{ $digest }}"><label class="sz-check"><input name="confirm" type="checkbox" value="1" required>{{ __('szamlazz::ui.confirm') }}</label>
<button class="sz-button sz-primary">{{ __('szamlazz::ui.issue_'.$payload['kind']) }}</button></form>
@endsection
