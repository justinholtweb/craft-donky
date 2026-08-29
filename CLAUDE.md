# Donky — Craft CMS 5 Plugin

## Project Overview

Donky is the document suite for Craft Commerce: invoices, packing slips, pick lists and credit
notes, template-driven, with sequential numbering, bulk printing and per-status attachment rules.
Distributed as `justinholtweb/craft-donky`. **Lite is free; Pro is $99 with a $49/year renewal.**

Modelled on WooCommerce PDF Invoices & Packing Slips, with one deliberate difference: no layout
builder. Every document is a Twig template meant to be copied out and owned.

## Tech Stack

- **PHP 8.2+**, **Craft CMS 5.3+**, **Craft Commerce 5.0+**, Yii2, Twig
- dompdf, which Commerce already requires — a `suggest`, never a `require`
- No build step, no asset bundles; the only JS is inline in CP templates

## Architecture

### Namespace & package

- Namespace: `justinholtweb\donky`
- Package: `justinholtweb/craft-donky`
- Handle: `donky`

### The five tables

- `{{%donky_designs}}` — the look, plus the issuer block. Nested settings are JSON columns
  (`margins`, `typography`, `colors`, `seller`, `blocks`) rather than thirty columns.
- `{{%donky_documenttypes}}` — a row of the suite: kind, design, template, numbering, trigger,
  order condition. Store-scoped.
- `{{%donky_counters}}` — **one row per type per reset period**, which is what makes "reset every
  month" mean anything.
- `{{%donky_documents}}` — issued documents, each with its frozen `snapshot` (mediumText: a
  hundred-line order with options overflows `text`, and a truncated snapshot is a corrupt invoice).
- `{{%donky_rules}}` — attachment rules.

All in the database rather than project config, like Commerce's own shipping rules — they carry
sequence state, and a project-config deploy that renumbered a live store would be very bad.

### The invariants

- `services\Numbers::allocate()` is the **only** place a number is handed out, under a mutex named
  `donky:type:<id>`. `pick()` is public so allocation and the CP preview cannot disagree.
- Unique index on `(typeId, periodKey, number)`.
- Counters never rewind. Voiding keeps the number; deleting keeps it spent. `forceNextNumber()` is
  the single deliberate exception.
- `Documents::ensure()` is what every automatic path calls, so asking twice never issues twice.

### The snapshot

`Documents::buildSnapshot()` freezes line items, addresses, adjustments, totals and payments onto
the document. The bundled templates read `document.snapshot`; the live `order` is still passed in.
This is the difference between an invoice and a report.

`Documents::SNAPSHOT_VERSION` is stamped on every one, so an old snapshot is identifiable.

### Rendering

`Renderer::renderHtml()` builds one HTML document — inlined stylesheet from the design, one
`.dk-doc` per document with a page break between — and `htmlToPdf()` runs it through dompdf once.

**Merging is done in HTML, not in PDF.** No merging library, no second engine, continuous page
numbers. The trade is that one bundle shares one page setup, from the first document's design.

The stylesheet is inlined rather than registered as an asset bundle: calling `head()` in a print
route invites every other installed plugin to inject its chrome into the PDF.

A type with a `templatePath` renders that **site** template (with `setIsCpRequest(false)` around
it, as Commerce does); a type without one renders the bundled **CP** template for its kind. A
template failure — missing file included — renders an error block rather than throwing, because
the same code path runs inside a checkout email.

### Attachment rules fire on the email

`Emails::EVENT_BEFORE_SEND_MAIL` gives us `craftEmail`, `commerceEmail`, `order` and
`orderHistory`. Matching there rather than on `OrderHistories::EVENT_ORDER_STATUS_CHANGE` means
the rule fires exactly when something is being sent, while `orderHistory->newStatusId` still gives
us status matching.

### Element-index actions

One `PrintDocuments` action per usable document type, registered on
`Element::EVENT_REGISTER_ACTIONS` for `Order`. The action never runs server-side: `getTriggerHtml()`
posts the selection to `donky/bulk/print` in a throwaway form, which is the only way an element
action can hand the browser a file.

## Traps found while building this

- **Craft's default element-action trigger id is the class name alone**
  (`sprintf('%s-actiontrigger', static::class)`), so N instances of one action class all point at
  the same DOM element and only the last one fires. Override `getTriggerId()` *and* pass
  `triggerId` to `Craft.ElementActionTrigger` — the JS reads `settings.triggerId ?? type +
  '-actiontrigger'`, so passing a doctored `type` does not work.
- **Element actions are configured with `type`, not `class`.** `Craft::createObject($config)`
  throws "Object configuration must be an array containing a class element"; the right call is
  `Craft::$app->getElements()->createAction($config)`.
- **`LineItem` has `getTax()`, not `getAdjustmentsTotalByType()`** — that one is private.
  `getTaxIncluded()` is the included half.
- **Commerce's `OrderStatusEvent` has typed, non-nullable `order` and `orderHistory`.** A guard
  written as `if (!$event->orderHistory)` is a fatal, not a null check. Use `isset()`.
- **`$order->message` is the status-change message**, not a customer note. Commerce 5 has no
  checkout note field of its own.
- **`Craft::parseEnv()` is gone in Craft 5** — `craft\helpers\App::parseEnv()`.
- **dompdf refuses to read a local image outside its chroot**, which is how a perfectly correct
  logo path renders as nothing at all. Set `setChroot()` to `@webroot`, `@storage` and `@root`.
- **`Plugin::getInstance()` can be null in an ad-hoc console script** even though
  `Craft::$app->getPlugins()->getPlugin('donky')` returns the object. Use the latter in scripts.
- **A literal CP route segment must be registered before the numeric one.**
  `donky/documents/download` falls through to Craft's template routing and 404s unless it has its
  own rule, because it is not a document id.
- **Lightswitches only post when they are on**, so a block list has to be rebuilt from its own
  defaults on save rather than merged — otherwise nothing can ever be switched off.
- **Craft's colour field posts hex without the leading `#`.** Normalise on the way in or every
  colour saved through the CP is rejected and silently falls back to the default.

See also `[[craft-plugin-gotchas]]` in the shared memory for family-wide traps.

## Editions

Lite: one invoice + one packing slip (the seeded pair), one design, own numbering with formats and
resets, one attachment rule (status and condition ignored), customer links, single-order PDFs.

Pro: credit notes, pick lists, proforma, custom kinds, more than one type per kind, several
designs, bulk printing, all rules with status and condition matching, backfill, CSV export.

The gates live in the **services and controllers**, not in the models: a downgrade keeps every row
readable and reversible. `DocumentTypes::isLicensed()` is the single question.

## Testing

No local PHP on this Mac. Everything runs inside the plugin-testing container:

```sh
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-donky/tests/integration/checks.php   # 64 checks
docker exec ddev-plugin-testing-web bash -c 'find /var/www/craft-donky/src -name "*.php" -print0 | xargs -0 -n1 php -l'
```

Use `docker exec`, not `ddev exec` — see the harness memory.

The checks switch to Pro and restore the original edition in a `finally`, and turn `autoIssue` and
`attachToEmails` **off** for the whole run so a trigger firing behind their back cannot make the
counts lie. They mutate the in-memory settings model and restore it, which works because settings
are memoized per request.

**Fixture types must not share a number format.** `prefix($handle)` exists for that: types share
one namespace of formatted numbers, so a shared format makes one fixture's numbers collide with
the next one's.

Editions live in project config, so `switchEdition()` from a console script is undone by the next
`craft up`. For CP testing, run `switchEdition()` **and** `ProjectConfig::flush()` — the latter is
what writes the yaml; `saveModifiedConfigData()` alone leaves the change in the database only.

## Coding conventions

- `Craft::t('donky', '…')` for user-facing strings; `src/translations/en/donky.php` lists them
- Business logic in services; controllers stay thin
- Never nest a `<form>` in a CP template — post secondary actions with `Craft.sendActionRequest`
- Never mark plugin settings `required`
- Nothing in the Twig API issues a document or spends a number
- Guard every snapshot read in a template with `??` — devMode turns on `strict_variables`
