@props(['status'])
@php $s = app(\App\Services\Inventory\StockStatus::class); @endphp
<span {{ $attributes->merge(['class' => $s->badge($status)]) }}>{{ $s->label($status) }}</span>
