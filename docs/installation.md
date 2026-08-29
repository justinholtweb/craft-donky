# Installation

## Requirements

- Craft CMS 5.3 or later
- Craft Commerce 5.0 or later
- PHP 8.2 or later

PDF rendering uses dompdf. Craft Commerce already requires it, so Donky does not add a dependency
of its own — it is listed as a `suggest` rather than a `require`, because every published dompdf
release carries a security advisory and Composer's audit policy blocks flagged packages by
default. A hard requirement would make the plugin uninstallable on sites with blocking turned on.

If Commerce ever stops shipping it, `composer require dompdf/dompdf` puts it back and Donky picks
it up.

## Installing

```sh
composer require justinholtweb/craft-donky
php craft plugin/install donky
```

Or find Donky in the Plugin Store.

## What installing does

- Creates five tables: designs, document types, counters, documents and attachment rules.
- Seeds one **Default** design.
- Seeds an **Invoice** and a **Packing slip** per store, both set to *only when asked*.

Nothing is issued automatically until you choose a trigger on a document type. Adding a document
suite to a live store must never start numbering checkouts by itself.

## Editions

Lite is free. Pro is $99 with a $49/year renewal, and adds:

- credit notes, pick lists, proforma invoices and custom kinds
- more than one document type per kind
- more than one design
- bulk printing from the order index
- attachment rules matched by order status and by an order condition, and more than one of them
- backfilling documents for orders that predate the plugin
- the document ledger's CSV export

Downgrading never deletes anything. A Pro document type stays in the table, stops issuing, and
starts again when the licence does.

## Uninstalling

`php craft plugin/uninstall donky` drops Donky's five tables. The documents go with them — they
are records Donky owns, not Commerce data. Export the ledger to CSV first if you need it.
