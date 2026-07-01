<?php

namespace App\Http\Controllers;

use App\Mail\CustomerInvoiceMail;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\DeliveryOrder;
use App\Models\Offer;
use App\Models\SentLog;
use App\Support\DocNumber;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Customer invoices (money IN). Two ways in: generated from an accepted Offer
 * (the procurement pipeline) or created directly when Matria supplies the goods
 * itself. Each invoice also carries the whole document trail for that job.
 */
class CustomerInvoiceController extends Controller
{
    public function index()
    {
        $invoices = CustomerInvoice::with([
            'rfq:id,reference',
            'rfq.purchaseOrders:id,rfq_id,po_number',
            'deliveryOrder:id,do_number,proforma_number',
            'creator:id,name',
        ])->orderByDesc('id')->get();

        $data = $invoices->map(fn (CustomerInvoice $inv) => [
            'id' => $inv->id,
            'invoice_number' => $inv->invoice_number,
            'customer_name' => $inv->customer_name,
            'currency' => $inv->currency,
            'grand_total' => (float) $inv->grand_total,
            'status' => $inv->status,
            'issue_date' => optional($inv->issue_date)->toDateString(),
            'created_by' => $inv->creator?->name,
            'is_direct' => $inv->rfq_id === null,        // no enquiry behind it = Matria-as-vendor
            'references' => $this->trail($inv),
        ]);

        return response()->json(['success' => true, 'data' => $data]);
    }

    /** Create a blank DIRECT invoice (Matria is the supplier); lines are added in the editor. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'currency' => ['nullable', 'string', 'size:3'],
        ]);

        $customer = ! empty($data['customer_id']) ? Customer::find($data['customer_id']) : null;

        $invoice = CustomerInvoice::create([
            'invoice_number' => DocNumber::next('INV'),
            'customer_id' => $customer?->id,
            'customer_name' => $customer?->name,
            'customer_address' => $customer?->address,
            'currency' => strtoupper($data['currency'] ?? 'SGD'),
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'created_by' => $request->user()?->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Invoice created.',
            'data' => $invoice->load('items'),
        ], 201);
    }

    /** Generate (or return the existing) invoice for an accepted offer. */
    public function generateFromOffer(Request $request, Offer $offer)
    {
        $existing = CustomerInvoice::where('offer_id', $offer->id)->first();
        if ($existing) {
            return response()->json([
                'success' => true,
                'message' => 'An invoice already exists for this offer.',
                'data' => $existing->load('items'),
            ]);
        }

        $offer->load(['items', 'rfq:id,reference,customer_reference']);
        $do = DeliveryOrder::where('offer_id', $offer->id)->first();

        $invoice = DB::transaction(function () use ($offer, $do, $request) {
            $invoice = CustomerInvoice::create([
                'invoice_number' => DocNumber::next('INV'),
                'rfq_id' => $offer->rfq_id,
                'offer_id' => $offer->id,
                'delivery_order_id' => $do?->id,
                'customer_id' => $offer->customer_id,
                'customer_name' => $offer->customer_name,
                'customer_address' => $offer->customer_address,
                'customer_reference' => $offer->rfq?->customer_reference,
                'currency' => $offer->currency,
                'status' => 'draft',
                'issue_date' => now()->toDateString(),
                'packing_cost' => $offer->packing_cost,
                'transportation_cost' => $offer->transportation_cost,
                'payment_terms' => $offer->payment_terms,
                'delivery_terms' => $offer->delivery_terms,
                'origin_type' => $offer->origin_type,
                'created_by' => $request->user()?->id,
            ]);

            $sort = 0;
            foreach ($offer->items as $it) {
                $invoice->items()->create([
                    'is_heading' => (bool) $it->is_heading,
                    'description' => $it->description,
                    'code' => $it->code,
                    'unit' => $it->unit,
                    'qty' => $it->qty,
                    'unit_price' => $it->unit_price,
                    'line_total' => $it->line_total,
                    'remarks' => $it->remarks,
                    'sort' => $sort++,
                ]);
            }

            $invoice->recalcTotals();

            return $invoice;
        });

        return response()->json([
            'success' => true,
            'message' => 'Invoice created from the offer.',
            'data' => $invoice->load('items'),
        ], 201);
    }

    public function show(CustomerInvoice $invoice)
    {
        $invoice->load([
            'items',
            'rfq:id,reference',
            'rfq.purchaseOrders:id,rfq_id,po_number',
            'deliveryOrder:id,do_number,proforma_number',
            'customer:id,name,address,email',
            'creator:id,name,phone',
        ]);

        $payload = $invoice->toArray();
        $payload['references'] = $this->trail($invoice);

        return response()->json(['success' => true, 'data' => $payload]);
    }

    public function update(Request $request, CustomerInvoice $invoice)
    {
        $data = $request->validate([
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_address' => ['nullable', 'string', 'max:2000'],
            'customer_reference' => ['nullable', 'string', 'max:255'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'status' => ['sometimes', 'string', 'in:draft,sent,paid'],
            'issue_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'payment_terms' => ['nullable', 'string', 'max:255'],
            'delivery_terms' => ['nullable', 'string', 'max:255'],
            'origin_type' => ['nullable', 'string', 'max:255'],
            'packing_cost' => ['nullable', 'numeric', 'min:0'],
            'transportation_cost' => ['nullable', 'numeric', 'min:0'],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['sometimes', 'array'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.is_heading' => ['nullable', 'boolean'],
            'items.*.description' => ['nullable', 'string', 'max:2000'],
            'items.*.code' => ['nullable', 'string', 'max:100'],
            'items.*.unit' => ['nullable', 'string', 'max:50'],
            'items.*.qty' => ['nullable', 'numeric', 'min:0'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($invoice, $data) {
            // Set/change the customer and re-snapshot their name + address.
            if (array_key_exists('customer_id', $data)) {
                $customer = $data['customer_id'] ? Customer::find($data['customer_id']) : null;
                $invoice->customer_id = $data['customer_id'];
                if ($customer) {
                    $invoice->customer_name = $customer->name;
                    $invoice->customer_address = $customer->address;
                }
            }

            foreach (['customer_name', 'customer_address', 'customer_reference', 'payment_terms', 'delivery_terms', 'origin_type', 'notes'] as $key) {
                if (array_key_exists($key, $data)) {
                    $invoice->{$key} = $data[$key];
                }
            }
            if (isset($data['currency'])) {
                $invoice->currency = strtoupper($data['currency']);
            }
            foreach (['issue_date', 'due_date'] as $key) {
                if (array_key_exists($key, $data)) {
                    $invoice->{$key} = $data[$key];
                }
            }
            foreach (['packing_cost', 'transportation_cost', 'tax_amount'] as $key) {
                if (array_key_exists($key, $data)) {
                    $invoice->{$key} = $data[$key] ?? 0;
                }
            }
            if (array_key_exists('status', $data)) {
                $invoice->status = $data['status'];
                if ($data['status'] === 'paid') {
                    $invoice->paid_at = $invoice->paid_at ?: now();
                } else {
                    $invoice->paid_at = null; // un-paid: don't leave a stale paid date the report would read
                }
            }
            $invoice->save();

            if (array_key_exists('items', $data)) {
                $keep = [];
                foreach (array_values($data['items']) as $i => $row) {
                    $heading = ! empty($row['is_heading']);
                    $qty = (float) ($row['qty'] ?? 0);
                    $price = (float) ($row['unit_price'] ?? 0);
                    $payload = [
                        'is_heading' => $heading,
                        'description' => $row['description'] ?? null,
                        'code' => $row['code'] ?? null,
                        'unit' => $row['unit'] ?? null,
                        'qty' => $heading ? 0 : $qty,
                        'unit_price' => $heading ? 0 : $price,
                        'line_total' => $heading ? 0 : round($qty * $price, 2),
                        'remarks' => $row['remarks'] ?? null,
                        'sort' => $i,
                    ];
                    if (! empty($row['id']) && ($item = $invoice->items()->whereKey($row['id'])->first())) {
                        $item->update($payload);
                        $keep[] = $item->id;
                    } else {
                        $keep[] = $invoice->items()->create($payload)->id;
                    }
                }
                $invoice->items()->whereNotIn('id', $keep)->delete();
            }

            $invoice->recalcTotals();
        });

        return response()->json([
            'success' => true,
            'message' => 'Invoice saved.',
            'data' => $invoice->fresh()->load('items'),
        ]);
    }

    public function destroy(CustomerInvoice $invoice)
    {
        $invoice->delete();

        return response()->json(['success' => true, 'message' => 'Invoice deleted.']);
    }

    public function pdf(CustomerInvoice $invoice)
    {
        $invoice->load(['items', 'rfq:id,reference', 'creator:id,name,phone']);

        $logoPath = public_path('logo.png');
        $logo = is_file($logoPath) ? 'data:image/png;base64,'.base64_encode(file_get_contents($logoPath)) : null;

        $pdf = Pdf::loadView('pdf.customer-invoice', [
            'invoice' => $invoice,
            'company' => config('procurement.company'),
            'logo' => $logo,
        ]);

        return $pdf->download(($invoice->invoice_number ?: 'invoice').'.pdf');
    }

    /** Email the invoice (PDF attached) to the customer. */
    public function email(Request $request, CustomerInvoice $invoice)
    {
        $invoice->load('customer:id,name,email');

        $emails = \App\Support\Recipients::emails($invoice->customer?->email);
        if (! $emails) {
            return response()->json(['success' => false, 'message' => 'This customer has no valid email on file.'], 422);
        }
        $email = implode(', ', $emails);

        $staff = $request->user();
        try {
            Mail::to($emails)->bcc(config('mail.from.address'))->send(new CustomerInvoiceMail($invoice, $staff));
        } catch (\Throwable $e) {
            SentLog::record([
                'type' => 'Invoice', 'reference' => $invoice->invoice_number,
                'recipient_name' => $invoice->customer_name, 'recipient_email' => $email,
                'subject' => 'Invoice '.$invoice->invoice_number,
                'status' => 'failed', 'error' => $e->getMessage(), 'sent_by' => $staff?->id, 'sent_by_name' => $staff?->name,
            ]);

            return response()->json(['success' => false, 'message' => 'Email failed: '.$e->getMessage()], 500);
        }

        SentLog::record([
            'type' => 'Invoice', 'reference' => $invoice->invoice_number,
            'recipient_name' => $invoice->customer_name, 'recipient_email' => $email,
            'subject' => 'Invoice '.$invoice->invoice_number,
            'status' => 'sent', 'sent_by' => $staff?->id, 'sent_by_name' => $staff?->name,
        ]);

        if ($invoice->status === 'draft') {
            $invoice->update(['status' => 'sent', 'sent_at' => now()]);
        }

        return response()->json(['success' => true, 'message' => 'Invoice emailed to '.$email.'.']);
    }

    /** The full document trail for the job behind an invoice (QTN → DO → ProINV → PO → INV). */
    private function trail(CustomerInvoice $invoice): array
    {
        return [
            'qtn' => $invoice->rfq?->reference,
            'do' => $invoice->deliveryOrder?->do_number,
            'proforma' => $invoice->deliveryOrder?->proforma_number,
            'po' => $invoice->rfq ? $invoice->rfq->purchaseOrders->pluck('po_number')->filter()->values()->all() : [],
            'inv' => $invoice->invoice_number,
        ];
    }
}
