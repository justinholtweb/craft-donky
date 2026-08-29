# FAQ

**Why is nothing being issued?**
Three switches, in order: the plugin setting *Issue documents automatically*, the document type's
*Issue this document* trigger, and — on Pro — the type's order condition. A freshly installed type
is set to *only when asked* on purpose.

**Can invoice numbers match order numbers?**
Yes. Set the type's number source to **The order reference**. With
[Abacus](https://github.com/justinholtweb/craft-abacus) installed that reference is itself a
sequential order number, so the two match exactly. Without Abacus it is Commerce's cart-hash
reference, which is unique but not sequential.

**Why does Donky have its own numbering at all, then?**
Because in several jurisdictions an invoice sequence must be gapless and independent of orders —
carts that never complete must not consume invoice numbers. Donky numbers at *issue* time, not at
checkout, which is what makes that possible.

**Are there gaps?**
Only where you make them. A number is spent when a document is issued, and voiding or deleting a
document does not return it. That is deliberate: reissuing a number that may already be on a
printed invoice is worse than a gap.

**Can I change a document after it is issued?**
No, and that is the point. Reissue instead: the old document is voided, keeps its number, and a
new one is issued with the next. Both stay in the ledger, which is what an auditor wants to see.

**Can two documents get the same number?**
No. Allocation happens in one place under a mutex, and the database carries a unique index on
`(typeId, periodKey, number)`.

**Does the PDF change if the order changes?**
No. Everything the template needs is frozen into the document when it is issued. Editing an order
afterwards does not rewrite the invoice you already sent.

**Can I show prices on a packing slip?**
The bundled packing slip leaves them off, because quoting prices on the paperwork inside a parcel
is the classic gift and customs complaint. Copy the template and pass `showPrices: true` to the
lines partial if you want them.

**What about several PDFs in one email?**
Add one rule per document for the same email. Each attaches separately.

**Why did an email go out without its invoice?**
By default a document that cannot be built is logged and the email is sent anyway. Check the logs
for `donky`, and set *If a document cannot be attached* to **Hold the email back** if you would
rather it did not go at all.

**How many orders can I bulk print at once?**
`bulkPrintLimit`, 100 by default. The bundle is a single render held whole in memory, so the real
limit is your PHP memory limit. Raise both together.

**Can I get a ZIP of separate PDFs instead of one merged file?**
No. Merging in HTML is what lets Donky produce one file with continuous page numbering and no
PDF-merging dependency. If you need them separate, print them separately or use the console.

**Which fonts can I use?**
The ones dompdf ships: Helvetica, Times, Courier and DejaVu Sans. DejaVu has the widest character
coverage, so pick it for Cyrillic, Greek or heavy accenting. A font that has to be downloaded and
cached at render time is a font that renders as boxes on the first server that cannot write to its
cache directory.

**Can I use flexbox in a template?**
No. dompdf implements CSS 2.1 and a little CSS 3. Use tables and floats.

**Does a credit note refund the money?**
No. Commerce refunds through the gateway; the credit note is the paperwork. They are deliberately
separate, so you can credit without refunding and refund without crediting.

**Can customers see their invoices?**
Mark the type *available to customers* and use `craft.donky.customerDocuments(order)`. Links are
signed and expiring; a signed-in customer can also fetch their own order's documents without one.

**Does Donky touch Commerce's own PDF settings?**
No. Commerce's PDF templates and its "Download PDF" button keep working exactly as they did.
