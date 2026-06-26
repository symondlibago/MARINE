<x-mail::message>
# Return Note {{ $rn->rtn_number }}

Dear {{ $rn->vendor_name ?: optional($rn->vendor)->name }},

Please find attached return note **{{ $rn->rtn_number }}** raised against purchase order **{{ optional($rn->purchaseOrder)->po_number }}**. The goods listed below are being returned and we kindly request a credit for the amount shown.

<x-mail::table>
| Item | Reason | Qty returned | Unit cost | Credit |
|:-----|:-------|-------------:|----------:|-------:|
@foreach($rn->items as $line)
| {{ $line->description }}{{ $line->unit ? ' ('.$line->unit.')' : '' }} | {{ $line->reason ?: '—' }} | {{ number_format($line->qty, 2) }} | {{ number_format($line->unit_cost, 2) }} {{ $rn->currency }} | {{ number_format($line->line_total, 2) }} {{ $rn->currency }} |
@endforeach
</x-mail::table>

**Total credit: {{ number_format($rn->subtotal, 2) }} {{ $rn->currency }}**

Kindly confirm the credit against the above purchase order at your earliest convenience.

Thanks,<br>
{{ $staff?->name ?? 'Matria Marine Services' }}
</x-mail::message>
