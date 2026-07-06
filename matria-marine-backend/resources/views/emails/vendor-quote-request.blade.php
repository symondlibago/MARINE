@component('mail::message')
# Request for Quotation

Dear {{ $vendor->name }},

{{ $bodyMessage ?: 'We would like to invite you to quote on the following provisions / stores request.' }}

**Reference:** {{ $rfq->reference }}
@if($rfq->ship_name)
**Vessel:** {{ $rfq->ship_name }}
@endif
@if($rfq->delivery_port)
**Delivery port:** {{ $rfq->delivery_port }}
@endif

Please use the button below to view the requested items and submit your prices. No login is required — this link is unique to you.

@component('mail::button', ['url' => $link])
Submit Your Quotation
@endcomponent

If the button or link does not open, a **PDF copy of this request is attached** to this email for your reference.

Thank you,<br>
{{ config('app.name') }}
@endcomponent
