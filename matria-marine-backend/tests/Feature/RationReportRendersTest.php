<?php

namespace Tests\Feature;

use App\Models\Inventory\CrewRation;
use App\Models\Inventory\Vessel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\NamesItsVessel;
use Tests\TestCase;

/**
 * The ration report, as it prints.
 *
 * A page count and a "%PDF" header only prove dompdf did not crash. These read
 * the text back out of the finished file, because the point of the report is
 * that the owner can check the figures on it.
 */
class RationReportRendersTest extends TestCase
{
    use NamesItsVessel, RefreshDatabase;

    private Vessel $vessel;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('admin', 'web');
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $user->assignRole('admin');
        $this->grantAllPages($user);
        Sanctum::actingAs($user);

        $this->vessel = Vessel::current();
        $this->vessel->update([
            'name' => 'OEL VARUN', 'imo' => '9414759', 'crew_size' => 24,
            'ration_rate' => 9.30, 'visitor_meal_rate' => 3.10, 'charterer_meal_rate' => 0,
        ]);
    }

    /** 25 days of a full crew, with the month's visitors on one of them. */
    private function aMonthAtSea(): void
    {
        for ($d = 6; $d <= 30; $d++) {
            $row = [
                'vessel_id' => $this->vessel->id,
                'date' => sprintf('2026-09-%02d', $d),
                'location' => $d <= 8 ? 'Sea' : 'Port',
                'port' => $d <= 8 ? null : 'Singapore',
                'breakfast_crew' => 24, 'lunch_crew' => 24, 'dinner_crew' => 24,
            ];

            if ($d === 10) {
                $row += [
                    'breakfast_visitors' => 8, 'lunch_visitors' => 15, 'dinner_visitors' => 12,
                    'additional_persons' => 'Supt., RINA, Technicians',
                ];
            }

            CrewRation::create($row);
        }
    }

    /** The text drawn on the page, decoded from the content streams. */
    private function printed(string $pdf): string
    {
        $this->assertStringStartsWith('%PDF', $pdf);
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $m);

        $text = '';
        foreach ($m[1] as $raw) {
            $stream = @gzuncompress($raw);
            if ($stream !== false && str_contains($stream, ' Td ') && str_contains($stream, ' TJ ')) {
                $text .= $stream;
            }
        }

        $this->assertNotSame('', $text, 'no page content found in the PDF');

        return $text;
    }

    private function assertPrints(string $text, string $needle, string $message = ''): void
    {
        $this->assertStringContainsString(
            mb_convert_encoding($needle, 'UTF-16BE', 'UTF-8'),
            $text,
            $message ?: "the report does not print \"{$needle}\""
        );
    }

    public function test_the_report_prints_the_figures_the_owner_checks(): void
    {
        $this->aMonthAtSea();

        $text = $this->printed(
            $this->get($this->url('/crew-rations/invoice/pdf?from=2026-09-06&to=2026-09-30'))
                ->assertOk()->getContent()
        );

        $this->assertPrints($text, 'RATION REPORT');
        $this->assertPrints($text, 'OEL VARUN');
        $this->assertPrints($text, '9414759', 'the IMO number is how the owner identifies the vessel');

        // The period, as the catering agreement states it.
        $this->assertPrints($text, '06.09.2026');
        $this->assertPrints($text, '30.09.2026');

        // 24 men for 25 days = 600 rations at 9.30 = 5,580.00.
        $this->assertPrints($text, '600.00', 'the crew ration quantity');
        $this->assertPrints($text, '9.3000', 'the ration rate');
        $this->assertPrints($text, '5,580.00', 'crew rations at the ration rate');

        // 35 visitor meals at 3.10 = 108.50 â€” a different basis and rate.
        $this->assertPrints($text, '3.1000', 'the visitor meal rate');
        $this->assertPrints($text, '108.50', 'visitor meals at the meal rate');

        $this->assertPrints($text, '5,688.50', 'the total charged');
    }

    /** Every category is named, so a zero can be told from an omission. */
    public function test_the_report_names_all_four_charge_lines(): void
    {
        $this->aMonthAtSea();

        $text = $this->printed(
            $this->get($this->url('/crew-rations/invoice/pdf?from=2026-09-06&to=2026-09-30'))
                ->assertOk()->getContent()
        );

        foreach (['Crew', 'Passengers', 'owner / crew', 'charterer'] as $label) {
            $this->assertPrints($text, $label);
        }
        foreach (['Rations', 'Meals'] as $unit) {
            $this->assertPrints($text, $unit);
        }
    }

    /** The daily record backs the invoice up. */
    public function test_the_report_carries_the_daily_record(): void
    {
        $this->aMonthAtSea();

        $text = $this->printed(
            $this->get($this->url('/crew-rations/invoice/pdf?from=2026-09-06&to=2026-09-30'))
                ->assertOk()->getContent()
        );

        $this->assertPrints($text, 'DAILY RECORD');
        $this->assertPrints($text, 'Singapore', 'the port is part of the record');
        $this->assertPrints($text, 'Supt., RINA, Technicians', 'who the extra heads were');
        $this->assertPrints($text, 'Sea', 'where the vessel was');
    }

    /** It carries the company footer like every other document we send out. */
    public function test_the_report_is_identifiably_ours(): void
    {
        $this->aMonthAtSea();

        $text = $this->printed(
            $this->get($this->url('/crew-rations/invoice/pdf?from=2026-09-06&to=2026-09-30'))
                ->assertOk()->getContent()
        );

        $company = config('procurement.company');

        $this->assertPrints($text, $company['name']);
        $this->assertPrints($text, 'UEN: '.$company['uen']);
        $this->assertStringNotContainsString(
            mb_convert_encoding('SWIFT:', 'UTF-16BE', 'UTF-8'),
            $text,
            'a ration report is not a request for payment to our account'
        );
    }
}
