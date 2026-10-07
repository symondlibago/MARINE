<?php

namespace App\Http\Controllers;

use App\Models\CustomerInvoice;
use App\Models\Offer;
use App\Models\Payroll\Run;
use App\Models\PurchaseOrder;
use App\Models\Rfq;
use App\Support\BackupBundle;
use App\Support\BackupRoster;
use App\Support\PortalPages;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;

/**
 * Serves the local backup — the copy of every document that lives in a folder
 * on Dennis's and Dru's own machines.
 *
 * Two ways to get it there, both reading the same {@see BackupBundle}:
 *
 *   A  the browser writes the folders itself, through the File System Access
 *      API, after being shown the backup folder once. manifest() tells it what
 *      to write and file() hands over each piece.
 *   B  a ZIP of the same tree, for a browser that cannot do (A).
 *
 * EVERY ACTION HERE IS READ-ONLY. Backing a document up must never alter it,
 * so nothing in this controller writes to the database, issues a document
 * number or moves a status. That is what makes it safe to press at any time,
 * on any document, as often as you like.
 *
 * Access is the existing page permission for the document's own type — see
 * PortalPages, which routes portal/backup/invoice/* through the Invoices page.
 * Mirroring a document shows nothing its own screen does not already show.
 */
class BackupController extends Controller
{
    /**
     * Routes read nicer hyphenated; the bundle keys are snake_case.
     *
     * ONE spelling is accepted, and it matters: page access is decided by
     * matching the URL (see PortalPages), and those patterns are written with
     * hyphens. Were "backup/purchase_order/5" allowed in as well, it would be
     * a second address for the same document that the permission patterns do
     * not recognise — so an admin without Purchase Orders could read one
     * through it. Anything but the canonical spelling is simply not a route.
     */
    private function bundle(string $type, string $id): BackupBundle
    {
        $key = str_replace('-', '_', $type);

        abort_unless(
            BackupBundle::knows($key) && $type === str_replace('_', '-', $key),
            404,
            'Not a document type that can be backed up.'
        );

        // Local Backup, which the middleware has already checked, says whether
        // this admin may copy anything to a machine at all. This says whether
        // they may copy THIS — mirroring a document shows nothing its own
        // screen does not, so the right to mirror it is the right to read it.
        // Payroll above all: the folder ends up holding every salary.
        $page = BackupBundle::pageFor($key);
        $user = request()->user();

        if ($page && $user && ! PortalPages::canSee($user, $page)) {
            // Same shape EnsurePageAccess returns, so the frontend handles a
            // refusal from here no differently from one from the middleware.
            abort(response()->json([
                'success' => false,
                'message' => "You don't have access to ".PortalPages::label($page).'. Ask a super admin to add it to your account.',
                'page' => $page,
            ], 403));
        }

        return BackupBundle::make($key, $id);
    }

    /**
     * What one document's folder should contain.
     *
     * The small text files travel inline; the PDFs and attachments come back
     * one at a time from file(), by the index given here.
     */
    public function manifest(string $type, string $id)
    {
        return response()->json([
            'success' => true,
            'data' => $this->bundle($type, $id)->manifest(),
            'meta' => ['index_columns' => BackupBundle::INDEX_COLUMNS],
        ]);
    }

    /**
     * One file out of the bundle, by its index in the manifest.
     *
     * Everything goes through here rather than a signed R2 link: the page
     * already holds a token for this API, so no storage credential is handed
     * to the browser and R2 is never asked to allow a cross-origin read.
     */
    public function file(string $type, string $id, int $index)
    {
        [$bytes, $mime, $name] = $this->bundle($type, $id)->readBinary($index);

        return Response::make($bytes, 200, [
            'Content-Type' => $mime,
            'Content-Length' => (string) strlen($bytes),
            'Content-Disposition' => 'attachment; filename="'.addslashes($name).'"',
            // A backup is a point-in-time copy; never serve a stale one.
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * The same folder as a ZIP — the fallback for a browser without the File
     * System Access API, and the way to take one document to another machine.
     *
     * The paths inside the ZIP are the folder the manifest asks for, so
     * unpacking it into the backup root lands everything where (A) would have
     * put it.
     */
    public function zip(string $type, string $id)
    {
        abort_unless(
            class_exists(\ZipArchive::class),
            501,
            'This server has no ZIP support. Use a Chrome or Edge browser, which can write the folder directly.'
        );

        $bundle = $this->bundle($type, $id);
        $folder = $bundle->folder();

        $path = tempnam(sys_get_temp_dir(), 'matria-backup-');
        $zip = new \ZipArchive();

        abort_unless($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE), 500, 'Could not start the ZIP.');

        foreach ($bundle->inlineFiles() as $name => $contents) {
            $zip->addFromString($folder.'/'.$name, $contents);
        }

        foreach ($bundle->binaries() as $i => $file) {
            try {
                [$bytes] = $bundle->readBinary($i);
                $zip->addFromString($folder.'/'.$file['name'], $bytes);
            } catch (\Throwable $e) {
                // One unreadable attachment must not cost the whole document.
                // Say so inside the ZIP instead of failing the download.
                $zip->addFromString(
                    $folder.'/'.$file['name'].'.MISSING.txt',
                    'This file could not be read from storage when the backup ran: '.$e->getMessage()."\r\n"
                );
            }
        }

        $zip->close();

        return response()
            ->download($path, BackupBundle::safeName($bundle->number()).'.zip', [
                'Content-Type' => 'application/zip',
            ])
            ->deleteFileAfterSend();
    }

    /**
     * Every document that could be mirrored, so the screen can show what is
     * still missing from the folder.
     *
     * Deliberately not filtered by status: Dru decides when a job is finished
     * and worth keeping, not the system. The comparison with what is already
     * on disk happens in the browser, against the folder's own manifest — so
     * each machine sees its own gaps, and mirroring on the PC does not make
     * the laptop think it already has a copy.
     *
     * Only the types this admin's pages cover are listed.
     */
    public function pending(Request $request)
    {
        $user = $request->user();

        // Slim selects with just what the folder name is built from — no line
        // items, no attachments. The enquiry reference comes along because it
        // is printed in brackets on the documents that came out of a job.
        $sources = [
            'enquiry' => fn () => Rfq::with('customer:id,name')
                ->get(['id', 'reference', 'customer_id', 'status', 'received_date', 'created_at']),
            'offer' => fn () => Offer::with('customer:id,name')
                ->get(['id', 'offer_number', 'customer_id', 'customer_name', 'status', 'currency', 'grand_total', 'created_at']),
            'invoice' => fn () => CustomerInvoice::with(['customer:id,name', 'rfq:id,reference'])
                ->get(['id', 'invoice_number', 'rfq_id', 'customer_id', 'customer_name', 'status', 'currency', 'grand_total', 'issue_date', 'created_at']),
            'purchase_order' => fn () => PurchaseOrder::with(['vendor:id,name', 'rfq:id,reference'])
                ->get(['id', 'po_number', 'rfq_id', 'vendor_id', 'status', 'currency', 'subtotal', 'issued_date', 'created_at']),
            'payroll_run' => fn () => Run::query()
                ->get(['id', 'period', 'payment_date', 'status', 'currency', 'created_at']),
            // One row, always: the staff list as it stands.
            'payroll_employees' => fn () => collect([BackupRoster::load()]),
        ];

        $rows = [];

        foreach ($sources as $type => $load) {
            $page = BackupBundle::pageFor($type);

            if ($page && ! PortalPages::canSee($user, $page)) {
                continue;
            }

            // One query for the whole type, not one per document: the browser
            // compares these against what its _index.csv recorded to work out
            // which folders have gone out of date since they were written.
            $fingerprints = BackupBundle::fingerprints($type);

            foreach ($load() as $model) {
                $bundle = BackupBundle::of($type, $model);

                $rows[] = [
                    'type' => $type,
                    'id' => $model->id,
                    'number' => $bundle->number(),
                    'party' => $bundle->party(),
                    'date' => $bundle->date()->format('Y-m-d'),
                    'status' => (string) ($model->status ?? ''),
                    'folder' => $bundle->folder(),
                    'fingerprint' => $fingerprints[$model->id] ?? '',
                ];
            }
        }

        usort($rows, fn ($a, $b) => [$b['date'], $b['number']] <=> [$a['date'], $a['number']]);

        return response()->json([
            'success' => true,
            'data' => $rows,
            'meta' => [
                'folders' => BackupBundle::FOLDERS,
                'index_columns' => BackupBundle::INDEX_COLUMNS,
            ],
        ]);
    }
}
