<?php

namespace App\Support;

/**
 * Where a long item description is allowed to break across pages.
 *
 * Dru: "so much space and nasayang sa page 1 — I need to cut some lines para
 * niya ma utilize ang first page." A towage quotation carries one line item
 * whose description runs to twenty-odd lines of terms, and dompdf will only
 * ever break a table between rows, never inside one (FrameDecorator\Page,
 * "table-row: break allowed"). One tall row is therefore atomic: if it does
 * not fit in what is left of the page it moves to the next one whole, and the
 * page it left behind keeps nothing but a column header and half a page of
 * white.
 *
 * So the description is cut into blocks and each block gets its own row. The
 * rows carry no border and no padding between them, so an item still reads as
 * one block of text — the seams are invisible until the page runs out, which
 * is the one moment we want them.
 */
class PdfBlocks
{
    /**
     * Lines per block where a paragraph has no blank lines to cut at.
     *
     * Low enough that a block is always far shorter than a page, so there is
     * somewhere to break wherever the text happens to fall.
     */
    private const MAX_LINES = 10;

    /**
     * Cut a description into blocks, preferring blank lines.
     *
     * A paragraph break is kept at the head of the block that follows it, so
     * the spacing between paragraphs survives the move into a separate row and
     * the rendered text is identical to what one tall cell produced.
     *
     * @return string[] one or more blocks; [] for an empty description
     */
    public static function split(?string $text): array
    {
        // One line ending from here down. A description typed on Windows
        // arrives with CRLF, and counting those breaks as two would put an
        // extra blank line between every paragraph it was cut at.
        $text = rtrim(str_replace(["\r\n", "\r"], "\n", (string) $text));
        if (trim($text) === '') {
            return [];
        }

        // Keep the separators: a run of blank lines belongs to the next block.
        $parts = preg_split('/(\n[ \t]*(?:\n[ \t]*)+)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        $blocks = [];
        $lead = '';
        foreach ($parts as $i => $part) {
            if ($i % 2 === 1) {
                // A separator. One of its newlines ends the block before it;
                // the rest are the blank lines the reader already sees, so
                // they ride at the head of the block that follows.
                $lead = substr(str_replace([' ', "\t"], '', $part), 1);

                continue;
            }
            if (trim($part) === '') {
                continue;
            }
            foreach (self::chop($part) as $n => $chunk) {
                $blocks[] = ($n === 0 ? $lead : '').$chunk;
                $lead = '';
            }
        }

        return $blocks;
    }

    /**
     * Break a single paragraph that is long enough to fill a page on its own.
     *
     * Nothing marks where it should go, so it goes every MAX_LINES lines. The
     * cut shows only if the page happens to end there, and a page has to end
     * somewhere.
     *
     * @return string[]
     */
    private static function chop(string $paragraph): array
    {
        $lines = explode("\n", $paragraph);
        if (count($lines) <= self::MAX_LINES) {
            return [$paragraph];
        }

        return array_map(
            fn (array $chunk) => implode("\n", $chunk),
            array_chunk($lines, self::MAX_LINES)
        );
    }
}
