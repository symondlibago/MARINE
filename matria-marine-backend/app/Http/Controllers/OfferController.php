<?php

namespace App\Http\Controllers;

use App\Mail\OfferMail;
use App\Models\Customer;
use App\Models\Offer;
use App\Models\Rfq;
use App\Models\RfqItem;
use App\Models\SentLog;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class OfferController extends Controller
{
    public function index()
    {
        $offers = Offer::with(['rfq:id,reference', 'customer:id,name', 'creator:id,name'])
            ->orderByDesc('id')
            ->get();

        return response()->json(['success' => true, 'data' => $offers]);
    }

    public function show(Offer $offer)
    {
        $offer->load(['items', 'rfq:id,reference,ship_name', 'customer:id,name,address,email']);

        return response()->json(['success' => true, 'data' => $offer]);
    }

    /**
     * Build (or return the existing) customer offer for an enquiry. Each line's
     * base price = the awarded vendor's cost (converted to base currency), or the
     * lowest quote if a line isn't awarded yet. Markup starts at 0 — staff set it.
     */
    public function generate(Request $request, Rfq $rfq)
    {
        $existing = Offer::where('rfq_id', $rfq->id)->first();
        if ($existing) {
            return response()->json([
                'success' => true,
                'message' => 'Offer already exists for this enquiry.',
                'data' => $existing->load('items'),
            ]);
        }

        // Vendor names come along so each line can record whose price it used.
        $rfq->load(['items.awards.quoteItem.quote', 'items.awards.vendor:id,name', 'items.quoteItems.quote.vendor:id,name', 'customer']);

        $offer = DB::transaction(function () use ($rfq, $request) {
            $offer = Offer::create([
                'offer_number' => $rfq->reference, // Offer to client reuses the enquiry's MMS-QTN number
                'rfq_id' => $rfq->id,
                'customer_id' => $rfq->customer_id,
                'customer_name' => $rfq->customer?->name,
                'customer_address' => $rfq->customer?->address,
                'currency' => $rfq->base_currency,
                'status' => 'draft',
                'created_by' => $request->user()?->id,
            ]);

            $sort = 0;
            foreach ($rfq->items as $item) {
                [$base, $baseSource] = $this->baseFor($item);
                $qty = (float) $item->qty;
                $offer->items()->create([
                    'rfq_item_id' => $item->id,
                    // One offer line per enquiry line even when it was split, so
                    // the customer never sees which vendors we bought from.
                    'award_id' => $item->awards->first()?->id,
                    'description' => $item->description,
                    'unit' => $item->unit,
                    'qty' => $qty,
                    'base_price' => $base,
                    // Internal note only — never printed on the customer's PDF.
                    'base_source' => $baseSource,
                    'markup_pct' => 0,
                    'unit_price' => round($base, 2),
                    'line_total' => round($base * $qty, 2),
                    // Carry the vendor remark through; a split line keeps each
                    // vendor's remark, one per line.
                    'remarks' => $item->awards
                        ->map(fn ($a) => $a->quoteItem?->remarks)
                        ->filter(fn ($r) => $r !== null && $r !== '')
                        ->unique()
                        ->implode("\n") ?: null,
                    'sort' => $sort++,
                ]);
            }

            $offer->recalcTotals();

            return $offer;
        });

        return response()->json([
            'success' => true,
            'message' => 'Offer created.',
            'data' => $offer->load('items'),
        ], 201);
    }

    public function update(Request $request, Offer $offer)
    {
        $data = $request->validate([
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'valid_until' => ['nullable', 'date'],
            'payment_terms' => ['nullable', 'string', 'max:255'],
            'delivery_terms' => ['nullable', 'string', 'max:255'],
            'origin_type' => ['nullable', 'string', 'max:255'],
            'packing_cost' => ['nullable', 'numeric', 'min:0'],
            'transportation_cost' => ['nullable', 'numeric', 'min:0'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'status' => ['sometimes', 'string', 'in:draft,sent,accepted,declined'],
            'items' => ['sometimes', 'array'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.description' => ['nullable', 'string', 'max:8000'],
            'items.*.code' => ['nullable', 'string', 'max:100'],
            'items.*.customs_code' => ['nullable', 'string', 'max:100'],
            'items.*.unit' => ['nullable', 'string', 'max:50'],
            'items.*.qty' => ['nullable', 'numeric', 'min:0'],
            'items.*.base_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.markup_pct' => ['nullable', 'numeric'],
            'items.*.discount_pct' => ['nullable', 'numeric'],
            'items.*.lead_time' => ['nullable', 'string', 'max:100'],
            'items.*.delivery_location' => ['nullable', 'string', 'max:255'],
            'items.*.remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($offer, $data) {
            // Set/change the customer and re-snapshot their name + address.
            if (array_key_exists('customer_id', $data)) {
                $customer = $data['customer_id'] ? Customer::find($data['customer_id']) : null;
                $offer->customer_id = $data['customer_id'];
                $offer->customer_name = $customer?->name;
                $offer->customer_address = $customer?->address;
            }

            $offer->fill([
                'currency' => isset($data['currency']) ? strtoupper($data['currency']) : $offer->currency,
                'valid_until' => array_key_exists('valid_until', $data) ? $data['valid_until'] : $offer->valid_until,
                'payment_terms' => array_key_exists('payment_terms', $data) ? $data['payment_terms'] : $offer->payment_terms,
                'delivery_terms' => array_key_exists('delivery_terms', $data) ? $data['delivery_terms'] : $offer->delivery_terms,
                'origin_type' => array_key_exists('origin_type', $data) ? $data['origin_type'] : $offer->origin_type,
                'packing_cost' => array_key_exists('packing_cost', $data) ? ($data['packing_cost'] ?? 0) : $offer->packing_cost,
                'transportation_cost' => array_key_exists('transportation_cost', $data) ? ($data['transportation_cost'] ?? 0) : $offer->transportation_cost,
                'tax_rate' => array_key_exists('tax_rate', $data) ? ($data['tax_rate'] ?? 0) : $offer->tax_rate,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $offer->notes,
                'status' => $data['status'] ?? $offer->status,
            ])->save();

            if (array_key_exists('items', $data)) {
                foreach ($data['items'] as $row) {
                    if (empty($row['id'])) {
                        continue;
                    }
                    $item = $offer->items()->whereKey($row['id'])->first();
                    if (! $item) {
                        continue;
                    }
                    $base = array_key_exists('base_price', $row) ? (float) $row['base_price'] : (float) $item->base_price;
                    $markup = array_key_exists('markup_pct', $row) ? (float) $row['markup_pct'] : (float) $item->markup_pct;
                    $discount = array_key_exists('discount_pct', $row) ? (float) $row['discount_pct'] : (float) $item->discount_pct;
                    $qty = array_key_exists('qty', $row) ? (float) $row['qty'] : (float) $item->qty;

                    $unit = round($base * (1 + $markup / 100), 2);   // marked-up unit price
                    $discAmt = round($unit * $discount / 100, 2);    // discount per unit
                    $amount = round(($unit - $discAmt) * $qty, 2);   // line total (net of discount)
                    $markupAmt = round($amount - $base * $qty, 2);   // profit on the line

                    // Typing over the cost makes the vendor label a lie, so the
                    // line stops claiming a source it no longer has.
                    $baseSource = abs($base - (float) $item->base_price) > 0.00001
                        ? 'Entered by hand'
                        : $item->base_source;

                    $item->update([
                        'description' => $row['description'] ?? $item->description,
                        'code' => $row['code'] ?? null,
                        'customs_code' => $row['customs_code'] ?? null,
                        'unit' => $row['unit'] ?? null,
                        'qty' => $qty,
                        'base_price' => $base,
                        'base_source' => $baseSource,
                        'markup_pct' => $markup,
                        'unit_price' => $unit,
                        'discount_pct' => $discount,
                        'discount_amount' => $discAmt,
                        'markup_amount' => $markupAmt,
                        'line_total' => $amount,
                        'lead_time' => $row['lead_time'] ?? null,
                        'delivery_location' => $row['delivery_location'] ?? null,
                        'remarks' => $row['remarks'] ?? null,
                    ]);
                }
            }

            $offer->recalcTotals();
        });

        return response()->json([
            'success' => true,
            'message' => 'Offer saved.',
            'data' => $offer->fresh()->load('items'),
        ]);
    }

    /**
     * Re-pull the line descriptions from the enquiry.
     *
     * An offer keeps its own copy of each description, taken when it was
     * generated — that is what the customer quotation prints. Editing the
     * enquiry afterwards therefore does not reach it. This pulls the current
     * wording across on request, so it can never silently overwrite a
     * description someone deliberately rewrote for the customer.
     */
    public function syncFromEnquiry(Offer $offer)
    {
        if ($offer->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'This quotation has already been sent — reopen it as a draft first.',
            ], 422);
        }

        $offer->load('items');

        // Awards and quotes come along so the price can be re-read from
        // whichever vendor is selected on Compare & Award *right now*.
        $source = RfqItem::with(['awards.quoteItem.quote', 'awards.vendor:id,name', 'quoteItems.quote.vendor:id,name'])
            ->whereIn('id', $offer->items->pluck('rfq_item_id')->filter())
            ->get()
            ->keyBy('id');

        $textChanged = 0;
        $pricesChanged = 0;

        DB::transaction(function () use ($offer, $source, &$textChanged, &$pricesChanged) {
            foreach ($offer->items as $line) {
                $item = $source->get($line->rfq_item_id);

                if (! $item) {
                    continue;
                }

                $update = [];

                if ($line->description !== $item->description) {
                    $update['description'] = $item->description;
                    $update['unit'] = $item->unit ?: $line->unit;
                    $textChanged++;
                }

                // Re-price from the current selection. Changing your mind about
                // the vendor should reach a draft you haven't sent yet — the
                // offer only froze its price to protect a quotation already out.
                [$base, $baseSource] = $this->baseFor($item);

                if (abs($base - (float) $line->base_price) > 0.00001) {
                    // The markup, discount and lead time are the user's own work
                    // and are kept; only the cost underneath them moves.
                    $markup = (float) $line->markup_pct;
                    $discount = (float) $line->discount_pct;
                    $qty = (float) $line->qty;

                    $unit = round($base * (1 + $markup / 100), 2);
                    $discAmt = round($unit * $discount / 100, 2);
                    $amount = round(($unit - $discAmt) * $qty, 2);

                    $update += [
                        'base_price' => $base,
                        'base_source' => $baseSource,
                        'unit_price' => $unit,
                        'discount_amount' => $discAmt,
                        'markup_amount' => round($amount - $base * $qty, 2),
                        'line_total' => $amount,
                    ];
                    $pricesChanged++;
                } elseif ($baseSource && $baseSource !== $line->base_source) {
                    // Same figure, different vendor behind it — say so honestly.
                    $update['base_source'] = $baseSource;
                }

                if ($update) {
                    $line->update($update);
                }
            }

            // Line totals moved, so the offer's own totals have to follow.
            $offer->recalcTotals();
        });

        $parts = [];
        if ($pricesChanged) {
            $parts[] = "{$pricesChanged} price(s) re-read from the selected vendor";
        }
        if ($textChanged) {
            $parts[] = "{$textChanged} description(s) updated";
        }

        return response()->json([
            'success' => true,
            'message' => $parts ? ucfirst(implode(' and ', $parts)).'.' : 'Already up to date with the enquiry.',
            'data' => $offer->fresh('items'),
        ]);
    }

    public function destroy(Offer $offer)
    {
        $offer->delete();

        return response()->json(['success' => true, 'message' => 'Offer deleted.']);
    }

    public function pdf(Offer $offer)
    {
        $offer->load(['items', 'rfq:id,customer_reference,ship_name', 'creator:id,name,phone,email']);

        $logoPath = public_path('logo.png');
        $logo = is_file($logoPath) ? 'data:image/png;base64,'.base64_encode(file_get_contents($logoPath)) : null;

        $pdf = Pdf::loadView('pdf.offer', [
            'offer' => $offer,
            'company' => config('procurement.company'),
            'logo' => $logo,
        ]);

        return $pdf->download(($offer->offer_number ?: 'offer').'.pdf');
    }

    /** Email the quotation (with a customer acceptance magic link) to the customer. */
    public function email(Request $request, Offer $offer)
    {
        $offer->load('customer:id,name,email');

        $emails = \App\Support\Recipients::emails($offer->customer?->email);
        if (! $emails) {
            return response()->json(['success' => false, 'message' => 'This customer has no valid email on file.'], 422);
        }
        $email = implode(', ', $emails);

        if (! $offer->token) {
            $offer->forceFill(['token' => Str::random(48)])->save();
        }
        $link = rtrim(config('procurement.frontend_url'), '/').'/offer/'.$offer->token;

        $staff = $request->user();
        try {
            Mail::to($emails)->bcc(config('mail.from.address'))->send(new OfferMail($offer, $staff, $link));
        } catch (\Throwable $e) {
            SentLog::record([
                'type' => 'Quotation', 'reference' => $offer->offer_number,
                'recipient_name' => $offer->customer?->name, 'recipient_email' => $email,
                'subject' => 'Quotation '.$offer->offer_number,
                'status' => 'failed', 'error' => $e->getMessage(), 'sent_by' => $staff?->id, 'sent_by_name' => $staff?->name,
            ]);

            return response()->json(['success' => false, 'message' => 'Email failed: '.$e->getMessage()], 500);
        }

        SentLog::record([
            'type' => 'Quotation', 'reference' => $offer->offer_number,
            'recipient_name' => $offer->customer?->name, 'recipient_email' => $email,
            'subject' => 'Quotation '.$offer->offer_number,
            'status' => 'sent', 'sent_by' => $staff?->id, 'sent_by_name' => $staff?->name,
        ]);

        if ($offer->status === 'draft') {
            $offer->update(['status' => 'sent']);
        }

        return response()->json(['success' => true, 'message' => 'Quotation emailed to '.$email.'.']);
    }

    /** Base unit cost in the enquiry's base currency: the awarded price, else the lowest quote. */
    /**
     * The base price AND a plain-English note of where it came from.
     *
     * Staff pick a vendor on Compare & Award to set the offer price, and may
     * move that pick later when they decide who to actually buy from. The offer
     * keeps the price it was built with, so it has to carry its own record of
     * whose price that was — otherwise nobody can tell afterwards.
     *
     * With nothing picked the cheapest quote is used, which is a decision the
     * system makes on the user's behalf; that case is labelled explicitly so it
     * never looks like a deliberate choice.
     *
     * @return array{0: float, 1: ?string}
     */
    private function baseFor($item): array
    {
        $price = $this->basePriceFor($item);

        if ($item->awards->isNotEmpty()) {
            $names = $item->awards
                ->map(fn ($a) => $a->vendor?->name)
                ->filter()
                ->unique()
                ->values();

            if ($names->isEmpty()) {
                return [$price, null];
            }

            // A split line is priced on the weighted average, so say so.
            return [$price, $names->count() > 1
                ? $names->implode(' + ').' (weighted)'
                : $names->first()];
        }

        $cheapest = $item->quoteItems
            ->filter(fn ($qi) => $qi->unit_cost !== null)
            ->sortBy(fn ($qi) => (float) $qi->unit_cost * (float) ($qi->quote?->exchange_rate ?? 1))
            ->first();

        $vendor = $cheapest?->quote?->vendor?->name;

        return [$price, $vendor ? 'Cheapest — '.$vendor : null];
    }

    private function basePriceFor($item): float
    {
        if ($item->awards->isNotEmpty()) {
            // A line split across vendors is quoted to the customer as ONE line,
            // so its cost is the quantity-weighted average of what we actually
            // pay each vendor. With a single vendor this is just that vendor's
            // converted price, exactly as before.
            $qty = 0.0;
            $spend = 0.0;

            foreach ($item->awards as $award) {
                $rate = (float) ($award->quoteItem?->quote?->exchange_rate ?? 1);
                $q = (float) $award->qty_to_buy;
                $qty += $q;
                $spend += $q * (float) $award->unit_cost * $rate;
            }

            if ($qty > 0) {
                return round($spend / $qty, 4);
            }

            // Awarded but with zero quantity — fall back to the first price.
            $first = $item->awards->first();

            return round((float) $first->unit_cost * (float) ($first->quoteItem?->quote?->exchange_rate ?? 1), 4);
        }

        $costs = $item->quoteItems
            ->filter(fn ($qi) => $qi->unit_cost !== null)
            ->map(fn ($qi) => (float) $qi->unit_cost * (float) ($qi->quote?->exchange_rate ?? 1));

        return $costs->isNotEmpty() ? round((float) $costs->min(), 4) : 0.0;
    }
}
