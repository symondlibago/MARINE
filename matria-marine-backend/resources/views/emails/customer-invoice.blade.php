<x-mail::message>
# Invoice {{ $invoice->invoice_number }}

Dear {{ $invoice->customer_name ?: 'Customer' }},

Please find attached our invoice **{{ $invoice->invoice_number }}**@if($invoice->customer_reference) for your order **{{ $invoice->customer_reference }}**@endif.

<x-mail::table>
| Item | Qty | Unit price | Amount |
|:-----|----:|-----------:|-------:|
@foreach($invoice->items->where('is_heading', false) as $line)
| {{ $line->description }}{{ $line->unit ? ' ('.$line->unit.')' : '' }} | {{ number_format($line->qty, 2) }} | {{ number_format($line->unit_price, 2) }} {{ $invoice->currency }} | {{ number_format($line->line_total, 2) }} {{ $invoice->currency }} |
@endforeach
</x-mail::table>

**Total: {{ number_format($invoice->grand_total, 2) }} {{ $invoice->currency }}**

@if($invoice->due_date)
Payment is due by **{{ $invoice->due_date->format('d M Y') }}**.
@endif

Thank you for your business.

Thanks,<br>
{{ $staff?->name ?? 'Matria Marine Services' }}
</x-mail::message>
