{{--
    The one footer, on every document Matria sends out.

    Dru asked for this in one line — "kindly fix all the footers kay lahi lahi
    og format, dapat uniform tanan" — and he was right on every count. There
    were three different layouts across four documents and no footer at all on
    the other four, so the same company looked like three companies depending on
    which paper you were holding.

    Defined here once so it cannot drift again: change the company's address and
    every document follows, which was the other half of what he was asking for.

    @param array $company  config('procurement.company')
    @param bool  $bank     print the bank line — only where the customer is
                           being asked to pay us. A purchase order carries our
                           own bank details to a vendor for no reason, and a
                           delivery note is not a request for money.
--}}
@php
    // One line, not three. A footer is a strip at the foot of the page; the
    // address block belongs in the letterhead at the top, where it already is.
    //
    // Each line is stripped of its own trailing comma before they are joined,
    // because the stored address already punctuates some of them: "…Unit B,"
    // followed by a newline came out as "…Unit B,, Pantech Business Hub".
    $footerAddress = collect(preg_split('/\R/u', (string) ($company['address'] ?? '')))
        ->map(fn ($line) => rtrim(trim($line), ','))
        ->filter()
        ->implode(', ');

    $contact = array_filter([
        ! empty($company['email']) ? 'email: '.$company['email'] : null,
        $company['website'] ?? null,
        $company['phone'] ?? null,
        ! empty($company['uen']) ? 'UEN: '.$company['uen'] : null,
    ]);
@endphp
<div class="page-footer">
    <div class="rule">
        <span class="co">{{ $company['name'] ?? '' }}</span><br>
        @if($footerAddress !== ''){{ $footerAddress }}<br>@endif
        {!! implode(' &nbsp;·&nbsp; ', array_map('e', $contact)) !!}
        @if(! empty($bank) && ! empty($company['bank_name']))
            <br>Bank: {{ $company['bank_name'] }} &nbsp;|&nbsp; Account: {{ $company['bank_account'] ?? '' }} &nbsp;|&nbsp; SWIFT: {{ $company['bank_swift'] ?? '' }}
        @endif
    </div>
</div>
