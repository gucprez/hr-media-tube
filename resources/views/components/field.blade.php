@props(['name', 'label' => null, 'type' => 'text', 'value' => null, 'required' => false, 'hint' => null])
<div {{ $attributes->only('class') }}>
    @if($label)<label for="{{ $name }}" class="label">{{ $label }}@if($required) <span class="text-red-500">*</span>@endif</label>@endif
    @if($type === 'textarea')
        <textarea id="{{ $name }}" name="{{ $name }}" rows="3" class="input" {{ $attributes->except('class') }}>{{ old($name, $value) }}</textarea>
    @else
        <input id="{{ $name }}" name="{{ $name }}" type="{{ $type }}" value="{{ $type === 'password' ? '' : old($name, $value) }}" @required($required) class="input" {{ $attributes->except('class') }}>
    @endif
    @if($hint)<p class="mt-1 text-xs text-slate-500">{{ $hint }}</p>@endif
    @error($name)<p class="field-error">{{ $message }}</p>@enderror
</div>
