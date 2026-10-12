@extends('layouts.app')
@section('title', 'Numeración')
@section('content')
@include('admin.settings._tabs')
<form method="POST" action="{{ route('admin.settings.numbering.update') }}" class="card card-body space-y-4">
    @csrf @method('PUT')
    <p class="text-sm text-slate-500">Defina prefijo y relleno de ceros de cada documento. El correlativo nunca puede retroceder, para evitar números duplicados.</p>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Documento</th><th>Prefijo</th><th>Dígitos</th><th>Siguiente número</th><th>Ejemplo</th></tr></thead>
            <tbody>
            @foreach($sequences as $s)
                <tr>
                    <td class="font-medium">{{ $labels[$s->key] ?? $s->key }}</td>
                    <td><input name="seq[{{ $s->id }}][prefix]" value="{{ $s->prefix }}" class="input w-28" maxlength="20"></td>
                    <td><input type="number" name="seq[{{ $s->id }}][padding]" value="{{ $s->padding }}" min="1" max="10" class="input w-24"></td>
                    <td><input type="number" name="seq[{{ $s->id }}][next_number]" value="{{ $s->next_number }}" min="{{ $s->next_number }}" class="input w-32"></td>
                    <td class="font-mono text-xs text-slate-500">{{ $s->prefix.str_pad((string) $s->next_number, $s->padding, '0', STR_PAD_LEFT) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <div><button class="btn-primary">Guardar numeración</button></div>
</form>
@endsection
