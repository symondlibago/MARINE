<?php

namespace Tests\Unit;

use App\Support\PdfBlocks;
use PHPUnit\Framework\TestCase;

/**
 * The splitter changes where a description may break, never what it says.
 *
 * Every one of these is really the same test: join the blocks back with a
 * newline and you must have the description you started with. The blocks are
 * laid into separate table rows with no border and no padding between them, so
 * the reader sees one block of text either way — right up to the page break,
 * which is the whole point.
 */
class PdfBlocksTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function descriptions(): array
    {
        return [
            'one paragraph' => ["Two tug towage from Yalova Yard to Tuzla Shipyard"],
            'two paragraphs' => ["Towage fee: USD 50,000 lump sum.\n\nFree time: 6 hours."],
            'a wider gap' => ["Port demurrage.\n\n\n\nAnchor demurrage."],
            'blank line with spaces' => ["Tug 1: 31 tonnes.\n   \nTug 2: 11 tonnes."],
            'windows line endings' => ["Towage fee.\r\n\r\nFree time.\r\nReversible."],
            'a paragraph longer than a page' => [implode("\n", array_map(
                fn ($n) => "Condition {$n} of carriage, which the owners accept.",
                range(1, 40)
            ))],
            'single lines throughout' => ["A\nB\nC"],
        ];
    }

    /**
     * @dataProvider descriptions
     */
    public function test_the_blocks_rejoin_into_the_description(string $description): void
    {
        // Line endings and the spaces left on an otherwise empty line are not
        // text: one renders as a line break and the other renders as nothing.
        $same = fn (string $s) => preg_replace(
            '/^[ \t]+$/m', '', str_replace(["\r\n", "\r"], "\n", $s)
        );

        $this->assertSame($same($description), $same(implode("\n", PdfBlocks::split($description))));
    }

    public function test_an_empty_description_has_no_blocks(): void
    {
        foreach ([null, '', '   ', "\n\n", "  \r\n  "] as $empty) {
            $this->assertSame([], PdfBlocks::split($empty));
        }
    }

    /** A paragraph break rides at the head of the block that follows it. */
    public function test_a_paragraph_break_stays_with_the_text_it_precedes(): void
    {
        $this->assertSame(
            ['Towage fee: USD 50,000.', "\nFree time: 6 hours."],
            PdfBlocks::split("Towage fee: USD 50,000.\n\nFree time: 6 hours.")
        );
    }

    /** Two blank lines in, two blank lines out. */
    public function test_a_wider_gap_survives(): void
    {
        $this->assertSame(
            ['Port demurrage.', "\n\nAnchor demurrage."],
            PdfBlocks::split("Port demurrage.\n\n\nAnchor demurrage.")
        );
    }

    /**
     * A paragraph with nothing to cut at is cut anyway.
     *
     * Terms are sometimes pasted in as one unbroken run of lines. Left whole
     * it is one tall row again, which is the thing being fixed.
     */
    public function test_a_long_unbroken_paragraph_is_cut_into_rows(): void
    {
        $lines = array_map(fn ($n) => "Line {$n}", range(1, 25));

        $blocks = PdfBlocks::split(implode("\n", $lines));

        $this->assertGreaterThan(1, count($blocks), 'one tall row again');
        foreach ($blocks as $block) {
            $this->assertLessThanOrEqual(10, substr_count($block, "\n") + 1);
        }
    }

    /** Short descriptions, which is nearly all of them, are left alone. */
    public function test_an_ordinary_description_is_a_single_block(): void
    {
        $this->assertSame(
            ['Marine gas oil, 500 litres'],
            PdfBlocks::split('Marine gas oil, 500 litres')
        );
    }
}
