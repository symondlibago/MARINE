<?php

namespace App\Support;

use App\Models\Rfq;
use App\Models\Vendor;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;

/**
 * Renders the per-vendor "Request for Quotation" PDF (no prices) — just the line
 * items a given vendor was asked to quote. Shared by the download route and the
 * RFQ email attachment so both produce an identical document.
 */
class EnquiryPdf
{
    /** Raw PDF bytes for this vendor's copy of the enquiry. */
    public static function render(Rfq $rfq, Vendor $vendor): string
    {
        $rfq->loadMissing(['customer:id,name,address', 'items', 'creator:id,name,email,phone']);

        // The vendor's scoped items. An empty pivot means "all items were sent".
        $rv = $rfq->rfqVendors()->where('vendor_id', $vendor->id)->with('items')->first();
        $scoped = $rv?->items;
        $items = ($scoped && $scoped->isNotEmpty())
            ? $rfq->items->whereIn('id', $scoped->pluck('id'))->values()
            : $rfq->items;

        $logoPath = public_path('logo.png');
        $logo = is_file($logoPath) ? 'data:image/png;base64,'.base64_encode(file_get_contents($logoPath)) : null;

        return Pdf::loadView('pdf.enquiry', [
            'rfq' => $rfq,
            'vendor' => $vendor,
            'items' => $items,
            'logo' => $logo,
        ])->output();
    }

    /** Download filename for this vendor's copy, e.g. RFQ-MMS-QTN-2026-00004-vendor-1.pdf */
    public static function filename(Rfq $rfq, Vendor $vendor): string
    {
        return 'RFQ-'.$rfq->reference.'-'.Str::slug($vendor->name).'.pdf';
    }
}
