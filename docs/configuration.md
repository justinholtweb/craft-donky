---
title: Configuration
slug: configuration
order: 20
summary: Plugin settings, document types, numbering formats, designs and attachment rules.
---

## Plugin settings

**Settings → Plugins → Donky.**

| Setting | Default | |
| --- | --- | --- |
| Issue documents automatically | on | The master switch for every document type's trigger. Off leaves every document a manual act, which is how you take Donky live on an established store without a surprise run of numbers. |
| Attach documents to Commerce emails | on | The master switch for attachment rules. |
| If a document cannot be attached | send the email without it | The other option holds the email back. |
| Let customers download their documents | on | Turns on signed links for types marked available to customers. |
| Link lifetime | 86400 | Seconds a signed customer link stays valid. |
| Bulk print limit | 100 | The most orders one bulk print may cover. |
| Default filename | `{{ type.handle }}-{{ document.formattedNumber ?: order.shortNumber }}` | An object template, used when a type has no filename of its own. |
| Allow remote images | off | Lets dompdf fetch images over the network. Off is the safe default. |
| Lock timeout | 5 | Seconds to wait for another request to finish with a counter. |
| Log renders | off | Writes every issue and render to the logs. |

### Filenames

Filename templates are object templates and receive `document`, `type`, `order`, `number` and
`date`. `number` is always a string and never null, which makes it the safe one to build on:

```twig
{{ type.handle }}-{{ number }}
invoices/{{ date|date('Y-m') }}/{{ number }}
```

A filename template that throws does not stop a document from being issued — the number is used
instead and the failure is logged.

### Remote images

dompdf will only fetch an image over the network when this is on. Leave it off and give the
Designer a logo on a **local** volume: Donky then hands dompdf a filesystem path, which always
works and costs no network round trip inside a render.

Turning it on means every render of a template containing a remote image makes an outbound HTTP
request, and a URL that comes from content is an SSRF waiting to happen.

## Document types

**Donky → Document types.**

| Field | |
| --- | --- |
| Kind | What the document *is*: whether it is numbered, whether it belongs to one order, whether the money runs the other way. |
| Design | The look. |
| Template | A site template. Empty means the bundled template for the kind. |
| Number source | Donky's own sequence, the order reference, the short order number, or none. |
| Format | With `{number}` and the date tokens. |
| Start / increment / pad / reset | The sequence itself. |
| Issue this document | Only when asked, when the order completes, when it is paid, or when it reaches a status. |
| Payment terms | Days after issue to print as a due date. 0 means no due date. |
| Filename | Overrides the site default. |
| Available to customers | Allows a signed download link. |
| Only for orders matching | Pro. An order condition, checked when a document is issued automatically. |

### Format tokens

`{number}` (padded), `{rawNumber}`, `{yyyy}`, `{yy}`, `{mm}`, `{dd}`, `{typeHandle}`,
`{storeHandle}`, `{orderNumber}`, `{orderReference}`.

A type on its own sequence must contain `{number}`, or every document would be named the same.

### Resets

`Never` keeps one counter. `Every year`, `Every month` and `Every day` keep one per period, so
each period starts again at the start number. The period a document falls in is decided by its
issue date, which is why a backfill dates its documents by the order and not by the clock.

## Designs

**Donky → Designer.** A design carries the page setup, the type, the colours, the logo, the issuer
block, the block toggles, the terms, the footer and any custom CSS.

There is always exactly one default design, enforced on save: a document type with no design of
its own falls back to it, and a second default would silently change what those documents look
like.

The issuer block is on the design rather than in the settings so that a second brand or a second
store can be a second issuer.

## Attachment rules

**Donky → Attachment rules.** Each rule names a document type, an email (or every email), and on
Pro an order status and an order condition.

Rules are evaluated when Commerce sends the email. The order history that triggered the send is
what the status is matched against, so a rule for "Shipped" fires on the email that the shipped
status sent — not on every email that happens to go out while the order is shipped.

`Issue the document if it does not exist yet` is on by default. Turn it off for a credit note: you
do not want one conjured out of nothing by an email.
