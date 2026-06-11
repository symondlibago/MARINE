<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Document;
use App\Models\Vendor;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DocumentController extends Controller
{
    private const TYPES = ['invoice', 'quotation', 'enquiry', 'delivery_note'];

    private const LABEL = [
        'invoice' => 'Invoice',
        'quotation' => 'Quotation',
        'enquiry' => 'Enquiry',
        'delivery_note' => 'Delivery Note',
    ];

    private const PREFIX = [
        'invoice' => 'INV',
        'quotation' => 'QTN',
        'enquiry' => 'ENQ',
        'delivery_note' => 'DN',
    ];

    public function index(Request $request)
    {
        $query = Document::query()->orderByDesc('id');

        if (($type = $request->query('type')) && in_array($type, self::TYPES, true)) {
            $query->where('type', $type);
        }
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('number', 'like', "%{$search}%")
                    ->orWhere('party_name', 'like', "%{$search}%")
                    ->orWhere('order_number', 'like', "%{$search}%");
            });
        }

        $rows = $query->get()->map(fn (Document $d) => [
            'id' => $d->id,
            'type' => $d->type,
            'type_label' => self::LABEL[$d->type] ?? ucfirst($d->type),
            'number' => $d->number,
            'party_name' => $d->party_name,
            'date' => $d->date?->toDateString(),
            'currency' => $d->currency,
            'total' => (float) $d->total,
        ]);

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function show(Document $document)
    {
        return response()->json(['success' => true, 'data' => $document->load('items')]);
    }

    /** Suggested next number for a type (so the form can pre-fill it). */
    public function nextNumber(Request $request)
    {
        $type = $request->query('type', 'invoice');
        $type = in_array($type, self::TYPES, true) ? $type : 'invoice';

        return response()->json(['success' => true, 'data' => ['number' => $this->buildNumber($type, now())]]);
    }

    public function store(Request $request)
    {
        $data = $this->validateDoc($request);

        $document = DB::transaction(function () use ($request, $data) {
            $doc = Document::create([
                'type' => $data['type'],
                'number' => $data['number'] ?: $this->buildNumber($data['type'], $data['date'] ?? now()),
                'party_kind' => $data['party_kind'] ?? null,
                'customer_id' => $data['customer_id'] ?? null,
                'vendor_id' => $data['vendor_id'] ?? null,
                'party_name' => $data['party_name'] ?? null,
                'party_address' => $data['party_address'] ?? null,
                'party_email' => $data['party_email'] ?? null,
                'date' => $data['date'] ?? now()->toDateString(),
                'currency' => strtoupper($data['currency']),
                'order_number' => $data['order_number'] ?? null,
                'terms' => $data['terms'] ?? null,
                'gst_rate' => $data['gst_rate'] ?? 0,
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()?->id,
            ]);

            $this->syncItems($doc, $data['items']);
            $doc->recalcTotals();

            return $doc;
        });

        return response()->json([
            'success' => true,
            'message' => self::LABEL[$document->type].' created.',
            'data' => $document->load('items'),
        ], 201);
    }

    public function update(Request $request, Document $document)
    {
        $data = $this->validateDoc($request);

        DB::transaction(function () use ($document, $data) {
            $document->update([
                'type' => $data['type'],
                'number' => $data['number'] ?: $document->number,
                'party_kind' => $data['party_kind'] ?? null,
                'customer_id' => $data['customer_id'] ?? null,
                'vendor_id' => $data['vendor_id'] ?? null,
                'party_name' => $data['party_name'] ?? null,
                'party_address' => $data['party_address'] ?? null,
                'party_email' => $data['party_email'] ?? null,
                'date' => $data['date'] ?? $document->date,
                'currency' => strtoupper($data['currency']),
                'order_number' => $data['order_number'] ?? null,
                'terms' => $data['terms'] ?? null,
                'gst_rate' => $data['gst_rate'] ?? 0,
                'notes' => $data['notes'] ?? null,
            ]);

            $document->items()->delete();
            $this->syncItems($document, $data['items']);
            $document->recalcTotals();
        });

        return response()->json([
            'success' => true,
            'message' => 'Document updated.',
            'data' => $document->fresh()->load('items'),
        ]);
    }

    public function destroy(Document $document)
    {
        $document->delete();

        return response()->json(['success' => true, 'message' => 'Document deleted.']);
    }

    public function pdf(Document $document)
    {
        $document->load('items');

        $logoPath = public_path('logo.png');
        $logo = is_file($logoPath) ? 'data:image/png;base64,'.base64_encode(file_get_contents($logoPath)) : null;

        $pdf = Pdf::loadView('pdf.document', [
            'doc' => $document,
            'company' => config('procurement.company'),
            'logo' => $logo,
            'title' => strtoupper(self::LABEL[$document->type] ?? $document->type),
            'partyLabel' => $this->partyLabel($document->type),
            'numberLabel' => strtoupper(self::LABEL[$document->type] ?? 'DOCUMENT').' NO.',
        ]);

        $name = ($document->number ?: 'document').'.pdf';

        return $pdf->download($name);
    }

    /* ----------------------------- helpers ----------------------------- */

    private function partyLabel(string $type): string
    {
        return match ($type) {
            'invoice' => 'BILL TO',
            'delivery_note' => 'DELIVER TO',
            default => 'TO',
        };
    }

    private function buildNumber(string $type, $date): string
    {
        $prefix = self::PREFIX[$type] ?? 'DOC';
        $yy = Carbon::parse($date)->format('y');
        $base = "MMS-{$prefix}-{$yy}-";

        $last = Document::where('type', $type)->where('number', 'like', $base.'%')->orderByDesc('id')->value('number');
        $seq = 1;
        if ($last && preg_match('/(\d+)$/', $last, $m)) {
            $seq = (int) $m[1] + 1;
        }

        return $base.str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
    }

    private function syncItems(Document $doc, array $items): void
    {
        foreach (array_values($items) as $i => $row) {
            $isHeading = (bool) ($row['is_heading'] ?? false);
            $qty = $isHeading ? null : ($row['qty'] ?? 0);
            $price = $isHeading ? null : ($row['unit_price'] ?? 0);

            $doc->items()->create([
                'is_heading' => $isHeading,
                'description' => $row['description'],
                'unit' => $isHeading ? null : ($row['unit'] ?? null),
                'qty' => $qty,
                'unit_price' => $price,
                'amount' => $isHeading ? 0 : round((float) $qty * (float) $price, 2),
                'sort' => $i,
            ]);
        }
    }

    private function validateDoc(Request $request): array
    {
        return $request->validate([
            'type' => ['required', 'in:invoice,quotation,enquiry,delivery_note'],
            'number' => ['nullable', 'string', 'max:50'],
            'party_kind' => ['nullable', 'in:customer,vendor'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'vendor_id' => ['nullable', 'integer', 'exists:vendors,id'],
            'party_name' => ['nullable', 'string', 'max:255'],
            'party_address' => ['nullable', 'string', 'max:2000'],
            'party_email' => ['nullable', 'string', 'max:255'],
            'date' => ['nullable', 'date'],
            'currency' => ['required', 'string', 'size:3'],
            'order_number' => ['nullable', 'string', 'max:255'],
            'terms' => ['nullable', 'string', 'max:255'],
            'gst_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.is_heading' => ['nullable', 'boolean'],
            'items.*.description' => ['required', 'string', 'max:2000'],
            'items.*.unit' => ['nullable', 'string', 'max:50'],
            'items.*.qty' => ['nullable', 'numeric'],
            'items.*.unit_price' => ['nullable', 'numeric'],
        ]);
    }
}
