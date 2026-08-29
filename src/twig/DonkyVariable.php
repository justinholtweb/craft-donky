<?php

namespace justinholtweb\donky\twig;

use craft\commerce\elements\Order;
use justinholtweb\donky\helpers\Kind;
use justinholtweb\donky\models\Design;
use justinholtweb\donky\models\Document;
use justinholtweb\donky\models\DocumentType;
use justinholtweb\donky\Plugin;

/**
 * `craft.donky` — read-only. Nothing here issues a document or spends a number: a template that
 * renders twice would number twice.
 */
class DonkyVariable
{
    /**
     * @return DocumentType[]
     */
    public function types(?int $storeId = null): array
    {
        return Plugin::getInstance()->getDocumentTypes()->getUsableTypes($storeId);
    }

    public function type(string $handle, ?int $storeId = null): ?DocumentType
    {
        return Plugin::getInstance()->getDocumentTypes()->getTypeByHandle($handle, $storeId);
    }

    /**
     * @return Design[]
     */
    public function designs(): array
    {
        return Plugin::getInstance()->getDesigns()->getAllDesigns();
    }

    /**
     * Every document issued for an order.
     *
     * @return Document[]
     */
    public function documents(Order|int $order, ?string $typeHandle = null, bool $includeVoid = false): array
    {
        $orderId = $order instanceof Order ? (int)$order->id : (int)$order;
        $typeId = null;

        if ($typeHandle !== null) {
            $storeId = $order instanceof Order ? $order->storeId : null;
            $typeId = Plugin::getInstance()->getDocumentTypes()->getTypeByHandle($typeHandle, $storeId)?->id;

            if ($typeId === null) {
                return [];
            }
        }

        return Plugin::getInstance()->getDocuments()->getDocumentsForOrder($orderId, $typeId, $includeVoid);
    }

    /**
     * The current invoice for an order, if one has been issued.
     */
    public function invoice(Order $order): ?Document
    {
        $type = Plugin::getInstance()->getDocumentTypes()->getPrimaryTypeOfKind(Kind::INVOICE, $order->storeId);

        return $type ? Plugin::getInstance()->getDocuments()->getDocumentForOrder($type, $order) : null;
    }

    /**
     * The documents a customer is allowed to download for an order.
     *
     * @return Document[]
     */
    public function customerDocuments(Order $order): array
    {
        return array_values(array_filter(
            $this->documents($order),
            static fn(Document $document) => $document->getType()?->availableToCustomer && $document->getPublicUrl() !== null
        ));
    }

    public function document(int|string $idOrUid): ?Document
    {
        $documents = Plugin::getInstance()->getDocuments();

        return is_int($idOrUid) || ctype_digit((string)$idOrUid)
            ? $documents->getDocumentById((int)$idOrUid)
            : $documents->getDocumentByUid((string)$idOrUid);
    }

    /**
     * A signed, expiring download link, or null when the type is not available to customers.
     */
    public function downloadUrl(Document $document, ?int $expiry = null): ?string
    {
        return Plugin::getInstance()->getDocuments()->getPublicUrl($document, $expiry);
    }

    public function isPro(): bool
    {
        return Plugin::getInstance()->isPro();
    }
}
