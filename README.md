# Donky

The document suite for Craft Commerce. Invoices, packing slips, pick lists and credit notes —
numbered properly, styled without touching Twig, printed a hundred at a time, and attached to the
right email at the right moment.

Donky is the Craft answer to *WooCommerce PDF Invoices & Packing Slips*, with one deliberate
difference: **every document is a Twig template.** There is no layout builder producing markup you
cannot read. The bundled templates are real files you can copy into your own `templates/` folder
and own outright, and the Designer keeps feeding them your logo, colours and issuer block even
after you do.

- **Lite is free.** One invoice, one packing slip, one design, sequential numbering, one
  attachment rule, customer download links.
- **Pro is $99, with a $49/year renewal.** Credit notes, pick lists, proforma invoices, any number
  of document types and designs, bulk printing, attachment rules matched by order status and by an
  order condition, backfilling, and the document ledger with CSV export.

## Requirements

Craft CMS 5.3+, Craft Commerce 5.0+, PHP 8.2+.

PDF rendering uses dompdf, which Craft Commerce already requires — Donky does not add a
dependency, and lists it only as a `suggest` so that a Composer audit policy cannot make the
plugin uninstallable.

## Installation

```sh
composer require justinholtweb/craft-donky
php craft plugin/install donky
```

Installing seeds a default design and, per store, an **Invoice** and a **Packing slip** — both set
to *only when asked*. Adding a document suite to a live store must never start stamping invoice
numbers on checkout by itself, so nothing is issued until you say so.

## What a document is

A document is a **record**, not a view. When Donky issues one it freezes everything the template
needs — line items, addresses, adjustments, totals, payments — into a snapshot on the document row.

That is the whole point. An order gets edited, refunded, re-statused and corrected for months
afterwards. An invoice that quietly restates itself every time it is reprinted is not an invoice,
it is a report. The live order is still handed to the template as `order`, and the bundled
templates read `document.snapshot`.

## Numbering

Every document type either counts for itself or borrows the number the order already has.

| Source | What the document is called |
| --- | --- |
| Donky's own sequence | `INV-2026-0142`, from a counter Donky holds a lock on |
| The order reference | whatever `order.reference` is |
| The short order number | Commerce's seven-character order number |
| No number | nothing — a packing slip is identified by its order |

Formats take `{number}`, `{rawNumber}`, `{yyyy}`, `{yy}`, `{mm}`, `{dd}`, `{typeHandle}`,
`{storeHandle}`, `{orderNumber}` and `{orderReference}`. Sequences can reset yearly, monthly or
daily; each reset period keeps a counter of its own, which is what makes "start again in January"
mean anything.

Two promises the code keeps:

- **A number is never handed out twice.** Allocation happens in one place, under a mutex, and the
  database carries a unique index on `(typeId, periodKey, number)`.
- **A counter never rewinds on its own.** Voiding a document keeps its number; deleting one keeps
  it spent. Reissuing a number that may already be on a printed invoice is worse than a gap.

### With Abacus installed

[Abacus](https://github.com/justinholtweb/craft-abacus) replaces Commerce's cart-hash
`order.reference` with a real sequential order number. If you run both, set an invoice type's
number source to **The order reference** and the invoice number *is* the order number — which is
what most merchants mean when they ask for sequential invoice numbers. Donky's own sequence is
there for the shops that need invoice numbers to run independently of orders, which is the law in
several places.

## The Designer

A design is paper size, margins, fonts, colours, logo, the issuer block, and which parts of the
document get drawn — the SKU column, the tax column, the shipping address, the paid stamp, the
terms, the footer. Restyling the whole suite never means editing Twig.

The design also produces the stylesheet, so a template you have written yourself still gets the
merchant's colours for free. Preview any design against a real order from the Designer.

## Templates

Leave a document type's template empty and it renders the bundled template for its kind:

| Kind | Template |
| --- | --- |
| Invoice, proforma, receipt | `donky/_render/invoice` |
| Packing slip, delivery note | `donky/_render/packing-slip` |
| Credit note | `donky/_render/credit-note` |
| Pick list | `donky/_render/pick-list` |

To take one over, copy the plugin's `src/templates/_render/` folder to
`templates/donky/_render/` and point the type at your copy. The partials are included by path, so
copy the whole folder rather than one file.

Every template receives:

| Variable | |
| --- | --- |
| `document` | the `Document` model — `formattedNumber`, `dateIssued`, `dateDue`, `money()`, `notes` |
| `snapshot` | the frozen order data |
| `type` | the document type |
| `design` | the design, including `design.blocks` and `design.sellerLines` |
| `order` | the live order, or `null` on a pick list |
| `orders` | every order an aggregate document covers |

The renderer is dompdf, so the CSS is CSS 2.1 plus a little CSS 3: tables and floats, not flexbox
or grid.

## Attachment rules

A rule says which document rides along with which email, optionally only when the order reaches a
given status and only when the order matches a condition.

Rules are matched when the **email is sent**, not when the status changes. An order can reach
"Shipped" from the control panel, a webhook, a console command or a bulk edit, and only one of
those is a moment the customer actually sees.

If a document cannot be built while an email is going out, the default is to send the email
without it and log why: an order confirmation that arrives with no invoice is a nuisance, and one
that never arrives is a support ticket. The other stance is one setting away.

## Bulk printing

Select orders on the Commerce order index and pick a document from the actions menu. Donky issues
whatever is missing and hands back **one** PDF.

Merging happens in HTML, not in PDF: the bundle is one long HTML document with a page break
between each part, rendered once. No PDF-merging library, no second engine to keep in step, and a
hundred packing slips come out as a single file with continuous page numbers. The trade is that
one bundle shares one page setup, taken from the first document's design.

`bulkPrintLimit` (100 by default) caps the selection, because a bundle is held whole in memory
while it renders.

## Pick lists

A pick list is the one document that is not about an order. It is about a shelf: every line item
across the selected orders, collapsed by the thing you actually reach for. Ten orders each wanting
one blue medium shirt is one row saying `10`, with the orders it covers listed underneath.

## Credit notes

A credit note is paperwork, not a refund. Commerce moves the money through a gateway; the credit
note records what was credited and why. The two are deliberately separate — you can credit a
customer without a gateway refund, and refund without issuing a credit note.

Lines are credited at what they were actually billed, per unit, so a discounted line credits back
the discounted money. Donky tracks how much of each line has already been credited and refuses to
credit more than was sold.

## Customer downloads

Mark a document type *available to customers* and Donky will build a signed, expiring link for it.
In a template:

```twig
{% for document in craft.donky.customerDocuments(order) %}
    <a href="{{ document.publicUrl }}">{{ document.formattedNumber }}</a>
{% endfor %}
```

A logged-in customer can also fetch their own order's documents without a signature. The signature
parameter is `sig`, not `token` — Craft reserves `token` for preview and share links and rejects
an unknown one before any controller runs.

## Twig

```twig
{{ craft.donky.types() }}                    {# every usable document type #}
{{ craft.donky.type('invoice') }}            {# one, by handle #}
{{ craft.donky.documents(order) }}           {# everything issued for an order #}
{{ craft.donky.invoice(order) }}             {# the current invoice, or null #}
{{ craft.donky.customerDocuments(order) }}   {# the ones a customer may download #}
{{ craft.donky.downloadUrl(document) }}      {# a signed link, or null #}
```

Nothing in the Twig API issues a document or spends a number. A template that renders twice would
number twice.

## Console

```sh
php craft donky/documents/types                                   # what is set up
php craft donky/documents/issue --type=invoice --order=ORD-1042    # issue one
php craft donky/documents/issue --type=invoice --order=ORD-1042 --out=/tmp/inv.pdf
php craft donky/documents/render INV-2026-0142 --out=/tmp/inv.pdf  # re-render an existing one
php craft donky/documents/backfill --type=invoice --dryRun         # Pro: what would be issued
php craft donky/documents/backfill --type=invoice --limit=500      # Pro: issue it
```

A backfill runs oldest order first and dates each document when the *order* happened, not when the
backfill ran — otherwise a yearly sequence files last year's invoices under this year's numbers.

## Events

```php
use justinholtweb\donky\services\Documents;
use justinholtweb\donky\services\Renderer;

Event::on(Documents::class, Documents::EVENT_BEFORE_ISSUE_DOCUMENT, function($event) {
    $event->document->snapshot['custom'] = 'anything';   // the snapshot is still writable
});

Event::on(Renderer::class, Renderer::EVENT_BEFORE_RENDER, function($event) {
    $event->pdf = myOwnRenderer($event->html);           // set this and dompdf is never called
});
```

## Permissions

`donky-viewDocuments` (with nested `donky-issueDocuments`, `donky-voidDocuments`,
`donky-bulkPrint`), `donky-manageTypes`, `donky-manageDesigns`, `donky-manageRules`.

## Support

justin@justinholt.com
