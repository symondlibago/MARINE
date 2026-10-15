{{--
    The footer's styling, defined once and included inside every document's
    <style> block.

    It travels with pdf.partials.footer and the two are useless apart: the
    bottom page margin reserves the strip the footer is then positioned into.
    Set them separately and a document either prints its footer over the last
    line of the table, or leaves a band of white space where no footer is.

    That is how they drifted. Quotations reserved 100px, invoices 104px, and
    delivery orders, purchase orders, final invoices and return notes reserved
    nothing at all and carried no footer — which is what Dru saw.
--}}
@page { margin: 28px 34px 104px 34px; }

.page-footer {
    position: fixed;
    bottom: -86px;
    left: 34px;
    right: 34px;
    text-align: center;
    font-size: 9px;
    color: #555;
    line-height: 1.5;
}

.page-footer .rule { border-top: 1px solid #ddd; padding-top: 6px; }

/* Named rather than relying on .navy, which not every template defines. */
.page-footer .co { font-size: 10px; font-weight: bold; color: #28364b; }
