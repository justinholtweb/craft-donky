# Release Notes for Donky

## 5.0.0

Initial release.

- Document types of eight kinds: invoice, packing slip, delivery note, proforma, receipt, credit
  note, pick list and custom.
- Sequential numbering with formats, padding, increments and yearly, monthly or daily resets, one
  counter per reset period, allocated under a mutex and backed by a unique index.
- A number source per type: Donky's own sequence, the order reference (which is Abacus's
  sequential order number when Abacus is installed), the short order number, or none.
- The Designer: paper, margins, type, colours, logo, issuer block, block toggles, terms, footer and
  custom CSS, with a live preview against a real order.
- Template-driven rendering. Bundled Twig templates for every kind, copyable into the site's own
  templates, with the design still feeding them.
- A frozen snapshot per document, so a reprint says what the document said.
- Automatic issuing on order completion, on payment, or on a chosen order status.
- Attachment rules: which document rides along with which Commerce email, matched at send time by
  email, order status and an order condition.
- Bulk printing from the order index — one merged PDF per selection, one action per document type.
- Pick lists that collapse line items across a selection of orders by SKU and options.
- Credit notes with per-line quantities, already-credited tracking, shipping and adjustment lines.
- Signed, expiring customer download links, plus ownership-based access for signed-in customers.
- The document ledger with filters and CSV export.
- Console commands to list types, issue, render and backfill.
- `craft.donky` Twig variable; issue and render events.
