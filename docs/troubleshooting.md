---
title: Troubleshooting
slug: troubleshooting
order: 40
summary: Blank PDFs, missing logos, numbers that never arrived, and emails that went out bare.
---

## The PDF is blank, or the logo is missing

dompdf will not read a file outside its chroot, and it will not fetch a URL unless *Allow remote
images* is on.

- Put the logo on a **local** volume. Donky then hands dompdf a filesystem path.
- If the logo lives on S3 or another remote filesystem, turn *Allow remote images* on and make
  sure the server can actually reach the URL.
- Check `storage/logs/` for `donky`.

## A document says "This document could not be rendered"

The template threw, and Donky put the message on the page rather than letting an exception escape
into a checkout email. The whole stack trace is in `storage/logs/`.

The commonest causes are a mistyped **Template** path on the document type, a partial that was
copied without the folder around it, and reading a snapshot key that does not exist. In dev mode
Twig runs with `strict_variables`, so guard every snapshot read with `??`.

## "dompdf is not installed"

Commerce normally provides it. If a Composer audit policy stripped it out:

```sh
composer require dompdf/dompdf
```

## Characters come out as boxes or hashes

The font does not have those characters. Switch the design's font to **DejaVu Sans**, which has
much wider coverage than Helvetica.

## The wrong document number

- **Numbers jumped.** Something was issued and then voided or deleted. Numbers are never reused.
  The ledger shows what happened to each one.
- **Numbers restarted.** The type resets yearly, monthly or daily, and a new period began.
- **The number is the order's.** The type's number source is *The order reference* or *The short
  order number* rather than Donky's own sequence.
- **A backfill filed documents under last year.** That is on purpose: a backfill dates each
  document when the order happened, so a yearly sequence stays in the right year.

## An email went out with no attachment

Check, in order:

1. *Attach documents to Commerce emails* in the settings.
2. The rule is enabled, and on Lite that it is the **first** enabled rule — Lite runs one.
3. The rule's email matches the one Commerce sent.
4. On Pro, the rule's order status matches the status change that sent the email, and its order
   condition matches the order.
5. `storage/logs/` for a `donky` entry saying why the document could not be built.

## Bulk printing does nothing, or the tab is blank

- Bulk printing is a Pro feature; on Lite the actions are not registered at all.
- The user needs the *Print in bulk* permission.
- Over the limit, the tab shows a page explaining that instead of a PDF.
- A large selection can exhaust PHP's memory. Lower `bulkPrintLimit` or raise `memory_limit`.

## Two print actions, only one works

Fixed in 5.0.0 by giving each action its own trigger id. If you have subclassed
`PrintDocuments`, keep the `getTriggerId()` override: Craft's default is the class name alone, so
several instances of one action class all point at the same trigger element and only the last one
ever fires.

## A document type will not save

- The handle is taken by another type in the same store.
- The format has no `{number}` and the type is on its own sequence.
- The trigger is *When the order reaches a status* and no status was chosen.
- The kind is a Pro kind on a Lite licence.

## A design will not delete

The default design cannot be deleted while others exist. Make another one the default first.

## Everything is fine on the console and broken on the web, or vice versa

The two run different PHP configurations. A render that works from `donky/documents/render` and
fails in the browser is usually a `memory_limit` difference, and one that fails on the console is
usually a missing font cache directory. Both are named in the log entry.
