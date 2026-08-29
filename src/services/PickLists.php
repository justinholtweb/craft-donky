<?php

namespace justinholtweb\donky\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use justinholtweb\donky\errors\DocumentException;
use justinholtweb\donky\helpers\Kind;
use justinholtweb\donky\models\Document;
use justinholtweb\donky\models\DocumentType;
use justinholtweb\donky\models\PickListRow;
use justinholtweb\donky\Plugin;

/**
 * Pick lists.
 *
 * A pick list is the one document in the suite that is not about an order. It is about a shelf:
 * every line item across a selection of orders, collapsed by the thing you actually reach for, so
 * that ten orders each wanting one blue medium shirt is one row saying "10".
 */
class PickLists extends Component
{
    /**
     * Collapse a set of orders into pickable rows, sorted by SKU.
     *
     * @param Order[] $orders
     * @return PickListRow[]
     */
    public function build(array $orders): array
    {
        /** @var PickListRow[] $rows */
        $rows = [];

        foreach ($orders as $order) {
            $ref = $order->reference ?: $order->getShortNumber();

            foreach ($order->getLineItems() as $item) {
                $options = [];

                foreach ($item->getOptions() as $key => $value) {
                    if (is_scalar($value) || $value === null) {
                        $options[(string)$key] = (string)$value;
                    }
                }

                $row = new PickListRow([
                    'sku' => $item->getSku(),
                    'description' => $item->getDescription(),
                    'quantity' => (int)$item->qty,
                    'options' => $options,
                    'purchasableId' => $item->purchasableId,
                    'orders' => [$ref],
                ]);

                $key = $row->getKey();

                if (isset($rows[$key])) {
                    $rows[$key]->quantity += $row->quantity;

                    if (!in_array($ref, $rows[$key]->orders, true)) {
                        $rows[$key]->orders[] = $ref;
                    }

                    continue;
                }

                $rows[$key] = $row;
            }
        }

        uasort($rows, static fn(PickListRow $a, PickListRow $b) => strnatcasecmp(
            (string)($a->sku ?: $a->description),
            (string)($b->sku ?: $b->description)
        ));

        return array_values($rows);
    }

    /**
     * Issue a pick list document covering a set of orders.
     *
     * @param Order[] $orders
     * @throws DocumentException
     */
    public function issue(DocumentType $type, array $orders, array $options = []): Document
    {
        if (!Kind::isAggregate($type->kind)) {
            throw new DocumentException(Craft::t('donky', '“{name}” is not a pick list.', ['name' => $type->name]));
        }

        $orders = array_values(array_filter($orders));

        if (!$orders) {
            throw new DocumentException(Craft::t('donky', 'Choose at least one order to pick.'));
        }

        $rows = $this->build($orders);
        $totalUnits = array_sum(array_map(static fn(PickListRow $row) => $row->quantity, $rows));

        $snapshot = [
            'rows' => array_map(static fn(PickListRow $row) => [
                'sku' => $row->sku,
                'description' => $row->description,
                'quantity' => $row->quantity,
                'options' => $row->options,
                'orders' => $row->orders,
            ], $rows),
            'orders' => array_map(static fn(Order $order) => [
                'id' => $order->id,
                'reference' => $order->reference ?: $order->getShortNumber(),
                'shortNumber' => $order->getShortNumber(),
                'name' => $order->getShippingAddress()?->fullName ?? $order->getEmail(),
                'status' => $order->getOrderStatus()?->name,
                'items' => count($order->getLineItems()),
            ], $orders),
            'summary' => [
                'orderCount' => count($orders),
                'rowCount' => count($rows),
                'unitCount' => $totalUnits,
            ],
        ];

        return Plugin::getInstance()->getDocuments()->issue($type, null, array_merge($options, [
            'snapshot' => $snapshot,
            'orderIds' => array_map(static fn(Order $order) => (int)$order->id, $orders),
        ]));
    }
}
