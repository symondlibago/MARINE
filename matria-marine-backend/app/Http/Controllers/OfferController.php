<?php

namespace App\Http\Controllers;

use App\Mail\OfferMail;
use App\Models\Customer;
use App\Models\Offer;
use App\Models\OfferItem;
use App\Models\Rfq;
use App\Models\RfqItem;
use App\Models\SentLog;
use App\Support\DocNumber;
use App\Support\ProformaDoc;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class OfferController extends Controller
{
    public function index()
    {
        // The enquiry's vessel and the customer's own reference come along: those
        // are what staff recognise a job by, not our quotation number.
        $offers = Offer::with([
            'rfq:id,reference,ship_name,customer_reference',
            'customer:id,name',
            'creator:id,name',
        ])
            ->orderByDesc('id')
            ->get();

        return response()->json(['success' => true, 'data' => $offers]);
    }

    public function show(Offer $offer)
    {
        return response()->json(['success' => true, 'data' => $this->present($offer)]);
    }

    /**
     * The offer as the detail screen expects it.
     *
     * Defined once because more than one endpoint hands the page a fresh offer,
     * and the page seeds its cache from whatever it is given. A leaner payload
     * from one of them silently drops `enquiry_lines_missing` and
     * `enquiry_currency`, and the two warnings that depend on them disappear.
     */
    private function present(Offer $offer): array
    {
        $offer->load(['items', 'rfq:id,reference,ship_name,customer_reference', 'customer:id,name,address,email']);

        // Lines added to the enquiry after this quotation was built. An offer
        // keeps its own copy of every line, so nothing arrives on its own and a
        // gap is otherwise invisible — you would have to hold both pages side by
        // side to spot it. Reporting it here is what lets the page say so.
        $data = $offer->toArray();
        $data['enquiry_lines_missing'] = $this->enquiryLinesMissing($offer)
            ->map(fn ($i) => ['rfq_item_id' => $i->id, 'description' => $i->description])
            ->values();

        // The base prices are denominated in this, so a disagreement with the
        // offer's own currency means the totals on screen are labelled wrong.
        $data['enquiry_currency'] = $offer->rfq_id
            ? strtoupper((string) Rfq::where('id', $offer->rfq_id)->value('base_currency'))
            : null;

        return $data;
    }

    /** Enquiry lines with no line of their own on this offer. */
    private function enquiryLinesMissing(Offer $offer)
    {
        if (! $offer->rfq_id) {
            return collect();
        }

        return RfqItem::where('rfq_id', $offer->rfq_id)
            ->whereNotIn('id', $this->offerRfqItemIds($offer) ?: [0])
            ->orderBy('sort')->orderBy('id')
            ->get(['id', 'description']);
    }

    /**
     * Delete one quotation line, and the enquiry line behind it, immediately.
     *
     * Separate from update() on purpose. Folding it into the form's save meant
     * the line only vanished on screen until "Save changes" was pressed — so
     * anyone who deleted a line and then went to look at the enquiry found it
     * still there, and reasonably concluded the delete button did nothing.
     *
     * A delete button deletes. Nothing else on the form is touched, so unsaved
     * markup and lead times survive.
     */
    public function destroyItem(Offer $offer, OfferItem $item)
    {
        if ((int) $item->offer_id !== (int) $offer->id) {
            return response()->json(['success' => false, 'message' => 'That line is not on this quotation.'], 404);
        }

        $rfqItemId = $item->rfq_item_id;

        DB::transaction(function () use ($offer, $item, $rfqItemId) {
            $item->delete();
            $this->deleteEnquiryLines(array_filter([$rfqItemId]));
            $offer->recalcTotals();
        });

        // The same shape show() returns, so the page can seed its cache with
        // this and re-sync on the spot instead of the user having to press
        // "Refresh from enquiry" to see the line actually gone.
        return response()->json([
            'success' => true,
            'message' => $rfqItemId
                ? 'Line deleted from the quotation and the enquiry.'
                : 'Line deleted.',
            'data' => $this->present($offer->fresh()),
        ]);
    }

    /**
     * Delete enquiry lines outright, taking their quotes and awards with them.
     *
     * The database cascades the rows, but NOT the stored objects: a line's
     * attachments live on R2, and dropping the row would leave the files
     * behind, paid for and unreachable. So the bucket is cleared first, the
     * same way RfqController does it when a line is removed on the enquiry.
     *
     * @param  list<int>  $rfqItemIds
     */
    private function deleteEnquiryLines(array $rfqItemIds): void
    {
        if (! $rfqItemIds) {
            return;
        }

        RfqItem::with('attachments')->whereIn('id', $rfqItemIds)->get()
            ->each(function (RfqItem $item) {
                foreach ($item->attachments as $file) {
                    Storage::disk($file->disk)->delete($file->path);
                }

                $item->delete();
            });
    }

    /**
     * The enquiry lines this offer already carries, as integers.
     *
     * Cast deliberately: these are compared strictly, and a driver that hands
     * back "12" instead of 12 would make every line look new and duplicate the
     * whole offer on the next refresh.
     */
    private function offerRfqItemIds(Offer $offer): array
    {
        return $offer->items->pluck('rfq_item_id')->filter()->map(fn ($v) => (int) $v)->all();
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
                    // Internal cost coding, carried through from the enquiry.
                    'accounting_code' => $item->accounting_code,
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
            'customer_po_number' => ['nullable', 'string', 'max:255'],
            'packing_cost' => ['nullable', 'numeric', 'min:0'],
            'transportation_cost' => ['nullable', 'numeric', 'min:0'],
            // A discount off the whole quotation, for when it is agreed at the
            // bottom line rather than item by item.
            'discount_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'status' => ['sometimes', 'string', 'in:draft,sent,accepted,declined'],
            'items' => ['sometimes', 'array'],
            'items.*.id' => ['nullable', 'integer'],
            // A row with no id is a line Matria is adding itself; its price is
            // typed in rather than marked up from a vendor cost.
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            // Which way round this line was priced. Absent (an older page still
            // open in a browser) means the percentage drives it, as it always has.
            'items.*.priced_by' => ['nullable', 'string', 'in:markup,unit'],
            'remove_item_ids' => ['sometimes', 'array'],
            'remove_item_ids.*' => ['integer'],
            'items.*.description' => ['nullable', 'string', 'max:8000'],
            'items.*.code' => ['nullable', 'string', 'max:100'],
            'items.*.customs_code' => ['nullable', 'string', 'max:100'],
            'items.*.accounting_code' => ['nullable', 'string', 'max:100'],
            'items.*.unit' => ['nullable', 'string', 'max:50'],
            'items.*.qty' => ['nullable', 'numeric', 'min:0'],
            'items.*.base_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.markup_pct' => ['nullable', 'numeric'],
            // The VENDOR's discount: lowers what we pay, becomes our profit.
            'items.*.discount_pct' => ['nullable', 'numeric'],
            // The CUSTOMER's discount: lowers what they are charged. Capped at
            // 100 because it comes off this line alone, and a bigger number
            // would quote a negative amount.
            'items.*.cust_discount_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
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
                'customer_po_number' => array_key_exists('customer_po_number', $data) ? $data['customer_po_number'] : $offer->customer_po_number,
                'packing_cost' => array_key_exists('packing_cost', $data) ? ($data['packing_cost'] ?? 0) : $offer->packing_cost,
                'transportation_cost' => array_key_exists('transportation_cost', $data) ? ($data['transportation_cost'] ?? 0) : $offer->transportation_cost,
                'discount_pct' => array_key_exists('discount_pct', $data) ? ($data['discount_pct'] ?? 0) : $offer->discount_pct,
                'tax_rate' => array_key_exists('tax_rate', $data) ? ($data['tax_rate'] ?? 0) : $offer->tax_rate,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $offer->notes,
                'status' => $data['status'] ?? $offer->status,
            ])->save();

            // Lines Matria added by hand can be taken away again. Lines that
            // came from the enquiry cannot — those belong to the enquiry, and
            // removing one here would leave the two documents disagreeing.
            // Any line may be taken off a quotation, including one that came
            // from the enquiry: the customer declines an item, so it comes off
            // what we quote them. The enquiry, its awards and any purchase
            // order already raised are untouched — an offer is a document in
            // its own right, not a live view of the enquiry.
            //
            // Removing a line removes it from the ENQUIRY too, not just the
            // quotation.
            //
            // Anything less is not a delete: "Refresh from enquiry" pulls in
            // every enquiry line the offer does not have, so a line taken off
            // the quotation alone comes straight back and the customer ends up
            // quoted for something they declined.
            //
            // What that costs, checked against the live schema:
            //   awards, quote_items, rfq_item_attachments  CASCADE — destroyed
            //   purchase_order_items, offer_items          SET NULL — KEPT
            //
            // So a purchase order already sent to a vendor keeps its line, its
            // quantity and its price; every figure it carries is its own
            // snapshot. What is lost is the trail back to the enquiry: the
            // vendor's quoted price and the award for that line.
            if (! empty($data['remove_item_ids'])) {
                $removing = $offer->items()->whereIn('id', $data['remove_item_ids'])->get();

                $offer->items()->whereIn('id', $removing->pluck('id'))->delete();

                $this->deleteEnquiryLines($removing->pluck('rfq_item_id')->filter()->unique()->all());
            }

            if (array_key_exists('items', $data)) {
                foreach ($data['items'] as $row) {
                    // No id: a new line of Matria's own — an agency fee, a
                    // service — with no enquiry line and no vendor behind it.
                    if (empty($row['id'])) {
                        if (trim((string) ($row['description'] ?? '')) === '') {
                            continue;   // an empty row the user never filled in
                        }

                        $qty = (float) ($row['qty'] ?? 1);
                        $unit = (float) ($row['unit_price'] ?? 0);
                        $custDiscount = (float) ($row['cust_discount_pct'] ?? 0);
                        $m = $this->lineMaths(0, 0, 0, $qty, $unit, null, $custDiscount);

                        $offer->items()->create([
                            'rfq_item_id' => null,
                            'is_heading' => false,
                            'description' => $row['description'],
                            'code' => $row['code'] ?? null,
                            'customs_code' => $row['customs_code'] ?? null,
                            'accounting_code' => $row['accounting_code'] ?? null,
                            'unit' => $row['unit'] ?? null,
                            'qty' => $qty,
                            'base_price' => 0,
                            'base_source' => 'Added by Matria',
                            'markup_pct' => 0,
                            'unit_price' => $m['unit'],
                            'discount_pct' => 0,
                            'discount_amount' => 0,
                            'cust_discount_pct' => $custDiscount,
                            'cust_discount_amount' => $m['cust_discount_amount'],
                            'markup_amount' => $m['markup_amount'],
                            'line_total' => $m['line_total'],
                            'lead_time' => $row['lead_time'] ?? null,
                            'delivery_location' => $row['delivery_location'] ?? null,
                            'remarks' => $row['remarks'] ?? null,
                            'sort' => (int) ($row['sort'] ?? 999),
                        ]);

                        continue;
                    }
                    $item = $offer->items()->whereKey($row['id'])->first();
                    if (! $item) {
                        continue;
                    }
                    $base = array_key_exists('base_price', $row) ? (float) $row['base_price'] : (float) $item->base_price;
                    $markup = array_key_exists('markup_pct', $row) ? (float) $row['markup_pct'] : (float) $item->markup_pct;
                    $discount = array_key_exists('discount_pct', $row) ? (float) $row['discount_pct'] : (float) $item->discount_pct;
                    $custDiscount = array_key_exists('cust_discount_pct', $row) ? (float) $row['cust_discount_pct'] : (float) $item->cust_discount_pct;
                    $qty = array_key_exists('qty', $row) ? (float) $row['qty'] : (float) $item->qty;

                    // A line with no enquiry behind it keeps its typed price:
                    // there is no vendor cost to mark up.
                    $manualUnit = $item->rfq_item_id === null
                        ? (float) ($row['unit_price'] ?? $item->unit_price)
                        : null;

                    // Priced the other way round: the seller typed what the line
                    // sells at and the percentage is worked out from it. Kept to
                    // the cent, because deriving it back from a percentage
                    // rounded to two decimals lands a cent or two away.
                    $pinnedUnit = ($manualUnit === null && ($row['priced_by'] ?? null) === 'unit')
                        ? (float) ($row['unit_price'] ?? 0)
                        : null;

                    $m = $this->lineMaths($base, $markup, $discount, $qty, $manualUnit, $pinnedUnit, $custDiscount);
                    $unit = $m['unit'];
                    $discAmt = $m['discount_amount'];
                    $amount = $m['line_total'];
                    $markupAmt = $m['markup_amount'];

                    // Typing over the cost makes the vendor label a lie, so the
                    // line stops claiming a source it no longer has.
                    $baseSource = abs($base - (float) $item->base_price) > 0.00001
                        ? 'Entered by hand'
                        : $item->base_source;

                    $item->update([
                        'description' => $row['description'] ?? $item->description,
                        'code' => $row['code'] ?? null,
                        'customs_code' => $row['customs_code'] ?? null,
                        'accounting_code' => $row['accounting_code'] ?? $item->accounting_code,
                        'unit' => $row['unit'] ?? null,
                        'qty' => $qty,
                        'base_price' => $base,
                        'base_source' => $baseSource,
                        // Derived when the price was typed; as entered otherwise.
                        'markup_pct' => $m['markup_pct'],
                        'unit_price' => $unit,
                        'discount_pct' => $discount,
                        'discount_amount' => $discAmt,
                        'cust_discount_pct' => $custDiscount,
                        'cust_discount_amount' => $m['cust_discount_amount'],
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
            'data' => $this->present($offer->fresh()),
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

        return response()->json([
            'success' => true,
            'message' => $this->syncMessage($this->pullFromEnquiry($offer)),
            'data' => $offer->fresh('items'),
        ]);
    }

    /**
     * Reopen a quotation that has already gone out and pull the enquiry's new
     * lines in, in one step.
     *
     * The status guard on Refresh is there so a document the customer is
     * holding cannot be rewritten behind their back — but doing it by hand
     * means Status, Save, Refresh, three steps with nothing saying so. This
     * keeps the decision explicit while making it one click. Sending the
     * corrected quotation on to the customer stays a deliberate, separate act.
     */
    public function reopenAndSync(Offer $offer)
    {
        // An accepted quotation is the basis of the order and whatever has been
        // invoiced against it. Reopening that is not a one-click decision.
        if ($offer->status === 'accepted') {
            return response()->json([
                'success' => false,
                'message' => 'The customer has already accepted this quotation — changing its lines now would put it out of step with the order. Change the status by hand if you really mean to.',
            ], 422);
        }

        $reopened = $offer->status !== 'draft';

        if ($reopened) {
            $offer->update(['status' => 'draft']);
        }

        $message = $this->syncMessage($this->pullFromEnquiry($offer->fresh()));

        return response()->json([
            'success' => true,
            'message' => $reopened ? 'Reopened as a draft. '.$message : $message,
            'data' => $offer->fresh('items'),
        ]);
    }

    /**
     * Bring this offer back into step with its enquiry.
     *
     * Returns what moved, so the caller can word its own message.
     */
    private function pullFromEnquiry(Offer $offer): array
    {
        $offer->load('items');

        // Every base price on this offer is denominated in the enquiry's base
        // currency, so the offer's own currency is a label for those numbers,
        // not an independent choice. Re-reading the prices without re-reading
        // the label leaves EUR figures headed USD — worse than being stale.
        $enquiryCurrency = strtoupper((string) Rfq::where('id', $offer->rfq_id)->value('base_currency'));
        $currencyChanged = false;

        // The WHOLE enquiry, not just the lines already on the offer: a line
        // added to the enquiry after this quotation was built has to be able to
        // find its way on, which is the point of pressing Refresh.
        //
        // Awards and quotes come along so the price can be re-read from
        // whichever vendor is selected on Compare & Award *right now*.
        $source = RfqItem::with(['awards.quoteItem.quote', 'awards.vendor:id,name', 'quoteItems.quote.vendor:id,name'])
            ->where('rfq_id', $offer->rfq_id)
            ->orderBy('sort')->orderBy('id')
            ->get()
            ->keyBy('id');

        $textChanged = 0;
        $pricesChanged = 0;
        $added = 0;

        DB::transaction(function () use ($offer, $source, $enquiryCurrency, &$textChanged, &$pricesChanged, &$added, &$currencyChanged) {
            if ($enquiryCurrency && strtoupper((string) $offer->currency) !== $enquiryCurrency) {
                $offer->update(['currency' => $enquiryCurrency]);
                $currencyChanged = true;
            }

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
                    $custDiscount = (float) $line->cust_discount_pct;

                    $m = $this->lineMaths($base, $markup, $discount, $qty, null, null, $custDiscount);

                    $update += [
                        'base_price' => $base,
                        'base_source' => $baseSource,
                        'unit_price' => $m['unit'],
                        'discount_amount' => $m['discount_amount'],
                        'cust_discount_amount' => $m['cust_discount_amount'],
                        'markup_amount' => $m['markup_amount'],
                        'line_total' => $m['line_total'],
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

            // Lines added to the enquiry since this quotation was built. They
            // come on at cost with no markup, exactly as generate() would have
            // created them, so the pricing decision is still the user's.
            $onOffer = $this->offerRfqItemIds($offer);
            $sort = (int) $offer->items->max('sort');

            foreach ($source as $item) {
                if (in_array($item->id, $onOffer, true)) {
                    continue;
                }

                [$base, $baseSource] = $this->baseFor($item);
                $qty = (float) $item->qty;

                $offer->items()->create([
                    'rfq_item_id' => $item->id,
                    'award_id' => $item->awards->first()?->id,
                    'description' => $item->description,
                    'unit' => $item->unit,
                    'accounting_code' => $item->accounting_code,
                    'qty' => $qty,
                    'base_price' => $base,
                    'base_source' => $baseSource,
                    'markup_pct' => 0,
                    'unit_price' => round($base, 2),
                    'line_total' => round($base * $qty, 2),
                    'remarks' => $item->awards
                        ->map(fn ($a) => $a->quoteItem?->remarks)
                        ->filter(fn ($r) => $r !== null && $r !== '')
                        ->unique()
                        ->implode("\n") ?: null,
                    'sort' => ++$sort,
                ]);
                $added++;
            }

            // Line totals moved, so the offer's own totals have to follow.
            $offer->recalcTotals();
        });

        return [
            'added' => $added,
            'prices' => $pricesChanged,
            'text' => $textChanged,
            'currency' => $currencyChanged ? $enquiryCurrency : null,
        ];
    }

    /** Plain English for what a sync actually moved. */
    private function syncMessage(array $counts): string
    {
        $parts = [];
        if ($counts['added']) {
            $parts[] = "{$counts['added']} new line(s) brought in from the enquiry";
        }
        if ($counts['prices']) {
            $parts[] = "{$counts['prices']} price(s) re-read from the selected vendor";
        }
        if ($counts['text']) {
            $parts[] = "{$counts['text']} description(s) updated";
        }
        if ($counts['currency'] ?? null) {
            $parts[] = "the currency set to {$counts['currency']} to match the enquiry";
        }

        return $parts ? ucfirst(implode(' and ', $parts)).'.' : 'Already up to date with the enquiry.';
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

    /**
     * Pro-forma invoice straight off the quotation.
     *
     * For the customers who pay before anything ships: they never get a delivery
     * order, and the final invoice is a tax invoice that must not be amended
     * afterwards. This is the amendable document they pay against — change the
     * quotation and download it again.
     */
    public function proforma(Offer $offer)
    {
        $offer->load(['items', 'rfq:id,customer_reference', 'creator:id,name,phone']);

        // Numbered once and kept, so re-downloading gives the customer the same
        // document instead of a new number each time.
        if (! $offer->proforma_number) {
            $offer->update(['proforma_number' => DocNumber::next('ProINV')]);
            $offer->refresh();
        }

        $logoPath = public_path('logo.png');
        $logo = is_file($logoPath) ? 'data:image/png;base64,'.base64_encode(file_get_contents($logoPath)) : null;

        $pdf = Pdf::loadView('pdf.proforma-invoice', [
            'pf' => ProformaDoc::fromOffer($offer),
            'company' => config('procurement.company'),
            'logo' => $logo,
        ]);

        return $pdf->download(($offer->proforma_number ?: 'proforma').'.pdf');
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

    /**
     * The money on one offer line — the single definition, used by every path
     * that prices an offer.
     *
     * The discount here is the VENDOR'S, not the customer's. The vendor knocks
     * 10% off their own price: we buy at 585, still sell at 650, and the 65 is
     * our margin. So it does NOT reduce what the customer pays — which is why
     * it must never appear on a customer-facing document.
     *
     * Markup and discount stack; a line can be marked up AND bought cheap.
     * With no discount set this reduces to plain base + markup, so every line
     * priced before the discount meant this is left exactly as it was.
     *
     * A manual line — an agency fee, a service Matria performs itself — has no
     * vendor behind it. Its price is typed straight in rather than derived from
     * a cost, and since nothing was bought to provide it, the whole amount is
     * profit.
     *
     * @return array{unit: float, cost: float, discount_amount: float, line_total: float, markup_amount: float}
     */
    private function lineMaths(
        float $base,
        float $markup,
        float $discount,
        float $qty,
        ?float $manualUnit = null,
        ?float $pinnedUnit = null,
        float $custDiscount = 0
    ): array {
        if ($manualUnit !== null) {
            $gross = round($manualUnit * $qty, 2);
            $custOff = round($gross * $custDiscount / 100, 2);

            return [
                'unit' => round($manualUnit, 2),
                'cost' => 0.0,
                'discount_amount' => 0.0,
                'cust_discount_amount' => $custOff,
                'line_total' => round($gross - $custOff, 2),
                // No cost was incurred, so everything left after the customer's
                // discount is margin.
                'markup_amount' => round($gross - $custOff, 2),
                'markup_pct' => round($markup, 2),
            ];
        }

        $cost = round($base * (1 - $discount / 100), 2);    // what we pay the vendor, per unit

        // A typed price IS the price. 1,060.2267 sold at 1,500.00 needs 41.479176%;
        // stored at two decimals that is 41.48%, which multiplies back to 1,500.01.
        // So the figure entered is kept and the percentage is derived from it.
        $unit = $pinnedUnit !== null
            ? round($pinnedUnit, 2)
            : round($base * (1 + $markup / 100), 2);        // what the customer pays, per unit

        $pct = $pinnedUnit !== null
            ? ($base > 0 ? round(($unit / $base - 1) * 100, 2) : 0.0)
            : round($markup, 2);

        // What the customer's discount takes off this line. It comes out of our
        // margin, not off the unit price, so the price the customer was quoted
        // still reads as the price and the reduction is shown as its own figure.
        $gross = round($unit * $qty, 2);
        $custOff = round($gross * $custDiscount / 100, 2);

        return [
            'unit' => $unit,
            'cost' => $cost,
            // The vendor's discount per unit — money we keep, not money they save.
            'discount_amount' => round($base - $cost, 2),
            'cust_discount_amount' => $custOff,
            'line_total' => round($gross - $custOff, 2),
            'markup_amount' => round(($unit - $cost) * $qty - $custOff, 2),
            'markup_pct' => $pct,
        ];
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
