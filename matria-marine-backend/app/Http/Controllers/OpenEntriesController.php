<?php

namespace App\Http\Controllers;

use App\Support\LedgerEntries;
use App\Support\OpenEntries;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Open entries across every customer or vendor at once — the management view
 * of "who owes what", as opposed to the single-party statement.
 *
 * Deliberately thin: all the reasoning lives in {@see OpenEntries} so the
 * screen, the CSV and the PDF can never disagree about the numbers.
 */
class OpenEntriesController extends Controller
{
    public function index(Request $request)
    {
        [$type, $asOf, $includeUnapplied] = $this->params($request);

        return response()->json([
            'success' => true,
            'data' => OpenEntries::build($type, $asOf, $includeUnapplied),
        ]);
    }

    /**
     * The full ledger: every invoice, credit note and payment in date order,
     * grouped by party — not just what is still open.
     */
    public function ledger(Request $request)
    {
        $data = $request->validate([
            'type' => ['required', 'in:customer,vendor'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        return response()->json([
            'success' => true,
            'data' => LedgerEntries::build(
                $data['type'],
                isset($data['from']) ? Carbon::parse($data['from']) : null,
                isset($data['to']) ? Carbon::parse($data['to']) : null,
            ),
        ]);
    }

    public function pdf(Request $request)
    {
        [$type, $asOf, $includeUnapplied] = $this->params($request);
        $newPagePerParty = $request->boolean('new_page_per_party');

        $report = OpenEntries::build($type, $asOf, $includeUnapplied);

        // Refuse loudly rather than time out half way through rendering. A
        // silent truncation here would read as "this is everyone", which on a
        // debt report is the one thing it must never wrongly say.
        if ($report['entry_count'] > OpenEntries::PDF_ENTRY_LIMIT) {
            return response()->json([
                'success' => false,
                'message' => sprintf(
                    'That is %s open entries across %s %ss — too many for one PDF. Narrow the date first, or use the CSV export.',
                    number_format($report['entry_count']),
                    number_format($report['party_count']),
                    $type
                ),
            ], 422);
        }

        $pdf = Pdf::loadView('pdf.open-entries', [
            'report' => $report,
            'isCustomer' => $type === 'customer',
            'newPagePerParty' => $newPagePerParty,
            'company' => config('procurement.company'),
            'logo' => is_file(public_path('logo.png'))
                ? 'data:image/png;base64,'.base64_encode(file_get_contents(public_path('logo.png')))
                : null,
        ]);

        return $pdf->download(sprintf(
            '%s-Open-Entries-%s.pdf',
            $type === 'customer' ? 'Customer' : 'Vendor',
            $report['as_of']
        ));
    }

    /** @return array{0: string, 1: ?Carbon, 2: bool} */
    private function params(Request $request): array
    {
        $data = $request->validate([
            'type' => ['required', 'in:customer,vendor'],
            'as_of' => ['nullable', 'date'],
        ]);

        return [
            $data['type'],
            isset($data['as_of']) ? Carbon::parse($data['as_of']) : null,
            // Read with boolean() rather than validated as one: a query string
            // carries these as the words "true"/"false", which Laravel's
            // boolean rule rejects.
            $request->has('include_unapplied') ? $request->boolean('include_unapplied') : true,
        ];
    }
}
