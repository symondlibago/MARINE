<x-mail::message>
# Purchase Order {{ $po->po_number }}

Dear {{ $po->vendor->name }},

Please find attached purchase order **{{ $po->po_number }}**@if($po->ship_name) for vessel **{{ $po->ship_name }}**@endif.

<x-mail::table>
| Item | Qty | Unit cost | Line total |
|:-----|----:|----------:|-----------:|
@foreach($po->items as $line)
| {{ $line->description }}{{ $line->unit ? ' ('.$line->unit.')' : '' }} | {{ number_format($line->qty, 2) }} | {{ number_format($line->unit_cost, 2) }} {{ $po->currency }} | {{ number_format($line->line_total, 2) }} {{ $po->currency }} |
@endforeach
</x-mail::table>

**Order total: {{ number_format($po->subtotal, 2) }} {{ $po->currency }}**

@isset($link)
<x-mail::button :url="$link">
Review &amp; accept this order
</x-mail::button>

You can review the full order and confirm acceptance online using the button above — no login required.
@endisset

@if($po->expected_date)
Requested delivery: **{{ $po->expected_date->format('d M Y') }}**@if($po->delivery_port) at **{{ $po->delivery_port }}**@endif.
@elseif($po->delivery_port)
Delivery port: **{{ $po->delivery_port }}**.
@endif

Kindly confirm receipt and acceptance of this order at your earliest convenience.

Thanks,<br>
{{ $staff?->name ?? 'Matria Marine Services' }}
</x-mail::message>
