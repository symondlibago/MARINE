<?php

namespace App\Http\Controllers;

use App\Models\Quote;
use App\Models\Rfq;
use App\Models\Vendor;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;

class RfqPdfController extends Controller
{
    /**
     * Per-vendor Request for Quotation as a PDF (no prices) — just the line items
     * this vendor was asked to quote, so staff can email each vendor their own RFQ
     * manually (for vendors who don't use the portal link).
     */
    public function enquiryVendor(Rfq $rfq, Vendor $vendor)
    {
        return response(\App\Support\EnquiryPdf::render($rfq, $vendor), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.\App\Support\EnquiryPdf::filename($rfq, $vendor).'"',
        ]);
    }

    /**
     * Per-vendor PDF of the quotation this vendor submitted — the same figures
     * shown in the vendor quotation modal, so staff can file or forward a
     * vendor's offer without re-typing it.
     */
    public function vendorQuote(Rfq $rfq, Vendor $vendor)
    {
        $quote = Quote::with('items')
            ->where('rfq_id', $rfq->id)
            ->where('vendor_id', $vendor->id)
            ->first();

        abort_if(! $quote, 404, 'This vendor has no quotation on this enquiry.');

        $rfq->load('items');

        // Only the lines this vendor was actually asked for. No rows in the
        // pivot means the whole enquiry was sent to them.
        $askedIds = $rfq->rfqVendors()
            ->with('items:id')
            ->where('vendor_id', $vendor->id)
            ->first()?->items->pluck('id')->all() ?? [];

        $lines = $rfq->items
            ->filter(fn ($i) => empty($askedIds) || in_array($i->id, $askedIds, true))
            ->map(function ($i) use ($quote) {
                $qi = $quote->items->firstWhere('rfq_item_id', $i->id);
                $quoted = $qi && $qi->unit_cost !== null;

                return [
                    'description' => $i->description,
                    'impa_no' => $i->impa_no,
                    'unit' => $i->unit,
                    'qty' => (float) $i->qty,
                    'quoted' => $quoted,
                    'unit_cost' => $quoted ? (float) $qi->unit_cost : null,
                    'line_total' => $quoted ? (float) $qi->unit_cost * (float) $i->qty : null,
                    'remarks' => $qi?->remarks,
                ];
            })
            ->values();

        $total = $lines->sum('line_total');
        $rate = (float) $quote->exchange_rate;

        $pdf = Pdf::loadView('pdf.vendor-quote', [
            'rfq' => $rfq,
            'vendor' => $vendor,
            'quote' => $quote,
            'lines' => $lines,
            'currency' => $quote->currency ?: $rfq->base_currency,
            'baseCurrency' => $rfq->base_currency,
            'grandTotal' => $total,
            // Only meaningful when the vendor quoted in a different currency.
            'grandTotalBase' => round($total * ($rate ?: 1), 2),
            'quotedCount' => $lines->where('quoted', true)->count(),
            'logo' => $this->logo(),
        ]);

        return $pdf->download('Quotation-'.$rfq->reference.'-'.Str::slug($vendor->name).'.pdf');
    }

    /** Per-vendor PDF: the line items awarded to this vendor on this enquiry. */
    public function vendorAward(Rfq $rfq, Vendor $vendor)
    {
        $rfq->load(['items.awards.quoteItem']);

        // Only this vendor's share of each line — on a split line that is the
        // portion awarded to them, not the whole quantity.
        $lines = $rfq->items
            ->flatMap(fn ($i) => $i->awards
                ->filter(fn ($a) => (int) $a->vendor_id === (int) $vendor->id)
                ->map(fn ($a) => [
                    'description' => $i->description,
                    'unit' => $i->unit,
                    'qty' => (float) $a->qty_to_buy,
                    'unit_cost' => (float) $a->unit_cost,
                    'line_total' => (float) $a->qty_to_buy * (float) $a->unit_cost,
                    'remarks' => $a->quoteItem?->remarks,
                ]))
            ->values();

        $quote = Quote::where('rfq_id', $rfq->id)->where('vendor_id', $vendor->id)->first();
        $currency = $quote?->currency ?? $rfq->base_currency;

        $pdf = Pdf::loadView('pdf.vendor-award', [
            'rfq' => $rfq,
            'vendor' => $vendor,
            'lines' => $lines,
            'currency' => $currency,
            'grandTotal' => $lines->sum('line_total'),
            'logo' => $this->logo(),
        ]);

        return $pdf->download('Award-'.$rfq->reference.'-'.Str::slug($vendor->name).'.pdf');
    }

    /** Overall quotation: every awarded line across all vendors, converted to the base currency. */
    public function summary(Rfq $rfq)
    {
        $rfq->load(['items.awards.vendor', 'items.awards.quoteItem.quote']);

        // Internal summary — a split line is listed once per vendor so it is
        // clear who supplies which portion.
        $lines = $rfq->items
            ->flatMap(fn ($i) => $i->awards->map(function ($a) use ($i, $rfq) {
                $quote = $a->quoteItem?->quote;
                $rate = (float) ($quote?->exchange_rate ?? 1);
                $unitBase = (float) $a->unit_cost * $rate;

                return [
                    'description' => $i->description,
                    'unit' => $i->unit,
                    'vendor' => $a->vendor?->name ?? '—',
                    'qty' => (float) $a->qty_to_buy,
                    'unit_cost' => (float) $a->unit_cost,
                    'currency' => $quote?->currency ?? $rfq->base_currency,
                    'line_total_base' => (float) $a->qty_to_buy * $unitBase,
                    'remarks' => $a->quoteItem?->remarks,
                ];
            }))
            ->values();

        $pdf = Pdf::loadView('pdf.award-summary', [
            'rfq' => $rfq,
            'lines' => $lines,
            'grandTotal' => $lines->sum('line_total_base'),
            'baseCurrency' => $rfq->base_currency,
            'logo' => $this->logo(),
        ]);

        return $pdf->download('Quotation-'.$rfq->reference.'.pdf');
    }

    /** Matria logo as a base64 data URI for embedding in PDFs (null if missing). */
    private function logo(): ?string
    {
        $path = public_path('logo.png');

        return is_file($path) ? 'data:image/png;base64,'.base64_encode(file_get_contents($path)) : null;
    }
}
