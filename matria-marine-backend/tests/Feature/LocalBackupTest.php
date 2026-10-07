<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\PurchaseOrder;
use App\Models\Rfq;
use App\Models\User;
use App\Models\Vendor;
use App\Support\BackupBundle;
use App\Support\PortalPages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The local backup: the folder of documents kept on staff machines.
 *
 * The thing these tests exist to protect is the promise that backing a
 * document up never changes it — see the read-only test at the bottom. The
 * rest cover the folder naming (which has to survive Windows), the files that
 * land in it, and the fact that mirroring obeys the same page permissions as
 * reading.
 */
class LocalBackupTest extends TestCase
{
    use RefreshDatabase;

    /**
     * An admin with the given pages, plus Local Backup — which gates the whole
     * feature, so almost every test here needs it. Pass false to leave it out
     * and test the gate itself.
     */
    private function admin(array $pages, bool $withLocalBackup = true): User
    {
        Role::findOrCreate('admin', 'web');
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $user->assignRole('admin');

        if ($withLocalBackup) {
            $pages[] = 'local_backup';
        }

        $user->syncPermissions(array_map(fn ($k) => PortalPages::permission($k), array_unique($pages)));
        Sanctum::actingAs($user);

        return $user;
    }

    private function enquiry(array $attributes = []): Rfq
    {
        $customer = Customer::factory()->create(['name' => 'Seven Seas Shipping']);

        $rfq = Rfq::create(array_merge([
            'reference' => 'MMS-QTN-2026-00023',
            'customer_id' => $customer->id,
            'base_currency' => 'SGD',
            'status' => 'awarded',
            'ship_name' => 'MV Pacific Star',
            'received_date' => '2026-03-14',
        ], $attributes));

        $rfq->items()->create([
            'description' => 'Cylinder head gasket set',
            'impa_no' => '751234',
            'qty' => 4,
            'unit' => 'set',
            'sort' => 1,
        ]);

        return $rfq;
    }

    // ------------------------------------------------------------- the folder

    public function test_folder_is_type_then_year_then_number_and_party(): void
    {
        $rfq = $this->enquiry();

        $this->assertSame(
            'ENQUIRIES/2026/MMS-QTN-2026-00023 Seven Seas Shipping',
            BackupBundle::make('enquiry', $rfq->id)->folder()
        );
    }

    public function test_folder_is_filed_under_the_documents_own_year(): void
    {
        $rfq = $this->enquiry(['received_date' => '2025-12-31']);

        $this->assertStringStartsWith(
            'ENQUIRIES/2025/',
            BackupBundle::make('enquiry', $rfq->id)->folder()
        );
    }

    public function test_a_document_from_an_enquiry_names_it_in_brackets(): void
    {
        $rfq = $this->enquiry();
        $vendor = Vendor::factory()->create(['name' => 'Sin Chew Marine']);

        $po = PurchaseOrder::create([
            'po_number' => 'MMS-PO-2026-000041',
            'rfq_id' => $rfq->id,
            'vendor_id' => $vendor->id,
            'currency' => 'SGD',
            'status' => 'issued',
            'issued_date' => '2026-03-20',
        ]);

        $this->assertSame(
            'PURCHASE ORDERS/2026/MMS-PO-2026-000041 (MMS-QTN-2026-00023) Sin Chew Marine',
            BackupBundle::make('purchase_order', $po->id)->folder()
        );
    }

    public function test_a_direct_purchase_has_no_brackets(): void
    {
        $vendor = Vendor::factory()->create(['name' => 'Office Supplies Co']);

        $po = PurchaseOrder::create([
            'po_number' => 'MMS-PO-2026-000042',
            'vendor_id' => $vendor->id,
            'currency' => 'SGD',
            'status' => 'issued',
            'issued_date' => '2026-03-20',
        ]);

        $this->assertSame(
            'PURCHASE ORDERS/2026/MMS-PO-2026-000042 Office Supplies Co',
            BackupBundle::make('purchase_order', $po->id)->folder()
        );
    }

    /**
     * Windows refuses a path with any of < > : " / \ | ? * in it, and customer
     * names carry slashes and ampersands as a matter of course.
     */
    public function test_a_party_name_windows_would_refuse_is_made_safe(): void
    {
        $rfq = $this->enquiry();
        $rfq->customer->update(['name' => 'A/S Møller & Co: "Ships" <Asia>']);

        $folder = BackupBundle::make('enquiry', $rfq->id)->folder();

        foreach (['<', '>', ':', '"', '|', '?', '*', '\\'] as $illegal) {
            $this->assertStringNotContainsString($illegal, $folder, "[{$illegal}] survived into the folder name");
        }

        // The slashes separating the folders are the only ones left.
        $this->assertCount(3, explode('/', $folder));
    }

    public function test_reserved_windows_device_names_are_escaped(): void
    {
        $this->assertSame('_CON', BackupBundle::safeName('CON'));
        $this->assertSame('_lpt1', BackupBundle::safeName('lpt1'));
        $this->assertSame('CONTAINER', BackupBundle::safeName('CONTAINER'));
    }

    public function test_a_trailing_dot_or_space_is_trimmed(): void
    {
        $this->assertSame('Acme Pte Ltd', BackupBundle::safeName('Acme Pte Ltd.  '));
    }

    // ------------------------------------------------------------ the content

    public function test_the_manifest_lists_the_text_files_and_the_pdf(): void
    {
        $this->admin(['enquiries']);
        $rfq = $this->enquiry();

        $manifest = $this->getJson("/api/portal/backup/enquiry/{$rfq->id}")
            ->assertOk()
            ->json('data');

        $this->assertSame('ENQUIRIES/2026/MMS-QTN-2026-00023 Seven Seas Shipping', $manifest['folder']);
        $this->assertArrayHasKey('data.json', $manifest['inline']);
        $this->assertArrayHasKey('line-items.csv', $manifest['inline']);
        $this->assertArrayHasKey('summary.txt', $manifest['inline']);

        $this->assertSame('MMS-QTN-2026-00023.pdf', $manifest['binaries'][0]['name']);
        $this->assertSame(0, $manifest['binaries'][0]['index']);
    }

    public function test_the_documents_pdf_comes_back_as_a_pdf(): void
    {
        $this->admin(['enquiries']);
        $rfq = $this->enquiry();

        $bytes = $this->get("/api/portal/backup/enquiry/{$rfq->id}/file/0")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->getContent();

        $this->assertStringStartsWith('%PDF', $bytes);
    }

    public function test_attachments_are_listed_and_served_from_storage(): void
    {
        Storage::fake('r2');
        Storage::disk('r2')->put('rfqs/1/survey report.pdf', 'the survey');

        $this->admin(['enquiries']);
        $rfq = $this->enquiry();

        $rfq->attachments()->create([
            'disk' => 'r2',
            'path' => 'rfqs/1/survey report.pdf',
            'original_name' => 'survey report.pdf',
            'mime_type' => 'application/pdf',
            'size' => 10,
        ]);

        $manifest = $this->getJson("/api/portal/backup/enquiry/{$rfq->id}")->assertOk()->json('data');

        $names = array_column($manifest['binaries'], 'name');
        $this->assertContains('attachments/survey report.pdf', $names);

        $index = array_search('attachments/survey report.pdf', $names, true);

        $this->assertSame(
            'the survey',
            $this->get("/api/portal/backup/enquiry/{$rfq->id}/file/{$index}")->assertOk()->getContent()
        );
    }

    public function test_a_missing_file_is_reported_rather_than_crashing_the_backup(): void
    {
        Storage::fake('r2');

        $this->admin(['enquiries']);
        $rfq = $this->enquiry();

        $rfq->attachments()->create([
            'disk' => 'r2',
            'path' => 'rfqs/1/deleted.pdf',
            'original_name' => 'deleted.pdf',
            'mime_type' => 'application/pdf',
            'size' => 10,
        ]);

        // Index 1 — the enquiry's own PDF is 0.
        $this->get("/api/portal/backup/enquiry/{$rfq->id}/file/1")->assertStatus(410);
    }

    public function test_asking_for_a_file_that_is_not_in_the_bundle_is_a_404(): void
    {
        $this->admin(['enquiries']);
        $rfq = $this->enquiry();

        $this->get("/api/portal/backup/enquiry/{$rfq->id}/file/99")->assertNotFound();
    }

    public function test_magic_link_tokens_never_reach_the_backup_folder(): void
    {
        $this->admin(['invoices']);

        $customer = Customer::factory()->create(['name' => 'Seven Seas Shipping']);
        $invoice = CustomerInvoice::create([
            'invoice_number' => 'MMS-INV-2026-000327',
            'customer_id' => $customer->id,
            'currency' => 'SGD',
            'status' => 'sent',
            'issue_date' => '2026-03-25',
        ]);

        $this->assertNotEmpty($invoice->token, 'the invoice should have a magic-link token to begin with');

        // Fetched as a whole array: "data.json" has a dot in it, which the
        // dotted-path helper would read as another level.
        $inline = $this->getJson("/api/portal/backup/invoice/{$invoice->id}")
            ->assertOk()
            ->json('data.inline');

        $json = $inline['data.json'];

        $this->assertStringNotContainsString($invoice->token, $json);
        $this->assertStringNotContainsString('"token"', $json);
    }

    public function test_the_csv_carries_a_bom_so_excel_reads_it_correctly(): void
    {
        $this->admin(['enquiries']);
        $rfq = $this->enquiry();

        $csv = BackupBundle::make('enquiry', $rfq->id)->csv();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('Cylinder head gasket set', $csv);
    }

    public function test_the_zip_holds_the_same_folder_the_manifest_asks_for(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('No zip extension on this PHP.');
        }

        $this->admin(['enquiries']);
        $rfq = $this->enquiry();

        // A download response hands back the file itself, not a stream.
        $response = $this->get("/api/portal/backup/enquiry/{$rfq->id}/zip")->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'test-zip-');
        copy($response->baseResponse->getFile()->getPathname(), $path);

        $zip = new \ZipArchive();
        $zip->open($path);

        $inside = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $inside[] = $zip->getNameIndex($i);
        }
        $zip->close();
        @unlink($path);

        $folder = 'ENQUIRIES/2026/MMS-QTN-2026-00023 Seven Seas Shipping';
        $this->assertContains($folder.'/data.json', $inside);
        $this->assertContains($folder.'/summary.txt', $inside);
        $this->assertContains($folder.'/line-items.csv', $inside);
        $this->assertContains($folder.'/MMS-QTN-2026-00023.pdf', $inside);
    }

    // -------------------------------------------------------------- the rules

    public function test_mirroring_obeys_the_page_the_document_belongs_to(): void
    {
        $this->admin(['invoices']);
        $rfq = $this->enquiry();

        $this->getJson("/api/portal/backup/enquiry/{$rfq->id}")
            ->assertForbidden()
            ->assertJsonPath('page', 'enquiries');

        $this->get("/api/portal/backup/enquiry/{$rfq->id}/file/0")->assertForbidden();
        $this->get("/api/portal/backup/enquiry/{$rfq->id}/zip")->assertForbidden();
    }

    public function test_the_pending_list_only_offers_what_this_admin_can_see(): void
    {
        $this->admin(['invoices']);

        $this->enquiry();
        $customer = Customer::factory()->create(['name' => 'Seven Seas Shipping']);
        CustomerInvoice::create([
            'invoice_number' => 'MMS-INV-2026-000327',
            'customer_id' => $customer->id,
            'currency' => 'SGD',
            'status' => 'sent',
            'issue_date' => '2026-03-25',
        ]);

        $types = array_unique(array_column(
            $this->getJson('/api/portal/backup/pending')->assertOk()->json('data'),
            'type'
        ));

        $this->assertSame(['invoice'], $types);
    }

    public function test_an_unknown_document_type_is_refused(): void
    {
        $this->admin(['enquiries', 'invoices', 'offers', 'purchase_orders']);

        $this->getJson('/api/portal/backup/payroll/1')->assertNotFound();
    }

    /**
     * Page access is decided by matching the URL, and those patterns are
     * written with hyphens. The underscore spelling must therefore not be a
     * route at all — otherwise it is a way into a purchase order for an admin
     * who was never given Purchase Orders.
     */
    public function test_the_underscore_spelling_is_not_a_second_way_in(): void
    {
        $vendor = Vendor::factory()->create(['name' => 'Sin Chew Marine']);
        $po = PurchaseOrder::create([
            'po_number' => 'MMS-PO-2026-000041',
            'vendor_id' => $vendor->id,
            'currency' => 'SGD',
            'status' => 'issued',
            'issued_date' => '2026-03-20',
        ]);

        // An admin with no Purchase Orders page.
        $this->admin(['invoices']);

        $this->getJson("/api/portal/backup/purchase-order/{$po->id}")->assertForbidden();
        $this->getJson("/api/portal/backup/purchase_order/{$po->id}")->assertNotFound();

        // And with the page, the canonical spelling works.
        $this->admin(['purchase_orders']);
        $this->getJson("/api/portal/backup/purchase-order/{$po->id}")->assertOk();
    }

    // -------------------------------------------------------------- payroll

    private function payrollRun(): \App\Models\Payroll\Run
    {
        $run = \App\Models\Payroll\Run::create([
            'period' => '2026-10-01',
            'payment_date' => '2026-10-28',
            'status' => 'draft',
            'currency' => 'SGD',
        ]);

        $run->lines()->create([
            'code' => 'MM001',
            'full_name' => 'Juan Dela Cruz',
            'cpf_scheme' => 'full',
            'shg_fund' => 'none',
            'basic_pay' => 3000,
            'gross_earnings' => 3000,
            'employee_cpf' => 600,
            'employer_cpf' => 510,
            'sdl' => 7.50,
            'total_deductions' => 600,
            'net_salary' => 2400,
            'total_employer_cost' => 3517.50,
        ]);

        return $run;
    }

    public function test_a_payroll_month_is_filed_by_year_and_month(): void
    {
        $run = $this->payrollRun();

        $this->assertSame(
            'PAYROLL/2026/2026-10 October',
            BackupBundle::make('payroll_run', $run->id)->folder()
        );
    }

    public function test_a_payroll_month_holds_the_summary_and_a_payslip_each(): void
    {
        $this->admin(['local_backup', 'payroll']);
        $run = $this->payrollRun();

        $manifest = $this->getJson("/api/portal/backup/payroll-run/{$run->id}")->assertOk()->json('data');

        $names = array_column($manifest['binaries'], 'name');
        $this->assertContains('payroll-summary-2026-10.pdf', $names);
        $this->assertContains('payslips/MM001 Juan Dela Cruz.pdf', $names);

        $this->assertStringStartsWith(
            '%PDF',
            $this->get("/api/portal/backup/payroll-run/{$run->id}/file/1")->assertOk()->getContent()
        );
    }

    public function test_the_staff_list_is_filed_on_its_own_without_a_year(): void
    {
        $this->admin(['local_backup', 'payroll']);

        \App\Models\Payroll\Employee::create([
            'code' => 'MM001',
            'full_name' => 'Juan Dela Cruz',
            'status' => 'active',
            'basic_salary' => 3000,
            'nric' => 'S1234567A',
            'bank_ref' => '123-456-789',
        ]);

        $manifest = $this->getJson('/api/portal/backup/payroll-employees/1')->assertOk()->json('data');

        $this->assertSame('PAYROLL/EMPLOYEES', $manifest['folder']);
        // A list, not a document — there is nothing to print.
        $this->assertSame([], $manifest['binaries']);
        $this->assertStringContainsString('Juan Dela Cruz', $manifest['inline']['line-items.csv']);
    }

    /**
     * A backup folder gets copied onto sticks and sync drives. An NRIC and a
     * bank reference are what someone would need to impersonate a person or
     * redirect their pay, and neither is needed to know who worked here.
     */
    public function test_nric_and_bank_details_stay_out_of_the_staff_list(): void
    {
        $this->admin(['local_backup', 'payroll']);

        \App\Models\Payroll\Employee::create([
            'code' => 'MM001',
            'full_name' => 'Juan Dela Cruz',
            'status' => 'active',
            'basic_salary' => 3000,
            'nric' => 'S1234567A',
            'bank_ref' => '123-456-789',
        ]);

        $inline = $this->getJson('/api/portal/backup/payroll-employees/1')->assertOk()->json('data.inline');
        $written = implode("\n", $inline);

        $this->assertStringNotContainsString('S1234567A', $written);
        $this->assertStringNotContainsString('123-456-789', $written);
        $this->assertStringContainsString('Juan Dela Cruz', $written);
    }

    public function test_payroll_is_refused_to_an_admin_without_the_payroll_page(): void
    {
        $run = $this->payrollRun();

        // Local Backup, but no Payroll.
        $this->admin(['local_backup', 'invoices']);

        $this->getJson("/api/portal/backup/payroll-run/{$run->id}")->assertForbidden();
        $this->getJson('/api/portal/backup/payroll-employees/1')->assertForbidden();

        $types = array_unique(array_column(
            $this->getJson('/api/portal/backup/pending')->assertOk()->json('data'),
            'type'
        ));

        $this->assertNotContains('payroll_run', $types);
        $this->assertNotContains('payroll_employees', $types);
    }

    public function test_hiring_someone_marks_the_staff_list_as_changed(): void
    {
        $before = BackupBundle::fingerprints('payroll_employees')[1];

        \App\Models\Payroll\Employee::create([
            'code' => 'MM002',
            'full_name' => 'Maria Santos',
            'status' => 'active',
            'basic_salary' => 2800,
        ]);

        $this->assertNotSame($before, BackupBundle::fingerprints('payroll_employees')[1]);
    }

    // ----------------------------------------------- the Local Backup page

    public function test_the_whole_feature_is_closed_without_local_backup(): void
    {
        $rfq = $this->enquiry();

        // Every document page, but not Local Backup.
        $this->admin(['enquiries', 'invoices', 'offers', 'purchase_orders', 'payroll'], false);

        $this->getJson('/api/portal/backup/pending')
            ->assertForbidden()
            ->assertJsonPath('page', 'local_backup');

        $this->getJson("/api/portal/backup/enquiry/{$rfq->id}")->assertForbidden();
        $this->get("/api/portal/backup/enquiry/{$rfq->id}/file/0")->assertForbidden();
        $this->get("/api/portal/backup/enquiry/{$rfq->id}/zip")->assertForbidden();
    }

    public function test_local_backup_is_one_of_the_pages_a_super_admin_can_grant(): void
    {
        $this->assertContains('local_backup', PortalPages::keys());
        $this->assertSame('Local Backup', PortalPages::label('local_backup'));
    }

    // ------------------------------------------------- has it changed since?

    public function test_the_manifest_carries_a_change_fingerprint(): void
    {
        $this->admin(['enquiries']);
        $rfq = $this->enquiry();

        $row = $this->getJson("/api/portal/backup/enquiry/{$rfq->id}")->assertOk()->json('data.index_row');

        $this->assertNotEmpty($row['fingerprint']);
        $this->assertContains('fingerprint', BackupBundle::INDEX_COLUMNS);
    }

    /**
     * Editing a LINE must change the fingerprint.
     *
     * This is the case the document's own updated_at misses — changing a line
     * item does not touch its parent row — and it is the ordinary one: Dru
     * changes a mark-up from 20% to 25% and the folder on disk is now wrong.
     */
    public function test_editing_a_line_marks_the_document_as_changed(): void
    {
        $rfq = $this->enquiry();
        $offer = \App\Models\Offer::create([
            'offer_number' => $rfq->reference,
            'rfq_id' => $rfq->id,
            'customer_id' => $rfq->customer_id,
            'currency' => 'USD',
            'status' => 'draft',
        ]);
        $item = $offer->items()->create([
            'description' => 'Mooring rope, 64mm x 220m',
            'qty' => 2,
            'unit' => 'coil',
            'base_price' => 1757.50,
            'markup_pct' => 20,
            'unit_price' => 2109.00,
            'line_total' => 4218.00,
        ]);

        $before = BackupBundle::make('offer', $offer->id)->fingerprint();

        $item->update(['markup_pct' => 25, 'unit_price' => 2196.88, 'line_total' => 4393.76]);

        $this->assertNotSame(
            $before,
            BackupBundle::make('offer', $offer->id)->fingerprint(),
            'changing a line left the fingerprint alone, so the backup screen would call the folder up to date'
        );
    }

    public function test_adding_an_attachment_marks_an_enquiry_as_changed(): void
    {
        Storage::fake('r2');
        $rfq = $this->enquiry();

        $before = BackupBundle::make('enquiry', $rfq->id)->fingerprint();

        $rfq->attachments()->create([
            'disk' => 'r2',
            'path' => 'rfqs/1/late-arrival.pdf',
            'original_name' => 'late-arrival.pdf',
            'mime_type' => 'application/pdf',
            'size' => 10,
        ]);

        $this->assertNotSame($before, BackupBundle::make('enquiry', $rfq->id)->fingerprint());
    }

    public function test_an_untouched_document_keeps_the_same_fingerprint(): void
    {
        $rfq = $this->enquiry();

        $this->assertSame(
            BackupBundle::make('enquiry', $rfq->id)->fingerprint(),
            BackupBundle::make('enquiry', $rfq->id)->fingerprint(),
            'the fingerprint is not stable, so every document would look changed on every visit'
        );
    }

    public function test_the_pending_list_fingerprints_match_the_manifests(): void
    {
        $this->admin(['invoices']);

        $customer = Customer::factory()->create(['name' => 'Seven Seas Shipping']);
        $invoice = CustomerInvoice::create([
            'invoice_number' => 'MMS-INV-2026-000327',
            'customer_id' => $customer->id,
            'currency' => 'SGD',
            'status' => 'sent',
            'issue_date' => '2026-03-25',
        ]);

        $listed = collect($this->getJson('/api/portal/backup/pending')->assertOk()->json('data'))
            ->firstWhere('number', 'MMS-INV-2026-000327');

        $manifest = $this->getJson("/api/portal/backup/invoice/{$invoice->id}")->assertOk()->json('data');

        // If these two ever disagree, every document reads as changed for ever.
        $this->assertSame($listed['fingerprint'], $manifest['index_row']['fingerprint']);
    }

    /**
     * Mirroring everything is hundreds of requests in a minute — one manifest
     * plus every file, per document. On the ordinary 60-a-minute allowance a
     * 40-document backup died at 23 with "Too Many Attempts", leaving the rest
     * silently unwritten.
     */
    public function test_backup_traffic_has_its_own_rate_limit(): void
    {
        $limiter = app(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $resolver = \Illuminate\Support\Facades\RateLimiter::limiter('api');

        $request = \Illuminate\Http\Request::create('/api/portal/backup/invoice/1');
        $ordinary = \Illuminate\Http\Request::create('/api/portal/invoices');

        $this->assertSame(300, $resolver($request)->maxAttempts);
        $this->assertSame(60, $resolver($ordinary)->maxAttempts);

        // Separate buckets, so a backup does not eat the portal's allowance.
        $this->assertNotSame($resolver($request)->key, $resolver($ordinary)->key);
        $this->assertNotNull($limiter);
    }

    /**
     * The whole point. Backing a document up is a read: no number is issued,
     * no status moves, nothing is saved. If this ever fails, the backup button
     * has become capable of changing the records it is meant to preserve.
     */
    public function test_backing_up_changes_nothing_at_all(): void
    {
        $this->admin(['offers', 'enquiries']);

        $rfq = $this->enquiry();
        $before = Rfq::find($rfq->id)->toArray();
        $itemsBefore = $rfq->items()->get()->toArray();

        $this->getJson("/api/portal/backup/enquiry/{$rfq->id}")->assertOk();
        $this->get("/api/portal/backup/enquiry/{$rfq->id}/file/0")->assertOk();

        $this->assertSame($before, Rfq::find($rfq->id)->toArray());
        $this->assertSame($itemsBefore, $rfq->items()->get()->toArray());
    }

    /**
     * An offer's pro-forma number is issued the first time the pro-forma is
     * downloaded. The backup must never be the thing that issues it.
     */
    public function test_backing_up_an_offer_does_not_issue_a_proforma_number(): void
    {
        $this->admin(['offers', 'enquiries']);

        $rfq = $this->enquiry();
        $offer = \App\Models\Offer::create([
            'offer_number' => $rfq->reference,
            'rfq_id' => $rfq->id,
            'customer_id' => $rfq->customer_id,
            'currency' => 'SGD',
            'status' => 'draft',
        ]);

        $this->assertNull($offer->proforma_number);

        $this->getJson("/api/portal/backup/offer/{$offer->id}")->assertOk();
        $this->get("/api/portal/backup/offer/{$offer->id}/file/0")->assertOk();

        $this->assertNull($offer->fresh()->proforma_number, 'the backup issued a ProINV number');
    }
}
