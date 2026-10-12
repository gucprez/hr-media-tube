{{-- Hoja carta con 1, 2 o 4 tickets. Variables: $ticket, $plan, $company, $logo, $reprint --}}
<table class="sheet n{{ $plan['copies'] }} l{{ $plan['copies'] }}">
    @php $k = 0; @endphp
    @for($r = 0; $r < $plan['rows']; $r++)
        <tr>
            @for($c = 0; $c < $plan['cols']; $c++)
                <td class="cell l{{ $plan['copies'] }}">
                    @include('operations.tickets._ticket', ['label' => $plan['labels'][$k] ?? 'COPIA', 'n' => $k + 1, 'total' => $plan['copies']])
                </td>
                @php $k++; @endphp
            @endfor
        </tr>
    @endfor
</table>
