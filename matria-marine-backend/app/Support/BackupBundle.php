<?php

namespace App\Support;

use App\Http\Controllers\CreditMemoController;
use App\Http\Controllers\CustomerInvoiceController;
use App\Http\Controllers\OfferController;
use App\Http\Controllers\Payroll\PdfController as PayrollPdfController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\RfqPdfController;
use App\Models\CustomerInvoice;
use App\Models\Offer;
use App\Models\Payroll\Run;
use App\Models\PurchaseOrder;
use App\Models\Rfq;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Everything that belongs in one document's folder in the local backup.
 *
 * The one place this is defined. The browser mirror (File System Access API)
 * and the ZIP fallback both read the same bundle, so the two can never drift
 * into writing different folders for the same document.
 *
 * STRICTLY READ-ONLY. Backing something up must never change it: no numbers
 * are minted, no statuses move, nothing is saved. Only the side-effect-free
 * pdf() actions are reused — never OfferController::proforma(), which issues a
 * ProINV number the first time it runs.
 *
 * Each folder holds
 *
 *   MMS-QTN-2026-00023.pdf     the document itself, as staff would send it
 *   data.json                  the whole record — what makes this restorable
 *   line-items.csv             the lines, for opening in Excel
 *   summary.txt                one page of plain text, so the folder reads itself
 *   attachments/               the source files, from R2
 *
 * and sits under a year, because a flat folder of five hundred enquiries is
 * not something anyone can work with:
 *
 *   ENQUIRIES/2026/MMS-QTN-2026-00023 Seven Seas Shipping/
 */
class BackupBundle
{
    /** type => top-level folder on disk. */
    public const FOLDERS = [
        'enquiry' => 'ENQUIRIES',
        'offer' => 'OFFERS',
        'invoice' => 'INVOICES',
        'purchase_order' => 'PURCHASE ORDERS',
        'payroll_run' => 'PAYROLL',
        'payroll_employees' => 'PAYROLL',
    ];

    /**
     * type => the portal page someone needs to back that document up.
     *
     * Mirroring a document exposes nothing its page does not already show, so
     * the right to back it up is the right to read it — not a separate one.
     * Both payroll types sit behind Payroll for that reason: the folder they
     * write holds every salary in the company.
     */
    public const PAGES = [
        'enquiry' => 'enquiries',
        'offer' => 'offers',
        'invoice' => 'invoices',
        'purchase_order' => 'purchase_orders',
        'payroll_run' => 'payroll',
        'payroll_employees' => 'payroll',
    ];

    /**
     * Types that are not filed under a year.
     *
     * The staff list is not a document from a particular month — it is the
     * list as it stands, so it lives in one place and is written over.
     */
    private const NOT_BY_YEAR = ['payroll_employees'];

    /** Longest a party name may run inside a folder name (Windows path limit). */
    private const PARTY_MAX = 40;

    /** Longest an attachment's own filename may run. */
    private const FILE_MAX = 80;

    /**
     * @param  object  $model  an Eloquent document, or {@see BackupRoster} for
     *                         the staff list, which has no row of its own
     */
    private function __construct(
        public readonly string $type,
        public readonly object $model,
    ) {}

    public static function knows(string $type): bool
    {
        return isset(self::FOLDERS[$type]);
    }

    /** @return list<string> */
    public static function types(): array
    {
        return array_keys(self::FOLDERS);
    }

    /** The page key a user must hold for this type, for the middleware's benefit. */
    public static function pageFor(string $type): ?string
    {
        return self::PAGES[$type] ?? null;
    }

    /**
     * Wrap a document that is already in hand.
     *
     * For the pending list, which names hundreds of documents at once and must
     * not load every line item and attachment to do it. Only the naming side
     * (number, party, date, folder) is safe to use on a bundle made this way —
     * the contents need the relations {@see make()} loads.
     */
    public static function of(string $type, object $model): self
    {
        abort_unless(self::knows($type), 404, "Unknown backup type [{$type}].");

        return new self($type, $model);
    }

    /**
     * Load one document with everything its folder needs.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public static function make(string $type, int|string $id): self
    {
        $model = match ($type) {
            'enquiry' => Rfq::with([
                'customer', 'items.attachments', 'attachments',
                'quotes.vendor', 'quotes.attachments', 'quotes.items',
                'offer:id,rfq_id,offer_number,grand_total,currency,status',
            ])->findOrFail($id),
            'offer' => Offer::with([
                'items', 'customer', 'rfq:id,reference,ship_name,customer_reference,delivery_port',
            ])->findOrFail($id),
            'invoice' => CustomerInvoice::with([
                'items', 'customer', 'rfq:id,reference,ship_name',
                'creditMemos.items',
            ])->findOrFail($id),
            'purchase_order' => PurchaseOrder::with([
                'items', 'vendor', 'attachments', 'returnNote.items',
                'rfq:id,reference,ship_name',
            ])->findOrFail($id),
            'payroll_run' => Run::with('lines')->findOrFail($id),
            // One list, so the id is ignored — see BackupRoster.
            'payroll_employees' => BackupRoster::load(),
            default => throw new \InvalidArgumentException("Unknown backup type [{$type}]."),
        };

        return new self($type, $model);
    }

    // ---------------------------------------------------------------- naming

    /** The document's own number, or a stable fallback if it has none yet. */
    public function number(): string
    {
        $m = $this->model;

        return (string) (match ($this->type) {
            'enquiry' => $m->reference,
            'offer' => $m->offer_number,
            'invoice' => $m->invoice_number,
            'purchase_order' => $m->po_number,
            // A payroll month is known by its month, not by a number.
            'payroll_run' => $m->period?->format('Y-m'),
            'payroll_employees' => 'EMPLOYEES',
        } ?: strtoupper($this->type).'-'.$m->id);
    }

    /** Who the document is with — the customer, or the vendor on a purchase order. */
    public function party(): string
    {
        $m = $this->model;

        return (string) (match ($this->type) {
            'enquiry' => $m->customer?->name,
            'offer' => $m->customer?->name ?: $m->customer_name,
            'invoice' => $m->customer?->name ?: $m->customer_name,
            'purchase_order' => $m->vendor?->name,
            'payroll_run' => $m->period?->format('F'),
            'payroll_employees' => 'Staff list',
        } ?: 'Unknown');
    }

    /** The date the folder is filed under. */
    public function date(): Carbon
    {
        $m = $this->model;

        // An offer carries no date of its own — it is filed by when it was
        // raised, like everything else that has no issue date set yet.
        $own = match ($this->type) {
            'enquiry' => $m->received_date,
            'offer' => null,
            'invoice' => $m->issue_date,
            'purchase_order' => $m->issued_date,
            // The month it pays, not the day it was keyed in.
            'payroll_run' => $m->period,
            'payroll_employees' => $m->updated_at,
        };

        return Carbon::parse($own ?? $m->created_at ?? now());
    }

    /**
     * The enquiry a document came out of, when there is one.
     *
     * Named in brackets on purchase orders and invoices so the job behind them
     * is visible from the folder list. Offers reuse the enquiry's own MMS-QTN
     * number (see OfferController::generate), so repeating it there would only
     * print it twice.
     */
    public function enquiryReference(): ?string
    {
        if (in_array($this->type, ['enquiry', 'offer', 'payroll_run', 'payroll_employees'], true)) {
            return null;
        }

        $reference = $this->model->rfq?->reference;

        return $reference && $reference !== $this->number() ? $reference : null;
    }

    /** Where this document's folder sits, relative to the backup root. */
    public function folder(): string
    {
        // The staff list is the list as it stands, not a document from a given
        // month, so it sits in one place and is written over.
        if (in_array($this->type, self::NOT_BY_YEAR, true)) {
            return self::FOLDERS[$this->type].'/'.self::safeName($this->number());
        }

        $parts = array_filter([
            $this->number(),
            $this->enquiryReference() ? '('.$this->enquiryReference().')' : null,
            Str::limit($this->party(), self::PARTY_MAX, ''),
        ]);

        return sprintf(
            '%s/%s/%s',
            self::FOLDERS[$this->type],
            $this->date()->format('Y'),
            self::safeName(implode(' ', $parts)),
        );
    }

    /**
     * A name Windows will accept as a file or folder.
     *
     * Customer names carry "/" and "&" and the odd ":", any of which makes a
     * path Windows refuses outright. Reserved device names (CON, PRN, LPT1 and
     * friends) are illegal as whole segments however they are spelled, and a
     * segment may not end in a dot or a space.
     */
    public static function safeName(string $name, int $limit = 120): string
    {
        $clean = preg_replace('#[<>:"/\\\\|?*]+#', '-', $name);
        $clean = preg_replace('/[\x00-\x1F\x7F]+/', '', (string) $clean);
        $clean = trim(preg_replace('/\s+/', ' ', (string) $clean));
        $clean = rtrim($clean, ". \t");

        if (preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])$/i', $clean)) {
            $clean = '_'.$clean;
        }

        return mb_substr($clean, 0, $limit) ?: 'unnamed';
    }

    // ----------------------------------------------------------- the content

    /**
     * The small text files, written straight from the manifest.
     *
     * They are a few kilobytes at most, so they travel inline rather than
     * costing three more round trips per document.
     *
     * @return array<string, string> filename => contents
     */
    public function inlineFiles(): array
    {
        return [
            'data.json' => json_encode(
                $this->data(),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ),
            'line-items.csv' => $this->csv(),
            'summary.txt' => $this->summary(),
        ];
    }

    /**
     * The files that have to be fetched one at a time: generated PDFs and
     * whatever is filed on R2.
     *
     * The order is the index the frontend asks for, so it must stay stable
     * within a single manifest — which it does, being rebuilt from the same
     * query every time.
     *
     * @return list<array<string, mixed>>
     */
    public function binaries(): array
    {
        $files = [];
        $m = $this->model;

        // The staff list is a list — there is no document to print.
        if ($this->type === 'payroll_employees') {
            return [];
        }

        if ($this->type === 'payroll_run') {
            $files[] = $this->pdf(
                'payroll-summary-'.$this->number().'.pdf',
                [PayrollPdfController::class, 'summary'],
            );

            // One payslip per person, which is the document anyone actually
            // comes looking for years later.
            foreach ($m->lines as $line) {
                $name = self::safeName(trim(($line->code ?: 'EMP').' '.($line->full_name ?: '')), self::PARTY_MAX);

                $files[] = $this->pdf(
                    "payslips/{$name}.pdf",
                    [PayrollPdfController::class, 'payslip'],
                    [$m, $line],
                );
            }

            return array_values(array_filter($files));
        }

        // 1. The document itself.
        $files[] = $this->pdf($this->number().'.pdf', match ($this->type) {
            'enquiry' => [RfqPdfController::class, 'summary'],
            'offer' => [OfferController::class, 'pdf'],
            'invoice' => [CustomerInvoiceController::class, 'pdf'],
            'purchase_order' => [PurchaseOrderController::class, 'pdf'],
        });

        if ($this->type === 'enquiry') {
            // What each vendor was asked, and what they came back with — the
            // compare-and-award paper trail.
            foreach ($m->quotes as $quote) {
                $vendor = self::safeName($quote->vendor?->name ?: 'vendor-'.$quote->id, self::PARTY_MAX);

                $files[] = $this->pdf(
                    "vendor-quotes/{$vendor} - quote.pdf",
                    [RfqPdfController::class, 'vendorQuote'],
                    [$m, $quote->vendor],
                    $quote->vendor !== null,
                );

                foreach ($quote->attachments as $attachment) {
                    $files[] = $this->disk($attachment, "vendor-quotes/{$vendor}");
                }
            }

            foreach ($m->attachments as $attachment) {
                $files[] = $this->disk($attachment, 'attachments');
            }

            foreach ($m->items as $item) {
                foreach ($item->attachments as $attachment) {
                    $files[] = $this->disk($attachment, 'line-attachments');
                }
            }
        }

        if ($this->type === 'purchase_order') {
            // The vendor settlement document — order less returns.
            $files[] = $this->pdf(
                'final-invoice-'.$this->number().'.pdf',
                [PurchaseOrderController::class, 'finalInvoice'],
            );

            foreach ($m->attachments as $attachment) {
                $files[] = $this->disk($attachment, 'attachments');
            }
        }

        if ($this->type === 'invoice') {
            // A credited invoice is not the whole story without the credit
            // notes that changed what the customer owes.
            foreach ($m->creditMemos as $memo) {
                $files[] = $this->pdf(
                    'credit-notes/'.self::safeName($memo->cm_number ?: 'CM-'.$memo->id).'.pdf',
                    [CreditMemoController::class, 'pdf'],
                    [$memo],
                );
            }
        }

        return array_values(array_filter($files));
    }

    /** A generated PDF, rendered on demand. */
    private function pdf(string $name, array $action, ?array $args = null, bool $include = true): ?array
    {
        if (! $include) {
            return null;
        }

        return [
            'name' => self::safePath($name),
            'kind' => 'pdf',
            'bytes' => null,          // not known until it is rendered
            'action' => $action,
            'args' => $args ?? [$this->model],
        ];
    }

    /** A file already sitting on R2. */
    private function disk(Model $attachment, string $prefix): array
    {
        return [
            'name' => self::safePath($prefix.'/'.($attachment->original_name ?: basename($attachment->path))),
            'kind' => 'disk',
            'bytes' => $attachment->size,
            'mime' => $attachment->mime_type,
            'disk' => $attachment->disk,
            'path' => $attachment->path,
        ];
    }

    /** Sanitise each segment of a relative path, keeping the slashes. */
    private static function safePath(string $path): string
    {
        $segments = array_filter(explode('/', $path), fn ($s) => $s !== '');
        $last = array_key_last($segments);

        return implode('/', array_map(
            fn ($segment, $i) => self::safeName($segment, $i === $last ? self::FILE_MAX : self::PARTY_MAX + 20),
            $segments,
            array_keys($segments),
        ));
    }

    /**
     * Render or read one binary by its index in {@see binaries()}.
     *
     * @return array{0: string, 1: string, 2: string} [bytes, mime, filename]
     */
    public function readBinary(int $index): array
    {
        $files = $this->binaries();

        if (! isset($files[$index])) {
            abort(404, 'No such file in this backup bundle.');
        }

        $file = $files[$index];
        $name = basename($file['name']);

        if ($file['kind'] === 'pdf') {
            [$class, $method] = $file['action'];

            return [app($class)->{$method}(...$file['args'])->getContent(), 'application/pdf', $name];
        }

        $disk = Storage::disk($file['disk']);

        if (! $disk->exists($file['path'])) {
            abort(410, 'That file is no longer in storage.');
        }

        return [$disk->get($file['path']), $file['mime'] ?: 'application/octet-stream', $name];
    }

    // -------------------------------------------------------------- the data

    /**
     * The whole record, as JSON.
     *
     * This is what turns the folder from readable into restorable — a PDF can
     * be opened by anyone in ten years, but only this can be loaded back in.
     *
     * Magic-link tokens are stripped: they let anyone holding one accept an
     * offer or view a purchase order without logging in, and a backup folder
     * is a thing people copy onto sticks and email to each other.
     */
    public function data(): array
    {
        return [
            'matria_backup' => [
                'version' => 1,
                'type' => $this->type,
                'number' => $this->number(),
                'exported_at' => now()->toIso8601String(),
                'folder' => $this->folder(),
            ],
            'record' => self::scrub($this->model->toArray()),
        ];
    }

    /** Drop magic-link tokens, however deep they sit. */
    private static function scrub(array $row): array
    {
        unset($row['token']);

        foreach ($row as $key => $value) {
            if (is_array($value)) {
                $row[$key] = self::scrub($value);
            }
        }

        return $row;
    }

    /** The line items as CSV, so they open in Excel without anyone's help. */
    public function csv(): string
    {
        [$headers, $rows] = $this->lines();

        $out = fopen('php://temp', 'r+');
        fputcsv($out, $headers);
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        // Excel on Windows reads a BOM-less UTF-8 CSV as the local codepage,
        // which mangles any vessel or vendor name with an accent in it.
        return "\xEF\xBB\xBF".$csv;
    }

    /**
     * The line items, per type — each one has its own columns.
     *
     * An enquiry line carries no price at all (that is what the vendors are
     * for), a purchase order line costs rather than charges, and the two
     * customer documents charge. The account code goes in wherever the line
     * holds one, because that is the link back to what Accounting reports.
     *
     * @return array{0: list<string>, 1: list<list<mixed>>}
     */
    private function lines(): array
    {
        $m = $this->model;

        if ($this->type === 'enquiry') {
            return [
                ['#', 'Description', 'IMPA / part no', 'Qty', 'Unit', 'Account code'],
                $m->items->values()->map(fn ($i, $n) => [
                    $n + 1, $i->description, $i->impa_no, $i->qty, $i->unit, $i->accounting_code,
                ])->all(),
            ];
        }

        if ($this->type === 'purchase_order') {
            return [
                ['#', 'Description', 'IMPA / part no', 'Qty', 'Unit', 'Unit cost', 'Line total', 'Currency', 'Account code', 'Remarks'],
                $m->items->values()->map(fn ($i, $n) => [
                    $n + 1, $i->description, $i->impa_no, $i->qty, $i->unit,
                    $i->unit_cost, $i->line_total, $m->currency, $i->account_code, $i->remarks,
                ])->all(),
            ];
        }

        if ($this->type === 'payroll_employees') {
            return [
                ['Code', 'Name', 'Job title', 'Status', 'CPF scheme', 'SHG fund', 'Basic salary', 'Fixed allowance', 'Started', 'Ended'],
                $m->employees->values()->map(fn ($e) => [
                    $e->code, $e->full_name, $e->job_title, $e->status,
                    $e->cpf_scheme, $e->shg_fund, $e->basic_salary, $e->fixed_allowance,
                    $e->start_date?->format('Y-m-d'), $e->end_date?->format('Y-m-d'),
                ])->all(),
            ];
        }

        if ($this->type === 'payroll_run') {
            return [
                ['Code', 'Name', 'Basic pay', 'Allowances', 'OT', 'Bonus', 'Gross', 'Employee CPF', 'SHG', 'Deductions', 'Net pay', 'Employer CPF', 'SDL', 'Cost to business'],
                $m->lines->values()->map(fn ($l) => [
                    $l->code, $l->full_name, $l->basic_pay,
                    (float) $l->fixed_allowance + (float) $l->other_allowance,
                    $l->ot_pay, $l->bonus_aw, $l->gross_earnings,
                    $l->employee_cpf, $l->shg_deduction, $l->total_deductions, $l->net_salary,
                    $l->employer_cpf, $l->sdl, $l->total_employer_cost,
                ])->all(),
            ];
        }

        if ($this->type === 'offer') {
            return [
                ['#', 'Description', 'Code', 'Qty', 'Unit', 'Unit price', 'Discount %', 'Discount', 'Line total', 'Currency'],
                $m->items->where('is_heading', false)->values()->map(fn ($i, $n) => [
                    $n + 1, $i->description, $i->code, $i->qty, $i->unit,
                    $i->unit_price, $i->cust_discount_pct, $i->cust_discount_amount,
                    $i->line_total, $m->currency,
                ])->all(),
            ];
        }

        return [
            ['#', 'Description', 'Code', 'Qty', 'Unit', 'Unit price', 'Line total', 'Currency', 'Account code', 'Remarks'],
            $m->items->where('is_heading', false)->values()->map(fn ($i, $n) => [
                $n + 1, $i->description, $i->code, $i->qty, $i->unit,
                $i->unit_price, $i->line_total, $m->currency, $i->account_code, $i->remarks,
            ])->all(),
        ];
    }

    /** One page of plain text, so the folder says what it is on its own. */
    public function summary(): string
    {
        $m = $this->model;

        $partyLabel = match ($this->type) {
            'purchase_order' => 'Vendor',
            'payroll_run' => 'Month',
            'payroll_employees' => 'Holds',
            default => 'Customer',
        };

        $rows = array_filter([
            'Document' => $this->number(),
            'Type' => ucwords(str_replace('_', ' ', $this->type)),
            $partyLabel => $this->type === 'payroll_employees' ? $m->statusLine() : $this->party(),
            'Date' => $this->date()->format($this->type === 'payroll_run' ? 'F Y' : 'd M Y'),
            'Status' => $m->status ? ucfirst($m->status) : null,
            'Paid on' => $this->type === 'payroll_run' ? $m->payment_date?->format('d M Y') : null,
            'Vessel' => $m->ship_name ?: $m->rfq?->ship_name,
            'From enquiry' => $this->enquiryReference(),
            'Their reference' => $m->customer_reference ?: $m->rfq?->customer_reference,
        ], fn ($v) => $v !== null && $v !== '');

        $money = $this->type === 'payroll_run'
            ? array_filter([
                'Gross earnings' => $m->lines->sum('gross_earnings'),
                'Employee CPF' => $m->lines->sum('employee_cpf'),
                'Net paid' => $m->lines->sum('net_salary'),
                'Employer CPF' => $m->lines->sum('employer_cpf'),
                'SDL' => $m->lines->sum('sdl'),
                'Cost to business' => $m->lines->sum('total_employer_cost'),
            ], fn ($v) => (float) $v !== 0.0)
            : array_filter([
                'Subtotal' => $m->subtotal,
                'Discount' => $m->discount_amount ?? null,
                'Packing' => $m->packing_cost ?? null,
                'Transport' => $m->transportation_cost ?? null,
                'GST' => $m->tax_amount ?? null,
                'Total' => $m->grand_total ?? null,
            ], fn ($v) => $v !== null && (float) $v !== 0.0);

        $lines = ['MATRIA MARINE SERVICES — '.$this->number(), str_repeat('=', 60), ''];

        foreach ($rows as $label => $value) {
            $lines[] = sprintf('%-18s %s', $label, $value);
        }

        if ($money) {
            $lines[] = '';
            $currency = $m->currency ?: 'SGD';
            foreach ($money as $label => $value) {
                $lines[] = sprintf('%-18s %s %s', $label, $currency, number_format((float) $value, 2));
            }
        }

        $items = count($this->lines()[1]);
        // The three text files are counted by name, not by calling
        // inlineFiles() — which is what renders this summary.
        $files = count($this->binaries()) + 3;
        $lines[] = '';
        $lines[] = sprintf(
            '%-18s %s, %s',
            'Contents',
            $items.' '.Str::plural('line item', $items),
            $files.' '.Str::plural('file', $files),
        );
        $lines[] = sprintf('%-18s %s', 'Backed up', now()->format('d M Y H:i'));
        $lines[] = '';
        $lines[] = 'data.json holds the complete record this was written from.';

        // Windows line endings: this is opened in Notepad on a PC, and Notepad
        // on older Windows runs a bare \n all into one line.
        return implode("\r\n", $lines)."\r\n";
    }

    // --------------------------------------------------------- the manifest

    /**
     * What the browser needs to write this folder.
     *
     * Binaries are listed with the index to ask for, never a storage path or a
     * signed R2 link — the frontend fetches each one back through the API with
     * the token it already has, so nothing private is handed to the page and
     * R2 never needs to accept cross-origin reads.
     */
    public function manifest(): array
    {
        $binaries = $this->binaries();

        return [
            'type' => $this->type,
            'id' => $this->model->id,
            'number' => $this->number(),
            'folder' => $this->folder(),
            'inline' => $this->inlineFiles(),
            'binaries' => array_values(array_map(fn ($file, $i) => [
                'index' => $i,
                'name' => $file['name'],
                'bytes' => $file['bytes'],
            ], $binaries, array_keys($binaries))),
            'index_row' => $this->indexRow(),
        ];
    }

    /** This document's row in the backup root's _index.csv. */
    public function indexRow(): array
    {
        $m = $this->model;

        // Both payroll types live under PAYROLL, so the folder name alone would
        // not tell a month from the staff list in the index.
        $label = match ($this->type) {
            'payroll_run' => 'PAYROLL',
            'payroll_employees' => 'PAYROLL EMPLOYEES',
            default => self::FOLDERS[$this->type],
        };

        $total = match ($this->type) {
            'payroll_run' => $m->lines->sum('total_employer_cost'),
            'payroll_employees' => $m->employees->count(),
            default => $m->grand_total ?? $m->subtotal ?? '',
        };

        return [
            'type' => $label,
            'number' => $this->number(),
            'date' => $this->date()->format('Y-m-d'),
            'party' => $this->type === 'payroll_employees' ? $m->statusLine() : $this->party(),
            'status' => (string) ($m->status ?? ''),
            'currency' => (string) ($m->currency ?? ''),
            'total' => (string) $total,
            'folder' => $this->folder(),
            'fingerprint' => $this->fingerprint(),
        ];
    }

    // ------------------------------------------------------ has it changed?

    /**
     * What goes into a document's change fingerprint, per type.
     *
     * A folder on disk goes out of date the moment anything it was built from
     * moves — an edited mark-up, a line added, a vendor quote arriving, a
     * credit note raised. The document's own updated_at does not see most of
     * that, because changing a line item does not touch its parent row.
     *
     * So the fingerprint folds in the children too: when the figure here stops
     * matching the one recorded in _index.csv, that folder needs writing again.
     *
     * Written as scalar subqueries rather than relations so the whole pending
     * list costs one query per type instead of one per document.
     *
     * The money is folded in beside the timestamps on purpose. An updated_at
     * column holds whole seconds, so two edits inside the same second leave it
     * looking untouched; carrying the totals as well means a price or mark-up
     * that actually moved is caught whatever the clock says.
     *
     * @var array<string, array{0: string, 1: list<string>}> type => [table, parts]
     */
    private const CHANGE_PARTS = [
        'enquiry' => ['rfqs', [
            'rfqs.updated_at',
            'rfqs.status',
            '(select max(updated_at) from rfq_items where rfq_items.rfq_id = rfqs.id)',
            '(select count(*) from rfq_items where rfq_items.rfq_id = rfqs.id)',
            '(select sum(qty) from rfq_items where rfq_items.rfq_id = rfqs.id)',
            '(select count(*) from rfq_attachments where rfq_attachments.rfq_id = rfqs.id)',
            '(select count(*) from rfq_item_attachments where rfq_item_attachments.rfq_item_id in (select id from rfq_items where rfq_items.rfq_id = rfqs.id))',
            '(select max(updated_at) from quotes where quotes.rfq_id = rfqs.id)',
            '(select count(*) from quotes where quotes.rfq_id = rfqs.id)',
            '(select count(*) from quote_attachments where quote_attachments.quote_id in (select id from quotes where quotes.rfq_id = rfqs.id))',
        ]],
        'offer' => ['offers', [
            'offers.updated_at',
            'offers.status',
            'offers.grand_total',
            'offers.markup_total',
            'offers.discount_amount',
            '(select max(updated_at) from offer_items where offer_items.offer_id = offers.id)',
            '(select count(*) from offer_items where offer_items.offer_id = offers.id)',
            '(select sum(line_total) from offer_items where offer_items.offer_id = offers.id)',
            '(select sum(markup_pct) from offer_items where offer_items.offer_id = offers.id)',
        ]],
        'invoice' => ['customer_invoices', [
            'customer_invoices.updated_at',
            'customer_invoices.status',
            'customer_invoices.grand_total',
            '(select max(updated_at) from customer_invoice_items where customer_invoice_items.customer_invoice_id = customer_invoices.id)',
            '(select count(*) from customer_invoice_items where customer_invoice_items.customer_invoice_id = customer_invoices.id)',
            '(select sum(line_total) from customer_invoice_items where customer_invoice_items.customer_invoice_id = customer_invoices.id)',
            '(select count(*) from credit_memos where credit_memos.customer_invoice_id = customer_invoices.id)',
            '(select max(updated_at) from credit_memos where credit_memos.customer_invoice_id = customer_invoices.id)',
        ]],
        'purchase_order' => ['purchase_orders', [
            'purchase_orders.updated_at',
            'purchase_orders.status',
            'purchase_orders.subtotal',
            'purchase_orders.credit_note_amount',
            '(select max(updated_at) from purchase_order_items where purchase_order_items.purchase_order_id = purchase_orders.id)',
            '(select count(*) from purchase_order_items where purchase_order_items.purchase_order_id = purchase_orders.id)',
            '(select sum(line_total) from purchase_order_items where purchase_order_items.purchase_order_id = purchase_orders.id)',
            '(select count(*) from purchase_order_attachments where purchase_order_attachments.purchase_order_id = purchase_orders.id)',
            '(select max(updated_at) from return_notes where return_notes.purchase_order_id = purchase_orders.id)',
        ]],
        'payroll_run' => ['payroll_runs', [
            'payroll_runs.updated_at',
            'payroll_runs.status',
            'payroll_runs.payment_date',
            'payroll_runs.account_code',
            'payroll_runs.cpf_account_code',
            'payroll_runs.sdl_account_code',
            '(select max(updated_at) from payroll_run_lines where payroll_run_lines.run_id = payroll_runs.id)',
            '(select count(*) from payroll_run_lines where payroll_run_lines.run_id = payroll_runs.id)',
            '(select sum(net_salary) from payroll_run_lines where payroll_run_lines.run_id = payroll_runs.id)',
            '(select sum(total_employer_cost) from payroll_run_lines where payroll_run_lines.run_id = payroll_runs.id)',
        ]],
    ];

    /**
     * The staff list has no row of its own, so its fingerprint comes from the
     * table as a whole: how many people are on it, when any of them last
     * changed, and what they are paid.
     */
    private static function rosterFingerprint(): string
    {
        $row = DB::table('payroll_employees')
            ->selectRaw('count(*) as people')
            ->selectRaw('max(updated_at) as touched')
            ->selectRaw('sum(basic_salary) as basic')
            ->selectRaw('sum(fixed_allowance) as allowance')
            ->selectRaw("count(case when status = 'active' then 1 end) as active")
            ->first();

        return substr(sha1(implode('|', [
            $row->people ?? 0, $row->touched ?? '', $row->basic ?? '', $row->allowance ?? '', $row->active ?? 0,
        ])), 0, 12);
    }

    /**
     * Change fingerprints for a whole type in one query.
     *
     * @param  list<int>|null  $ids  null for every document of that type
     * @return array<int, string> id => fingerprint
     */
    public static function fingerprints(string $type, ?array $ids = null): array
    {
        if ($type === 'payroll_employees') {
            return [BackupRoster::ID => self::rosterFingerprint()];
        }

        [$table, $parts] = self::CHANGE_PARTS[$type];

        $query = DB::table($table)->select($table.'.id');

        foreach ($parts as $i => $sql) {
            $query->selectRaw($sql.' as part_'.$i);
        }

        if ($ids !== null) {
            $query->whereIn($table.'.id', $ids);
        }

        $out = [];

        foreach ($query->get() as $row) {
            // Hashed in PHP, not in SQL: sqlite (which the tests run on) has no
            // md5(), and concat_ws() treats nulls differently across drivers.
            $values = array_map(
                fn ($i) => (string) ($row->{'part_'.$i} ?? ''),
                array_keys($parts),
            );

            $out[$row->id] = substr(sha1(implode('|', $values)), 0, 12);
        }

        return $out;
    }

    /** This document's change fingerprint. */
    public function fingerprint(): string
    {
        return self::fingerprints($this->type, [$this->model->id])[$this->model->id] ?? '';
    }

    /**
     * The column order of _index.csv at the root of the backup folder.
     *
     * The browser maintains that file, because only the browser knows what
     * actually reached the disk. It fills backed_up_at itself — the server has
     * no idea when, or whether, a given machine took its copy.
     */
    public const INDEX_COLUMNS = ['type', 'number', 'date', 'party', 'status', 'currency', 'total', 'folder', 'backed_up_at', 'fingerprint'];
}
