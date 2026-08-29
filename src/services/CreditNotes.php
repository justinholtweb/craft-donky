<?php

namespace justinholtweb\donky\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use justinholtweb\donky\errors\DocumentException;
use justinholtweb\donky\helpers\Kind;
use justinholtweb\donky\models\Document;
use justinholtweb\donky\models\DocumentType;
use justinholtweb\donky\Plugin;

/**
 * Credit notes.
 *
 * Commerce refunds money through a gateway; a credit note is the paperwork that says why. The two
 * are deliberately not welded together — a merchant can credit a customer without a gateway refund
 * (goodwill, a corrected invoice) and can refund without issuing a credit note. What Donky does do
 * is offer the refunded transactions as a starting point, so the commonest case is two clicks.
 */
class CreditNotes extends Component
{
    /**
     * What is available to credit on an order: each invoiced line, less anything already credited.
     *
     * @return array<int, array<string, mixed>>
     */
    public function creditableLines(Order $order): array
    {
        $credited = $this->creditedQuantities($order);
        $lines = [];

        foreach ($order->getLineItems() as $item) {
            $already = $credited[$item->id] ?? 0;

            $lines[] = [
                'lineItemId' => (int)$item->id,
                'description' => $item->getDescription(),
                'sku' => $item->getSku(),
                'qty' => (int)$item->qty,
                'credited' => $already,
                'remaining' => max(0, (int)$item->qty - $already),
                'unitPrice' => (float)$item->salePrice,
                'total' => (float)$item->getTotal(),
            ];
        }

        return $lines;
    }

    /**
     * How much of each line has already been credited by an issued credit note.
     *
     * @return array<int, int>
     */
    public function creditedQuantities(Order $order): array
    {
        $out = [];

        foreach (Plugin::getInstance()->getDocuments()->getDocumentsForOrder((int)$order->id, null, false) as $document) {
            if (!Kind::isCredit($document->kind)) {
                continue;
            }

            foreach (($document->snapshot['credit']['lines'] ?? []) as $line) {
                $id = (int)($line['lineItemId'] ?? 0);

                if ($id) {
                    $out[$id] = ($out[$id] ?? 0) + (int)($line['qty'] ?? 0);
                }
            }
        }

        return $out;
    }

    /**
     * The refunds Commerce has recorded against an order, for the "credit what was refunded"
     * shortcut in the CP.
     */
    public function refundedTotal(Order $order): float
    {
        $total = 0.0;

        foreach ($order->getTransactions() as $transaction) {
            if ($transaction->type === 'refund' && $transaction->status === 'success') {
                $total += (float)$transaction->amount;
            }
        }

        return $total;
    }

    /**
     * Issue a credit note.
     *
     * @param array<int, int> $quantities lineItemId => quantity to credit
     * @param array<string, mixed> $extras `shipping`, `adjustment`, `reason`, `sourceDocumentId`
     * @throws DocumentException
     */
    public function issue(DocumentType $type, Order $order, array $quantities = [], array $extras = []): Document
    {
        if (!Kind::isCredit($type->kind)) {
            throw new DocumentException(Craft::t('donky', '“{name}” is not a credit note type.', ['name' => $type->name]));
        }

        $available = [];

        foreach ($this->creditableLines($order) as $line) {
            $available[$line['lineItemId']] = $line;
        }

        $lines = [];
        $subtotal = 0.0;
        $tax = 0.0;

        foreach ($quantities as $lineItemId => $qty) {
            $lineItemId = (int)$lineItemId;
            $qty = (int)$qty;

            if ($qty <= 0 || !isset($available[$lineItemId])) {
                continue;
            }

            $line = $available[$lineItemId];

            if ($qty > $line['remaining']) {
                throw new DocumentException(Craft::t('donky', 'Only {n} of “{description}” is left to credit.', [
                    'n' => $line['remaining'],
                    'description' => $line['description'],
                ]));
            }

            // Credit the line at what it was actually billed at, per unit, so a discounted line
            // credits back the discounted money and not the list price.
            $unit = $line['qty'] > 0 ? $line['total'] / $line['qty'] : 0.0;
            $amount = round($unit * $qty, 4);

            $lines[] = [
                'lineItemId' => $lineItemId,
                'description' => $line['description'],
                'sku' => $line['sku'],
                'qty' => $qty,
                'unitPrice' => round($unit, 4),
                'amount' => $amount,
            ];

            $subtotal += $amount;
        }

        $shipping = round((float)($extras['shipping'] ?? 0), 4);
        $adjustment = round((float)($extras['adjustment'] ?? 0), 4);
        $total = round($subtotal + $shipping + $adjustment, 4);

        if ($total <= 0 && !$lines) {
            throw new DocumentException(Craft::t('donky', 'A credit note needs something to credit.'));
        }

        $invoice = null;

        if (!empty($extras['sourceDocumentId'])) {
            $invoice = Plugin::getInstance()->getDocuments()->getDocumentById((int)$extras['sourceDocumentId']);
        } else {
            $invoiceType = Plugin::getInstance()->getDocumentTypes()->getPrimaryTypeOfKind(Kind::INVOICE, $order->storeId);
            $invoice = $invoiceType ? Plugin::getInstance()->getDocuments()->getDocumentForOrder($invoiceType, $order) : null;
        }

        return Plugin::getInstance()->getDocuments()->issue($type, $order, [
            'source' => $extras['source'] ?? Document::SOURCE_MANUAL,
            'sourceDocumentId' => $invoice?->id,
            'notes' => $extras['reason'] ?? null,
            'snapshot' => [
                'credit' => [
                    'reason' => $extras['reason'] ?? null,
                    'lines' => $lines,
                    'shipping' => $shipping,
                    'adjustment' => $adjustment,
                    'subtotal' => round($subtotal, 4),
                    'total' => $total,
                    'invoiceNumber' => $invoice?->formattedNumber,
                    'invoiceDate' => $invoice?->dateIssued?->format(DATE_ATOM),
                ],
                'totals' => [
                    'total' => $total,
                ],
            ],
        ]);
    }
}
