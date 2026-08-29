# Using Donky

## Issuing a document

Open an order in the control panel. Donky's panel sits in the order details with everything issued
for that order and a control to issue another. Issuing spends the next number in that type's
sequence.

Everything else that issues a document goes through the same path:

- a document type's trigger, when the order completes, is paid, or reaches a status
- an attachment rule, as an email goes out
- a bulk print, for the orders that do not have one yet
- `donky/documents/issue` and `donky/documents/backfill` on the console

Asking twice never issues twice: every automatic path calls `ensure()`, which returns the existing
document if there is one.

## Reprinting, voiding, reissuing

**Donky → Documents** is the ledger: every document, filterable by type, kind and status, with a
CSV export.

- **Download** re-renders the PDF from the frozen snapshot. It will say the same thing in a year.
- **Void** marks it void and keeps its number. A voided invoice is still a fact about the past.
- **Reissue** voids the current document and issues a fresh one with the next number, which is the
  honest way to correct an invoice that has already left the building.
- **Delete** removes the row. The number stays spent.

## Bulk printing

On the Commerce order index, select some orders and choose a document from the actions menu.
There is one action per document type — *Print Invoice*, *Print Packing slip*, *Build Pick list* —
and the PDF opens in a new tab.

Donky issues whatever is missing first, so "print packing slips for today's orders" is one gesture.
An order that fails is skipped and logged rather than losing the other ninety-nine.

## Customer downloads

Mark a type *available to customers*, then in a template:

```twig
{% for document in craft.donky.customerDocuments(order) %}
    <a href="{{ document.publicUrl }}">
        {{ document.type.name }} {{ document.formattedNumber }}
    </a>
{% endfor %}
```

`publicUrl` is signed and expires after `linkExpiry` seconds. A signed-in customer can also reach
their own order's documents without a signature.

## Writing your own template

Copy `vendor/justinholtweb/craft-donky/src/templates/_render/` to `templates/donky/_render/`, then
point a document type's **Template** at, say, `donky/_render/invoice`. Copy the whole folder: the
partials are included by full path.

Your template still receives the design, so the merchant keeps control of the logo, the colours,
the issuer block and the block toggles without you doing anything:

```twig
{% set heading = type.name %}

<div class="dk-doc">
    {% include 'donky/_render/partials/_header' %}

    <table class="dk-lines">
        {% for line in snapshot.lineItems %}
            <tr>
                <td>{{ line.description }}</td>
                <td class="dk-qty">{{ line.qty }}</td>
                <td class="dk-num">{{ document.money(line.total) }}</td>
            </tr>
        {% endfor %}
    </table>

    {% include 'donky/_render/partials/_totals' %}
</div>
```

Read `snapshot`, not `order`, for anything that should not move. `document.money()` formats in the
currency the order was placed in, which is not necessarily the site's current one.

Because the renderer is dompdf, lay out with tables and floats. Flexbox and grid do nothing.

## The snapshot

```
version, kind, issuedAt, dueAt, number, typeName, currency
seller            { name, address, taxId, registration, email, phone, website }
order             { id, number, shortNumber, reference, email, dateOrdered, datePaid,
                    status, paidStatus, couponCode, note, shippingMethod, gateway, language }
billingAddress    { name, organization, organizationTaxId, lines[], countryCode }
shippingAddress   { … }
lineItems[]       { description, sku, qty, price, salePrice, subtotal, total, tax,
                    taxIncluded, note, options{}, purchasableId }
adjustments       { shipping[], discount[], tax[], other[] }
totals            { itemSubtotal, discount, shipping, tax, taxIncluded, total, paid, outstanding }
payments[]        { type, amount, currency, gateway, reference, date }
credit            { reason, lines[], shipping, adjustment, subtotal, total,
                    invoiceNumber, invoiceDate }        — credit notes only
rows[], orders[], summary                               — pick lists only
```

## Credit notes

From an order, **Credit note** opens a form listing every line with how much of it is still
creditable. Enter quantities, add a shipping or other adjustment, give a reason, and issue.

Lines are credited at what they were billed per unit, so a discounted line credits back the
discounted money. Crediting more than was sold is refused.

The credit note records which invoice it credits, and the invoice's number is printed on it.

## Pick lists

Select orders on the order index and choose the pick list action. One document comes back: every
line item across the selection, collapsed by SKU and options, sorted, with a tick box per row and
the covered orders listed underneath.

Pick lists are not attached to emails and do not belong to an order — they belong to a batch.
