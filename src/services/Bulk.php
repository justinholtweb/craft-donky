<?php

namespace justinholtweb\donky\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use craft\helpers\ArrayHelper;
use justinholtweb\donky\errors\DocumentException;
use justinholtweb\donky\helpers\Kind;
use justinholtweb\donky\models\Document;
use justinholtweb\donky\models\DocumentType;
use justinholtweb\donky\Plugin;

/**
 * Bulk printing.
 *
 * One dompdf render of one long HTML document, so a hundred packing slips come out as a single
 * file with continuous page numbers instead of a zip of a hundred PDFs. The whole thing is held in
 * memory while it renders, which is exactly what `bulkPrintLimit` is protecting.
 */
class Bulk extends Component
{
    /**
     * What a bulk print would do, without doing it.
     *
     * @param int[] $orderIds
     * @return array{orders: Order[], existing: int, missing: int, overLimit: bool, limit: int}
     */
    public function plan(array $orderIds, DocumentType $type): array
    {
        $limit = Plugin::getInstance()->getSettings()->bulkPrintLimit;
        $orders = $this->_orders($orderIds);
        $existing = 0;
        $missing = 0;

        foreach ($orders as $order) {
            if (Plugin::getInstance()->getDocuments()->getDocumentForOrder($type, $order)) {
                $existing++;
            } else {
                $missing++;
            }
        }

        return [
            'orders' => $orders,
            'existing' => $existing,
            'missing' => $missing,
            'overLimit' => count($orders) > $limit,
            'limit' => $limit,
        ];
    }

    /**
     * Render one PDF covering every selected order.
     *
     * @param int[] $orderIds
     * @return array{pdf: string, fileName: string, documents: Document[], issued: int, skipped: array<int, string>}
     * @throws DocumentException
     */
    public function print(array $orderIds, DocumentType $type, bool $issueMissing = true): array
    {
        if (!Plugin::getInstance()->isPro()) {
            throw new DocumentException(Craft::t('donky', 'Bulk printing is a Donky Pro feature.'));
        }

        $limit = Plugin::getInstance()->getSettings()->bulkPrintLimit;
        $orderIds = array_values(array_unique(array_map('intval', $orderIds)));

        if (!$orderIds) {
            throw new DocumentException(Craft::t('donky', 'Select some orders first.'));
        }

        if (count($orderIds) > $limit) {
            throw new DocumentException(Craft::t('donky', 'That is {count} orders; the bulk print limit is {limit}. Raise it in the settings if your server has the memory for it.', [
                'count' => count($orderIds),
                'limit' => $limit,
            ]));
        }

        $orders = $this->_orders($orderIds);
        $documentsService = Plugin::getInstance()->getDocuments();

        // A pick list is one document about many orders, not many documents.
        if (Kind::isAggregate($type->kind)) {
            $document = Plugin::getInstance()->getPickLists()->issue($type, $orders, ['source' => Document::SOURCE_BULK]);

            return [
                'pdf' => Plugin::getInstance()->getRenderer()->renderPdf([$document]),
                'fileName' => $document->getDownloadFileName(),
                'documents' => [$document],
                'issued' => 1,
                'skipped' => [],
            ];
        }

        $documents = [];
        $issued = 0;
        $skipped = [];

        foreach ($orders as $order) {
            try {
                $document = $documentsService->getDocumentForOrder($type, $order);

                if ($document === null) {
                    if (!$issueMissing) {
                        $skipped[(int)$order->id] = Craft::t('donky', 'No {name} has been issued.', ['name' => $type->name]);
                        continue;
                    }

                    $document = $documentsService->issue($type, $order, ['source' => Document::SOURCE_BULK]);
                    $issued++;
                }

                $documents[] = $document;
            } catch (\Throwable $e) {
                // One bad order must not lose the other ninety-nine.
                $skipped[(int)$order->id] = $e->getMessage();
                Craft::$app->getErrorHandler()->logException($e);
            }
        }

        if (!$documents) {
            throw new DocumentException(Craft::t('donky', 'Nothing could be printed. {first}', [
                'first' => reset($skipped) ?: '',
            ]));
        }

        return [
            'pdf' => Plugin::getInstance()->getRenderer()->renderPdf($documents),
            'fileName' => $this->fileName($type, count($documents)),
            'documents' => $documents,
            'issued' => $issued,
            'skipped' => $skipped,
        ];
    }

    public function fileName(DocumentType $type, int $count): string
    {
        return sprintf('%s-%d-%s.pdf', $type->handle, $count, (new \DateTime())->format('Ymd-Hi'));
    }

    /**
     * @param int[] $orderIds
     * @return Order[]
     */
    private function _orders(array $orderIds): array
    {
        $orderIds = array_values(array_unique(array_map('intval', $orderIds)));

        if (!$orderIds) {
            return [];
        }

        $orders = Order::find()->id($orderIds)->status(null)->limit(null)->all();
        $byId = ArrayHelper::index($orders, 'id');
        $out = [];

        // Keep the selection order: a pick list that jumps around the warehouse is worse than one
        // that follows the list the picker was handed.
        foreach ($orderIds as $id) {
            if (isset($byId[$id])) {
                $out[] = $byId[$id];
            }
        }

        return $out;
    }
}
