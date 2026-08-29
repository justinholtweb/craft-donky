# Donky — design notes

The decisions that shaped the plugin, and why they went the way they did.

## Template-driven, not builder-driven

WooCommerce's document plugins ship a layout builder that emits markup the merchant cannot read
and the developer cannot maintain. Donky goes the other way: every document is a Twig template
that lives in the plugin, is meant to be copied out, and stays readable.

The Designer is not a builder. It produces the **stylesheet and the content toggles** the
templates consume, so a merchant can restyle everything without Twig, and a developer who writes
their own template still gets the merchant's logo, colours and issuer block for free. Neither
audience has to take the other's tooling.

## A document is a record, not a view

The single most important design decision. When a document is issued, everything the template
needs is frozen into `snapshot`.

An invoice that re-derives itself from the live order is a report: edit the order, refund a line,
correct an address, and last quarter's invoice quietly changes. Freezing makes a reprint a
reprint. The live order is still passed to the template for anything the merchant genuinely wants
current.

## Numbering is Abacus's model, at a different moment

The counter-plus-mutex-plus-unique-index shape is lifted from
[Abacus](https://github.com/justinholtweb/craft-abacus), which does the same job for order
references. One counter row per type per reset period; `allocate()` is the only place a number is
handed out; `pick()` is public so the CP preview and the allocator cannot disagree.

The difference is *when*. Abacus numbers at checkout. Donky numbers at **issue**, which is what
makes a gapless invoice sequence possible: abandoned carts never consume an invoice number.

And because the two are complementary rather than competing, a Donky type can simply carry
`order.reference` — which is Abacus's sequential number when Abacus is installed. That is the
right answer for most shops, and Donky's own sequence is there for the ones with a legal reason to
keep the two apart.

## Never rewind

A counter refuses to go backwards; voiding keeps the number; deleting keeps it spent. The
alternative is reissuing a number that may already be printed on somebody's invoice, which is
worse than any gap. `forceNextNumber()` exists for the console, and it is the only door.

## Merging in HTML, not in PDF

A bulk print is one HTML document with `page-break-after` between the parts, rendered once. The
alternatives were a PDF-merging library (another dependency, another engine to keep in step) or a
zip of separate files (no continuous page numbers, and a worse thing to hand a warehouse).

The cost is that one bundle shares one page setup, taken from the first document's design. In
practice a bulk print is one document type at a time, because the element-index actions are
registered per type.

## Rules fire on the email, not on the status

An order reaches "Shipped" from the control panel, a webhook, a console command, a bulk edit, an
import. Only one of those is a moment a customer sees. Hanging attachments on the *email* means
the rule fires exactly when something is being sent, and the order history that triggered the send
tells us which status change it was — so status matching is still available without inventing a
second, invisible trigger.

## Failure never blocks the customer

Issuing a document cannot fail a checkout or a status change; attaching one cannot, by default,
stop an email. A template error renders an error block on the page rather than throwing. An order
confirmation that arrives without its invoice is a nuisance; one that never arrives is a support
ticket. Merchants who disagree have one setting.

## Credit notes are paperwork

Commerce refunds money through a gateway. A credit note records what was credited and why. Welding
them together would be wrong in both directions — goodwill credits with no refund, and gateway
refunds that need no document. Donky offers Commerce's recorded refunds as a starting figure and
otherwise stays out of it.

Lines credit at what they were **billed** per unit, not at list price, and already-credited
quantities are tracked so a line cannot be credited twice.

## A pick list is about a shelf

Every other document in the suite is about an order. A pick list is about a batch: line items
collapsed by the thing a picker actually reaches for, so ten orders wanting one blue medium shirt
each is one row saying 10. It is stored as a document with no `orderId` and a list of `orderIds`,
which is why `Kind::isAggregate()` exists.

## Database, not project config

Document types, designs and rules live in tables. They carry sequence state and are store-scoped
operational data, the same call Commerce makes for its shipping rules — and a project-config
deploy that renumbered a live store would be a very bad afternoon.

## Editions

Lite is a working document suite: an invoice, a packing slip, real numbering, one design, one
attachment rule, customer links. It is not a demo.

Pro is what a busy warehouse and a picky accountant need: credit notes, pick lists, more of
everything, bulk printing, and matching by status and condition.

Downgrading never deletes. A Pro type stops issuing and keeps its rows, so the licence lapsing is
recoverable rather than destructive.

## Things deliberately left out

- **No PDF merging library.** See above.
- **No WYSIWYG layout editor.** See above.
- **No outbound requests.** Remote images are off by default, and nothing else in the plugin talks
  to the network.
- **No second PDF engine.** dompdf is what Commerce ships; a template that renders in one engine
  and not another is a support burden with no upside.
- **No e-invoicing formats.** Factur-X, ZUGFeRD, PEPPOL and friends are a real need and a real
  project. The snapshot is the right foundation for them, which is why it is structured data
  rather than HTML.
