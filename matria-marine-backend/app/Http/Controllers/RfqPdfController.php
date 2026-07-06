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
        $rfq->load(['customer:id,name,address', 'items']);

        // The vendor's scoped items. An empty pivot means "all items were sent".
        $rv = $rfq->rfqVendors()->where('vendor_id', $vendor->id)->with('items')->first();
        $scoped = $rv?->items;
        $items = ($scoped && $scoped->isNotEmpty())
            ? $rfq->items->whereIn('id', $scoped->pluck('id'))->values()
            : $rfq->items;

        $pdf = Pdf::loadView('pdf.enquiry', [
            'rfq' => $rfq,
            'vendor' => $vendor,
            'items' => $items,
            'logo' => $this->logo(),
        ]);

        return $pdf->download('RFQ-'.$rfq->reference.'-'.Str::slug($vendor->name).'.pdf');
    }

    /** Per-vendor PDF: the line items awarded to this vendor on this enquiry. */
    public function vendorAward(Rfq $rfq, Vendor $vendor)
    {
        $rfq->load(['items.award']);

        $lines = $rfq->items
            ->filter(fn ($i) => $i->award && (int) $i->award->vendor_id === (int) $vendor->id)
            ->map(fn ($i) => [
                'description' => $i->description,
                'unit' => $i->unit,
                'qty' => (float) $i->award->qty_to_buy,
                'unit_cost' => (float) $i->award->unit_cost,
                'line_total' => (float) $i->award->qty_to_buy * (float) $i->award->unit_cost,
            ])->values();

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
        $rfq->load(['items.award.vendor', 'items.award.quoteItem.quote']);

        $lines = $rfq->items
            ->filter(fn ($i) => $i->award)
            ->map(function ($i) use ($rfq) {
                $quote = $i->award->quoteItem?->quote;
                $rate = (float) ($quote?->exchange_rate ?? 1);
                $unitBase = (float) $i->award->unit_cost * $rate;

                return [
                    'description' => $i->description,
                    'unit' => $i->unit,
                    'vendor' => $i->award->vendor?->name ?? '—',
                    'qty' => (float) $i->award->qty_to_buy,
                    'unit_cost' => (float) $i->award->unit_cost,
                    'currency' => $quote?->currency ?? $rfq->base_currency,
                    'line_total_base' => (float) $i->award->qty_to_buy * $unitBase,
                ];
            })->values();

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
