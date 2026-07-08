<x-mail::message>
# Quotation {{ $offer->offer_number }}

Dear {{ $offer->customer_name ?: 'Customer' }},

Thank you for your enquiry. Please find attached our quotation **{{ $offer->offer_number }}**@if($offer->rfq && $offer->rfq->ship_name) for vessel **{{ $offer->rfq->ship_name }}**@endif.

@php
    $packing = (float) $offer->packing_cost;
    $transport = (float) $offer->transportation_cost;
    $tax = (float) $offer->tax_amount;
    $grand = (float) $offer->grand_total ?: (float) $offer->subtotal;
    $hasExtras = $packing > 0 || $transport > 0 || $tax > 0;
@endphp
<x-mail::table>
| Item | Qty | Unit price | Amount |
|:-----|----:|-----------:|-------:|
@foreach($offer->items->where('is_heading', false) as $line)
| {{ $line->description }}{{ $line->unit ? ' ('.$line->unit.')' : '' }} | {{ number_format($line->qty, 2) }} | {{ number_format($line->unit_price, 2) }} {{ $offer->currency }} | {{ number_format($line->line_total, 2) }} {{ $offer->currency }} |
@endforeach
@if($hasExtras)
| **Subtotal** | | | {{ number_format($offer->subtotal, 2) }} {{ $offer->currency }} |
@if($packing > 0)
| Packing | | | {{ number_format($packing, 2) }} {{ $offer->currency }} |
@endif
@if($transport > 0)
| Transportation | | | {{ number_format($transport, 2) }} {{ $offer->currency }} |
@endif
@if($tax > 0)
| GST {{ rtrim(rtrim(number_format((float) $offer->tax_rate, 2), '0'), '.') }}% | | | {{ number_format($tax, 2) }} {{ $offer->currency }} |
@endif
@endif
</x-mail::table>

**Total: {{ number_format($grand, 2) }} {{ $offer->currency }}**

@isset($link)
<x-mail::button :url="$link">
Review &amp; accept this quotation
</x-mail::button>

You can review the full quotation and confirm your order online using the button above — no login required.
@endisset

@if($offer->valid_until)
This quotation is valid until **{{ $offer->valid_until->format('d M Y') }}**.
@endif

We look forward to your order.

Thanks,<br>
{{ $staff?->name ?? 'Matria Marine Services' }}
</x-mail::message>
