<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\Payment;
use App\Models\PaymentAttachment;
use App\Models\PurchaseOrder;
use App\Models\Vendor;
use App\Support\DocNumber;
use App\Support\Settlement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Payments received from customers and paid out to vendors.
 *
 * The accountant enters what actually hit the bank, then ticks the invoices
 * (or purchase orders) it settles. The outstanding balance on the statement
 * is derived from those allocations — see {@see Settlement}.
 *
 * Nothing here rewrites an invoice's own figures. The only field a payment
 * touches on an existing document is its paid flag, and only to keep it in
 * step with the money actually applied.
 */
class PaymentController extends Controller
{
    /** Documents this party can still have money applied to. */
    public function openDocuments(Request $request, string $type, int $id)
    {
        abort_unless(in_array($type, ['customer', 'vendor'], true), 404);

        $party = $type === 'customer' ? Customer::find($id) : Vendor::find($id);
        abort_unless($party, 404);

        $docs = $type === 'customer'
            ? CustomerInvoice::issued()->with('rfq:id,reference,ship_name')
                ->where('customer_id', $id)
                ->orderBy('due_date')->orderBy('id')->get()
            : PurchaseOrder::live()->with('rfq:id,reference,ship_name')
                ->where('vendor_id', $id)
                ->orderBy('issued_date')->orderBy('id')->get();

        $rows = $docs->map(function ($d) use ($type) {
            $s = Settlement::of($d);

            return [
                'id' => $d->id,
                'kind' => $type === 'customer' ? 'invoice' : 'po',
                'number' => $type === 'customer' ? $d->invoice_number : $d->po_number,
                'reference' => $d->rfq?->reference,
                'vessel' => $d->rfq?->ship_name ?: ($d->ship_name ?? null),
                'date' => optional($type === 'customer' ? $d->issue_date : ($d->issued_date ?: $d->created_at))->toDateString(),
                'due_date' => optional($type === 'customer' ? $d->due_date : $d->expected_date)->toDateString(),
                'currency' => $d->currency,
                'billed' => $s['billed'],
                'credited' => $s['credited'],
                'allocated' => $s['allocated'],
                'outstanding' => $s['outstanding'],
                'paid' => $s['paid'],
            ];
        })->filter(fn ($r) => $r['outstanding'] > Settlement::EPSILON)->values();

        return response()->json(['success' => true, 'data' => [
            'party' => [
                'id' => $party->id,
                'name' => $party->name,
                'type' => $type,
                // What this party normally trades in. The payment screen falls
                // back to it when nothing is outstanding, so there is still a
                // sensible currency to record against.
                'currency' => $party->currency,
            ],
            'documents' => $rows,
        ]]);
    }

    /** Every payment recorded for one party, newest first. */
    public function index(Request $request)
    {
        $data = $request->validate([
            'type' => ['required', 'in:customer,vendor'],
            'party_id' => ['required', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $payments = Payment::with(['allocations.invoice:id,invoice_number', 'allocations.purchaseOrder:id,po_number', 'attachments'])
            ->where('party_type', $data['type'])
            ->where($data['type'] === 'customer' ? 'customer_id' : 'vendor_id', $data['party_id'])
            ->when($data['from'] ?? null, fn ($q, $v) => $q->whereDate('payment_date', '>=', $v))
            ->when($data['to'] ?? null, fn ($q, $v) => $q->whereDate('payment_date', '<=', $v))
            ->orderByDesc('payment_date')->orderByDesc('id')
            ->get();

        return response()->json(['success' => true, 'data' => $payments->map(fn ($p) => $this->present($p))]);
    }

    public function store(Request $request)
    {
        $data = $this->validatePayload($request);

        $payment = DB::transaction(function () use ($data, $request) {
            $isCustomer = $data['party_type'] === 'customer';
            $party = $isCustomer ? Customer::findOrFail($data['party_id']) : Vendor::findOrFail($data['party_id']);

            $payment = Payment::create([
                'payment_number' => DocNumber::next($isCustomer ? 'RCPT' : 'PMT'),
                'direction' => $isCustomer ? 'in' : 'out',
                'party_type' => $data['party_type'],
                'customer_id' => $isCustomer ? $party->id : null,
                'vendor_id' => $isCustomer ? null : $party->id,
                'party_name' => $party->name,
                'payment_date' => $data['payment_date'],
                'currency' => strtoupper($data['currency']),
                'amount' => round((float) $data['amount'], 2),
                'method' => $data['method'] ?? null,
                'reference' => $data['reference'] ?? null,
                'bank_account' => $data['bank_account'] ?? null,
                'account_code' => $data['account_code'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()?->id,
            ]);

            $this->applyAllocations($payment, $data['allocations'] ?? []);

            return $payment;
        });

        return response()->json([
            'success' => true,
            'message' => 'Payment '.$payment->payment_number.' recorded.',
            'data' => $this->present($payment->fresh(['allocations.invoice', 'allocations.purchaseOrder'])),
        ], 201);
    }

    public function update(Request $request, Payment $payment)
    {
        $data = $this->validatePayload($request, $payment);

        DB::transaction(function () use ($data, $payment) {
            $payment->update([
                'payment_date' => $data['payment_date'],
                'currency' => strtoupper($data['currency']),
                'amount' => round((float) $data['amount'], 2),
                'method' => $data['method'] ?? null,
                'reference' => $data['reference'] ?? null,
                'bank_account' => $data['bank_account'] ?? null,
                'account_code' => $data['account_code'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            // Documents this payment used to touch must be re-derived too,
            // otherwise one dropped from the list would stay marked paid.
            $touched = $this->documentsOf($payment);
            $payment->allocations()->delete();
            $this->applyAllocations($payment, $data['allocations'] ?? []);

            foreach ($touched as $doc) {
                Settlement::sync($doc->fresh(), force: true);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Payment updated.',
            'data' => $this->present($payment->fresh(['allocations.invoice', 'allocations.purchaseOrder'])),
        ]);
    }

    public function destroy(Payment $payment)
    {
        // Collected before the row goes, so the files can be removed from R2
        // after the database work has definitely succeeded.
        $files = $payment->attachments()->get(['disk', 'path']);

        DB::transaction(function () use ($payment) {
            $touched = $this->documentsOf($payment);

            $payment->allocations()->delete();
            $payment->delete();

            // force: the balance this payment created has to be reversed even
            // though nothing is allocated to these documents any more.
            foreach ($touched as $doc) {
                Settlement::sync($doc->fresh(), force: true);
            }
        });

        foreach ($files as $f) {
            Storage::disk($f->disk)->delete($f->path);
        }

        return response()->json(['success' => true, 'message' => 'Payment deleted and the balances put back.']);
    }

    /* ---------------------- bank slips & invoices --------------------- */

    /** File a bank slip or invoice copy against a payment. */
    public function uploadAttachments(Request $request, Payment $payment)
    {
        $request->validate([
            'files' => ['required', 'array', 'max:10'],
            'files.*' => ['file', 'max:10240', 'mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png,webp'],
            'kind' => ['nullable', Rule::in(['bank_slip', 'invoice', 'other'])],
        ]);

        foreach ($request->file('files') as $file) {
            $path = $file->store('payments/'.$payment->id, 'r2');
            $payment->attachments()->create([
                'disk' => 'r2',
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getClientMimeType(),
                'size' => $file->getSize(),
                'kind' => $request->input('kind', 'bank_slip'),
                'uploaded_by' => $request->user()?->id,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'File(s) attached to '.$payment->payment_number.'.',
            'data' => $this->attachmentList($payment),
        ]);
    }

    public function deleteAttachment(Payment $payment, PaymentAttachment $attachment)
    {
        abort_unless($attachment->payment_id === $payment->id, 404);

        Storage::disk($attachment->disk)->delete($attachment->path);
        $attachment->delete();

        return response()->json([
            'success' => true,
            'message' => 'File removed.',
            'data' => $this->attachmentList($payment->fresh()),
        ]);
    }

    /** Short-lived signed URL — the bucket is private. */
    public function attachmentUrl(Payment $payment, PaymentAttachment $attachment)
    {
        abort_unless($attachment->payment_id === $payment->id, 404);

        return response()->json(['success' => true, 'data' => [
            'url' => Storage::disk($attachment->disk)->temporaryUrl($attachment->path, now()->addMinutes(10)),
            'name' => $attachment->original_name,
        ]]);
    }

    private function attachmentList(Payment $payment)
    {
        return $payment->attachments()->get(['id', 'payment_id', 'original_name', 'mime_type', 'size', 'kind', 'created_at']);
    }

    /* ------------------------------------------------------------------ */

    private function validatePayload(Request $request, ?Payment $existing = null): array
    {
        return $request->validate([
            'party_type' => [$existing ? 'nullable' : 'required', 'in:customer,vendor'],
            'party_id' => [$existing ? 'nullable' : 'required', 'integer'],
            'payment_date' => ['required', 'date'],
            'currency' => ['required', 'string', 'size:3'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['nullable', Rule::in(['bank transfer', 'cheque', 'cash', 'card', 'offset', 'other'])],
            'reference' => ['nullable', 'string', 'max:190'],
            'bank_account' => ['nullable', 'string', 'max:190'],
            'account_code' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'allocations' => ['nullable', 'array', 'max:200'],
            'allocations.*.document_id' => ['required', 'integer'],
            'allocations.*.amount' => ['required', 'numeric', 'min:0.01'],
        ]) + ($existing ? ['party_type' => $existing->party_type, 'party_id' => $existing->customer_id ?: $existing->vendor_id] : []);
    }

    /**
     * Write the allocation rows, refusing anything that would apply more money
     * than exists — either more than the payment, or more than the document
     * still owes. Rows are locked so two accountants posting at the same
     * moment cannot both consume the same remaining balance.
     */
    private function applyAllocations(Payment $payment, array $rows): void
    {
        if (! $rows) {
            return;
        }

        $isCustomer = $payment->party_type === 'customer';
        $total = 0.0;
        $seen = [];

        foreach ($rows as $row) {
            $id = (int) $row['document_id'];
            $amount = round((float) $row['amount'], 2);

            if (isset($seen[$id])) {
                abort(422, 'The same document is listed twice — combine it into one line.');
            }
            $seen[$id] = true;
            $total += $amount;

            $doc = $isCustomer
                ? CustomerInvoice::where('id', $id)->lockForUpdate()->first()
                : PurchaseOrder::where('id', $id)->lockForUpdate()->first();

            abort_unless($doc, 422, 'One of the selected documents no longer exists.');

            $belongs = $isCustomer
                ? (int) $doc->customer_id === (int) $payment->customer_id
                : (int) $doc->vendor_id === (int) $payment->vendor_id;
            abort_unless($belongs, 422, 'A selected document does not belong to this '.$payment->party_type.'.');

            if (strtoupper((string) $doc->currency) !== strtoupper((string) $payment->currency)) {
                abort(422, sprintf(
                    '%s is in %s but this payment is in %s. Record the payment in the document\'s currency, or settle it separately.',
                    $isCustomer ? $doc->invoice_number : $doc->po_number,
                    $doc->currency,
                    $payment->currency
                ));
            }

            $room = Settlement::room($doc);
            if ($amount > $room + Settlement::EPSILON) {
                abort(422, sprintf(
                    '%s only has %s %s outstanding — you tried to apply %s.',
                    $isCustomer ? $doc->invoice_number : $doc->po_number,
                    $doc->currency,
                    number_format($room, 2),
                    number_format($amount, 2)
                ));
            }

            $payment->allocations()->create([
                'customer_invoice_id' => $isCustomer ? $doc->id : null,
                'purchase_order_id' => $isCustomer ? null : $doc->id,
                'amount' => $amount,
            ]);

            Settlement::sync($doc->fresh());
        }

        if (round($total, 2) > round((float) $payment->amount, 2) + Settlement::EPSILON) {
            abort(422, sprintf(
                'You applied %s but the payment is only %s. The difference cannot be matched to anything.',
                number_format($total, 2),
                number_format((float) $payment->amount, 2)
            ));
        }
    }

    /** The documents a payment currently touches, for re-deriving after a change. */
    private function documentsOf(Payment $payment)
    {
        $allocations = $payment->allocations()->get();

        $invoices = CustomerInvoice::whereIn('id', $allocations->pluck('customer_invoice_id')->filter())->get();
        $orders = PurchaseOrder::whereIn('id', $allocations->pluck('purchase_order_id')->filter())->get();

        return $invoices->concat($orders);
    }

    private function present(Payment $p): array
    {
        return [
            'id' => $p->id,
            'number' => $p->payment_number,
            'direction' => $p->direction,
            'party_type' => $p->party_type,
            'party_name' => $p->party_name,
            'date' => optional($p->payment_date)->toDateString(),
            'currency' => $p->currency,
            'amount' => round((float) $p->amount, 2),
            'allocated' => $p->allocatedAmount(),
            'unapplied' => $p->unappliedAmount(),
            'method' => $p->method,
            'reference' => $p->reference,
            'bank_account' => $p->bank_account,
            'account_code' => $p->account_code,
            'notes' => $p->notes,
            'allocations' => $p->allocations->map(fn ($a) => [
                'id' => $a->id,
                'document_id' => $a->customer_invoice_id ?: $a->purchase_order_id,
                'number' => $a->invoice?->invoice_number ?: $a->purchaseOrder?->po_number,
                'amount' => round((float) $a->amount, 2),
            ])->values(),
            // Read as a property so an eager-loaded list is reused rather than
            // re-queried once per payment on the index endpoint.
            'attachments' => $p->attachments->map(fn ($f) => [
                'id' => $f->id,
                'payment_id' => $f->payment_id,
                'original_name' => $f->original_name,
                'mime_type' => $f->mime_type,
                'size' => $f->size,
                'kind' => $f->kind,
                'created_at' => $f->created_at,
            ])->values(),
        ];
    }
}
