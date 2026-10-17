{{--
    Styling for an item description cut into blocks by App\Support\PdfBlocks so
    that a page can break inside it.

    The blocks must read as one cell: only the first row pads above, only the
    last pads below, and no rule is drawn between them. Get this wrong and
    every paragraph of a description grows its own divider and its own gap.

    !important because each template styles its own `tbody td`, with a selector
    more specific than anything that can be written here without knowing what
    the table is called in that template.
--}}
tr.block-open > td {
    border-bottom: none !important;
    padding-bottom: 0 !important;
}

tr.block-cont > td {
    padding-top: 0 !important;
}
