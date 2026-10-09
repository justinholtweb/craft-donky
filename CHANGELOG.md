# Release Notes for Donky

## 5.2.0 - 2026-10-09
### Added

- Console equivalents for every bulk and maintenance action in the control panel, each going through the same service method as the CP: `donky/documents/print` (the order index's bulk print, Pro), `void`, `restore`, `reissue`, `delete` and `export` (the ledger CSV, Pro). Anything that spends a number or removes a row takes `--dry-run`, and every command exits non-zero when it fails.
- `donky/documents/set-next-number`, the console door to a type's counter. It moves a counter forward; moving one backwards needs `--force`.

### Changed

- Reissuing now issues the fresh document before voiding the old one, so a reissue that fails leaves the order with the document it had instead of none.

### Fixed

- The ledger's CSV export was available on Lite, though it is a Pro feature. The *Export CSV* button now only shows on Pro, and the export refuses on Lite.

## 5.1.0 - 2026-09-30

### Security

- **A document type's filename format could read any secret on the site.** It was unrestricted Twig with the order and Craft in reach, so anyone who could manage document types could put `{{ craft.app.config.db.password }}` in it and read it back in every download's filename. Filename formats now run in Craft's sandbox and see plain values — `number`, `date`, `document.formattedNumber`, `type.handle`, `order.number`, `order.shortNumber`, `order.reference` and the like. The default format and ordinary formats keep working; filenames are capped at 150 characters. Requires Craft 5.9.
- **A design's custom CSS could run script in an admin's session.** It was inlined into previews served from the control panel, so `</style><script>` in it executed there. Custom CSS containing `<` is now refused, any already stored is neutralised when rendering, and previews are served with a Content-Security-Policy that allows no script.

### Fixes

- `donky/documents/render` printed an empty document number in its "not found" message.

### Changed

- Requires Craft CMS 5.9 or later.
- PHPStan and ECS configuration.

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
