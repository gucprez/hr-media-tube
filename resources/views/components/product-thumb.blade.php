@props(['product', 'size' => 'h-12 w-12'])
@if($product->primaryImage)
    <img src="{{ $product->primaryImage->url(true) }}" alt="{{ $product->name }}" loading="lazy" class="{{ $size }} shrink-0 rounded-lg border border-slate-200 object-cover">
@else
    <div class="{{ $size }} flex shrink-0 items-center justify-center rounded-lg border border-slate-200 bg-slate-50 text-slate-300"><x-icon name="box" class="h-1/2 w-1/2" /></div>
@endif
