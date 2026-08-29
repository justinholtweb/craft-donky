<?php

namespace justinholtweb\donky\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use craft\commerce\models\OrderAdjustment;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\elements\Address;
use craft\helpers\ArrayHelper;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\FileHelper;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use DateInterval;
use DateTime;
use justinholtweb\donky\db\Table;
use justinholtweb\donky\errors\DocumentException;
use justinholtweb\donky\helpers\Kind;
use justinholtweb\donky\models\Document;
use justinholtweb\donky\models\DocumentType;
use justinholtweb\donky\Plugin;
use justinholtweb\donky\records\DocumentRecord;
use yii\base\Event;

/**
 * Documents — issuing, storing and finding them.
 */
class Documents extends Component
{
    /**
     * @event DocumentEvent Raised before a document is issued, with the snapshot still writable.
     */
    public const EVENT_BEFORE_ISSUE_DOCUMENT = 'beforeIssueDocument';

    /**
     * @event DocumentEvent Raised once a document is saved.
     */
    public const EVENT_AFTER_ISSUE_DOCUMENT = 'afterIssueDocument';

    /** The shape of the snapshot, so a template can tell an old one from a new one. */
    public const SNAPSHOT_VERSION = 1;

    public function getDocumentById(?int $id): ?Document
    {
        if (!$id) {
            return null;
        }

        $row = $this->_query()->where(['id' => $id])->one();

        return $row ? $this->_toModel($row) : null;
    }

    public function getDocumentByUid(string $uid): ?Document
    {
        $row = $this->_query()->where(['uid' => $uid])->one();

        return $row ? $this->_toModel($row) : null;
    }

    public function getDocumentByNumber(string $formattedNumber): ?Document
    {
        $row = $this->_query()->where(['formattedNumber' => $formattedNumber])->one();

        return $row ? $this->_toModel($row) : null;
    }

    /**
     * Every document attached to an order, newest first.
     *
     * @return Document[]
     */
    public function getDocumentsForOrder(int $orderId, ?int $typeId = null, bool $includeVoid = true): array
    {
        $query = $this->_query()->where(['orderId' => $orderId]);

        if ($typeId !== null) {
            $query->andWhere(['typeId' => $typeId]);
        }

        if (!$includeVoid) {
            $query->andWhere(['status' => Document::STATUS_ISSUED]);
        }

        $query->orderBy(['dateIssued' => SORT_DESC, 'id' => SORT_DESC]);

        return array_map($this->_toModel(...), $query->all());
    }

    /**
     * The current document of a type for an order, if there is one.
     */
    public function getDocumentForOrder(DocumentType $type, Order $order): ?Document
    {
        $documents = $this->getDocumentsForOrder((int)$order->id, $type->id, false);

        return $documents[0] ?? null;
    }

    /**
     * The document of a type for an order, issuing it if it is not there yet.
     *
     * This is what every automatic path calls — an email attachment, a status change, a bulk
     * print — so that asking twice never issues twice and never spends a second number.
     */
    public function ensure(DocumentType $type, Order $order, string $source = Document::SOURCE_AUTOMATIC): Document
    {
        return $this->getDocumentForOrder($type, $order) ?? $this->issue($type, $order, ['source' => $source]);
    }

    /**
     * Issue a document.
     *
     * @param array<string, mixed> $options `source`, `notes`, `sourceDocumentId`, `snapshot`
     *                                      (merged over the built one), `issuedAt`.
     * @throws DocumentException
     */
    public function issue(DocumentType $type, ?Order $order, array $options = []): Document
    {
        if (!$type->id) {
            throw new DocumentException(Craft::t('donky', 'The document type has not been saved yet.'));
        }

        if (!Plugin::getInstance()->getDocumentTypes()->isLicensed($type)) {
            throw new DocumentException(Craft::t('donky', '“{name}” is a Donky Pro document type.', ['name' => $type->name]));
        }

        if ($order === null && !$type->isAggregate()) {
            throw new DocumentException(Craft::t('donky', '“{name}” documents belong to an order.', ['name' => $type->name]));
        }

        $issuedAt = $options['issuedAt'] ?? new DateTime();
        $allocation = Plugin::getInstance()->getNumbers()->allocate($type, $order, $issuedAt);

        $document = new Document([
            'typeId' => $type->id,
            'orderId' => $order?->id,
            'storeId' => $type->storeId,
            'kind' => $type->kind,
            'number' => $allocation['number'],
            'periodKey' => $allocation['periodKey'],
            'formattedNumber' => $allocation['formatted'],
            'status' => Document::STATUS_ISSUED,
            'source' => $options['source'] ?? Document::SOURCE_MANUAL,
            'sourceDocumentId' => $options['sourceDocumentId'] ?? null,
            'dateIssued' => $issuedAt,
            'notes' => $options['notes'] ?? null,
            'issuedByUserId' => Craft::$app->getUser()?->getIdentity()?->id,
            'orderIds' => $options['orderIds'] ?? [],
        ]);

        $document->setType($type);
        $document->setOrder($order);

        if ($type->dueDays > 0) {
            $due = (clone $issuedAt);
            $due->add(new DateInterval('P' . $type->dueDays . 'D'));
            $document->dateDue = $due;
        }

        $document->snapshot = array_replace_recursive(
            $this->buildSnapshot($document, $order),
            $options['snapshot'] ?? []
        );

        $document->currency = $order?->currency ?? ($document->snapshot['currency'] ?? null);
        $document->total = (float)($document->snapshot['totals']['total'] ?? 0);

        if (Kind::isCredit($type->kind)) {
            $document->total = -abs($document->total);
        }

        $document->fileName = $this->buildFileName($document);

        $event = new \justinholtweb\donky\events\DocumentEvent(['document' => $document, 'isNew' => true]);
        $this->trigger(self::EVENT_BEFORE_ISSUE_DOCUMENT, $event);

        if (!$event->isValid) {
            throw new DocumentException(Craft::t('donky', 'Issuing “{name}” was cancelled.', ['name' => $type->name]));
        }

        if (!$this->saveDocument($document)) {
            throw new DocumentException(Craft::t('donky', 'Could not save the document: {errors}', [
                'errors' => implode(' ', ArrayHelper::flatten($document->getErrors())),
            ]));
        }

        $this->trigger(self::EVENT_AFTER_ISSUE_DOCUMENT, new \justinholtweb\donky\events\DocumentEvent([
            'document' => $document,
            'isNew' => true,
        ]));

        if (Plugin::getInstance()->getSettings()->logRenders) {
            Craft::info(sprintf(
                'Issued %s %s for order %s',
                $type->kind,
                $document->formattedNumber ?? ('#' . $document->id),
                $order?->getShortNumber() ?? '—'
            ), 'donky');
        }

        return $document;
    }

    public function saveDocument(Document $document, bool $runValidation = true): bool
    {
        if ($runValidation && !$document->validate()) {
            return false;
        }

        $record = $document->id ? DocumentRecord::findOne($document->id) : null;

        if ($record === null) {
            $record = new DocumentRecord();
            $record->uid = StringHelper::UUID();
        }

        $record->typeId = $document->typeId;
        $record->orderId = $document->orderId;
        $record->storeId = $document->storeId;
        $record->kind = $document->kind;
        $record->number = $document->number;
        $record->periodKey = $document->periodKey;
        $record->formattedNumber = $document->formattedNumber;
        $record->fileName = $document->fileName;
        $record->status = $document->status;
        $record->source = $document->source;
        $record->sourceDocumentId = $document->sourceDocumentId;
        $record->dateIssued = Db::prepareDateForDb($document->dateIssued ?? new DateTime());
        $record->dateDue = $document->dateDue ? Db::prepareDateForDb($document->dateDue) : null;
        $record->currency = $document->currency;
        $record->total = $document->total;
        $record->snapshot = $document->encodeSnapshot();
        $record->orderIds = $document->orderIds ? Json::encode(array_values($document->orderIds)) : null;
        $record->notes = $document->notes;
        $record->issuedByUserId = $document->issuedByUserId;

        if (!$record->save()) {
            $document->addErrors($record->getErrors());
            return false;
        }

        $document->id = $record->id;
        $document->uid = $record->uid;

        return true;
    }

    /**
     * Void a document. It keeps its number and its snapshot: a voided invoice is a fact about the
     * past, and the number stays spent so the sequence stays honest.
     */
    public function voidDocument(Document $document, ?string $reason = null): bool
    {
        $document->status = Document::STATUS_VOID;

        if ($reason) {
            $document->notes = trim(($document->notes ? $document->notes . "\n" : '') . $reason);
        }

        return $this->saveDocument($document, false);
    }

    public function restoreDocument(Document $document): bool
    {
        $document->status = Document::STATUS_ISSUED;

        return $this->saveDocument($document, false);
    }

    /**
     * Really delete a document. The number is *not* returned to the counter — see `Numbers`.
     */
    public function deleteDocumentById(int $id): bool
    {
        return (bool)Db::delete(Table::DOCUMENTS, ['id' => $id]);
    }

    /**
     * The ledger query behind the Documents screen and the CSV export.
     *
     * @param array<string, mixed> $criteria
     */
    public function findQuery(array $criteria = []): Query
    {
        $query = $this->_query();

        if (!empty($criteria['typeId'])) {
            $query->andWhere(['typeId' => $criteria['typeId']]);
        }

        if (!empty($criteria['kind'])) {
            $query->andWhere(['kind' => $criteria['kind']]);
        }

        if (!empty($criteria['status'])) {
            $query->andWhere(['status' => $criteria['status']]);
        }

        if (!empty($criteria['storeId'])) {
            $query->andWhere(['storeId' => $criteria['storeId']]);
        }

        if (!empty($criteria['search'])) {
            $query->andWhere(['like', 'formattedNumber', $criteria['search']]);
        }

        if (!empty($criteria['from'])) {
            $query->andWhere(['>=', 'dateIssued', Db::prepareDateForDb($criteria['from'])]);
        }

        if (!empty($criteria['to'])) {
            $query->andWhere(['<=', 'dateIssued', Db::prepareDateForDb($criteria['to'])]);
        }

        return $query->orderBy(['dateIssued' => SORT_DESC, 'id' => SORT_DESC]);
    }

    /**
     * @param array<string, mixed> $criteria
     * @return Document[]
     */
    public function find(array $criteria = [], ?int $limit = null, int $offset = 0): array
    {
        $query = $this->findQuery($criteria);

        if ($limit !== null) {
            $query->limit($limit)->offset($offset);
        }

        return array_map($this->_toModel(...), $query->all());
    }

    /**
     * @param array<string, mixed> $criteria
     */
    public function count(array $criteria = []): int
    {
        return (int)$this->findQuery($criteria)->count();
    }

    /**
     * The download filename, without the extension.
     */
    public function buildFileName(Document $document): string
    {
        $type = $document->getType();
        $order = $document->getOrder();
        $number = $document->formattedNumber
            ?: ($order?->reference ?: $order?->getShortNumber())
            ?: (string)($document->id ?? 'document');

        $format = $type?->filenameFormat ?: Plugin::getInstance()->getSettings()->defaultFilenameFormat;
        $name = $number;

        if ($format) {
            try {
                $name = Craft::$app->getView()->renderObjectTemplate($format, $document, [
                    'document' => $document,
                    'type' => $type,
                    'order' => $order,
                    'number' => $number,
                    'date' => $document->dateIssued ?? new DateTime(),
                ]);
            } catch (\Throwable $e) {
                // A broken filename template must not stop an invoice from existing.
                Craft::warning('Donky filename template failed: ' . $e->getMessage(), 'donky');
                $name = $number;
            }
        }

        $name = FileHelper::sanitizeFilename(trim($name), ['asciiOnly' => false, 'separator' => '-']);

        return $name !== '' ? $name : 'document';
    }

    /**
     * Freeze everything the templates need.
     *
     * The live order is still handed to the template, but the bundled templates read this: an
     * order can be edited, refunded, restatused or have its addresses corrected long after the
     * invoice went out, and the invoice must keep saying what it said.
     *
     * @return array<string, mixed>
     */
    public function buildSnapshot(Document $document, ?Order $order): array
    {
        $type = $document->getType();
        $design = $type?->getDesign();
        $issuedAt = $document->dateIssued ?? new DateTime();

        $snapshot = [
            'version' => self::SNAPSHOT_VERSION,
            'kind' => $document->kind,
            'issuedAt' => $issuedAt->format(DATE_ATOM),
            'dueAt' => $document->dateDue?->format(DATE_ATOM),
            'number' => $document->formattedNumber,
            'typeName' => $type?->name,
            'seller' => $design?->seller ?? [],
            'currency' => $order?->currency,
            'lineItems' => [],
            'adjustments' => ['shipping' => [], 'discount' => [], 'tax' => [], 'other' => []],
            'totals' => [],
            'payments' => [],
        ];

        if ($order === null) {
            return $snapshot;
        }

        $snapshot['order'] = [
            'id' => $order->id,
            'number' => $order->number,
            'shortNumber' => $order->getShortNumber(),
            'reference' => $order->reference,
            'email' => $order->getEmail(),
            'dateOrdered' => $order->dateOrdered?->format(DATE_ATOM),
            'datePaid' => $order->datePaid?->format(DATE_ATOM),
            'status' => $order->getOrderStatus()?->name,
            'paidStatus' => $order->getPaidStatus(),
            'couponCode' => $order->couponCode,
            'note' => $order->message,
            'shippingMethod' => $order->shippingMethodName,
            'gateway' => $this->_gatewayName($order),
            'language' => $order->orderLanguage,
        ];

        $snapshot['billingAddress'] = $this->addressLines($order->getBillingAddress());
        $snapshot['shippingAddress'] = $this->addressLines($order->getShippingAddress());

        foreach ($order->getLineItems() as $item) {
            $options = [];

            foreach ($item->getOptions() as $key => $value) {
                if (is_scalar($value) || $value === null) {
                    $options[(string)$key] = (string)$value;
                }
            }

            $snapshot['lineItems'][] = [
                'id' => $item->id,
                'description' => $item->getDescription(),
                'sku' => $item->getSku(),
                'qty' => (int)$item->qty,
                'price' => (float)$item->price,
                'salePrice' => (float)$item->salePrice,
                'subtotal' => (float)$item->getSubtotal(),
                'total' => (float)$item->getTotal(),
                'tax' => (float)$item->getTax(),
                'taxIncluded' => (float)$item->getTaxIncluded(),
                'note' => $item->note,
                'options' => $options,
                'purchasableId' => $item->purchasableId,
            ];
        }

        /** @var OrderAdjustment $adjustment */
        foreach (($order->getAdjustments() ?? []) as $adjustment) {
            $bucket = match ($adjustment->type) {
                'shipping', 'discount', 'tax' => $adjustment->type,
                default => 'other',
            };

            $snapshot['adjustments'][$bucket][] = [
                'name' => $adjustment->name,
                'description' => $adjustment->description,
                'amount' => (float)$adjustment->amount,
                'included' => (bool)$adjustment->included,
                'lineItemId' => $adjustment->lineItemId,
            ];
        }

        $snapshot['totals'] = [
            'itemSubtotal' => (float)$order->getItemSubtotal(),
            'discount' => (float)$order->getTotalDiscount(),
            'shipping' => (float)$order->getTotalShippingCost(),
            'tax' => (float)$order->getTotalTax(),
            'taxIncluded' => (float)$order->getTotalTaxIncluded(),
            'total' => (float)$order->getTotalPrice(),
            'paid' => (float)$order->getTotalPaid(),
            'outstanding' => (float)$order->getOutstandingBalance(),
        ];

        foreach ($order->getTransactions() as $transaction) {
            if ($transaction->status !== 'success' || !in_array($transaction->type, ['purchase', 'capture', 'refund'], true)) {
                continue;
            }

            $snapshot['payments'][] = [
                'type' => $transaction->type,
                'amount' => (float)$transaction->amount,
                'currency' => $transaction->currency,
                'gateway' => $transaction->getGateway()?->name,
                'reference' => $transaction->reference,
                'date' => $transaction->dateCreated?->format(DATE_ATOM),
            ];
        }

        return $snapshot;
    }

    /**
     * An address as printable lines.
     *
     * @return array<string, mixed>
     */
    public function addressLines(?Address $address): array
    {
        if (!$address) {
            return ['lines' => []];
        }

        $lines = [];

        try {
            $formatted = Craft::$app->getAddresses()->formatAddress($address, ['html' => false]);
            $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $formatted))));
        } catch (\Throwable) {
            foreach ([
                $address->addressLine1,
                $address->addressLine2,
                $address->addressLine3 ?? null,
                trim(($address->locality ?? '') . ' ' . ($address->administrativeArea ?? '') . ' ' . ($address->postalCode ?? '')),
                $address->countryCode,
            ] as $line) {
                $line = trim((string)$line);

                if ($line !== '') {
                    $lines[] = $line;
                }
            }
        }

        return [
            'name' => trim((string)$address->fullName) ?: null,
            'organization' => $address->organization ?: null,
            'organizationTaxId' => $address->organizationTaxId ?: null,
            'lines' => $lines,
            'countryCode' => $address->countryCode,
        ];
    }

    /**
     * A signed front-end URL, or null when customer downloads are off or the type is private.
     */
    public function getPublicUrl(Document $document, ?int $expiry = null): ?string
    {
        $settings = Plugin::getInstance()->getSettings();
        $type = $document->getType();

        if (!$settings->customerDownloads || !$type || !$type->availableToCustomer || !$document->uid) {
            return null;
        }

        $expires = time() + ($expiry ?? $settings->linkExpiry);

        return UrlHelper::siteUrl('donky/document/' . $document->uid, [
            'expires' => $expires,
            'sig' => $this->signature($document->uid, $expires),
        ]);
    }

    /**
     * The signature for a public link. Namespaced away from `token`, which Craft reserves for its
     * own preview and share tokens and rejects before any controller runs.
     */
    public function signature(string $uid, int $expires): string
    {
        return hash_hmac(
            'sha256',
            'donky|' . $uid . '|' . $expires,
            Craft::$app->getConfig()->getGeneral()->securityKey
        );
    }

    public function verifySignature(string $uid, int $expires, string $signature): bool
    {
        if ($expires < time()) {
            return false;
        }

        return hash_equals($this->signature($uid, $expires), $signature);
    }

    private function _gatewayName(Order $order): ?string
    {
        try {
            return $order->getGateway()?->name;
        } catch (\Throwable) {
            return null;
        }
    }

    private function _query(): Query
    {
        return (new Query())->select('*')->from(Table::DOCUMENTS);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function _toModel(array $row): Document
    {
        $snapshot = $row['snapshot'] ? Json::decodeIfJson($row['snapshot']) : [];
        $orderIds = $row['orderIds'] ? Json::decodeIfJson($row['orderIds']) : [];

        return new Document([
            'id' => (int)$row['id'],
            'typeId' => (int)$row['typeId'],
            'orderId' => $row['orderId'] !== null ? (int)$row['orderId'] : null,
            'storeId' => $row['storeId'] !== null ? (int)$row['storeId'] : null,
            'kind' => $row['kind'],
            'number' => $row['number'] !== null ? (int)$row['number'] : null,
            'periodKey' => (string)$row['periodKey'],
            'formattedNumber' => $row['formattedNumber'],
            'fileName' => $row['fileName'],
            'status' => $row['status'],
            'source' => $row['source'],
            'sourceDocumentId' => $row['sourceDocumentId'] !== null ? (int)$row['sourceDocumentId'] : null,
            'dateIssued' => $row['dateIssued'] ? DateTimeHelper::toDateTime($row['dateIssued']) : null,
            'dateDue' => $row['dateDue'] ? DateTimeHelper::toDateTime($row['dateDue']) : null,
            'currency' => $row['currency'],
            'total' => (float)$row['total'],
            'snapshot' => is_array($snapshot) ? $snapshot : [],
            'orderIds' => is_array($orderIds) ? array_map('intval', $orderIds) : [],
            'notes' => $row['notes'],
            'issuedByUserId' => $row['issuedByUserId'] !== null ? (int)$row['issuedByUserId'] : null,
            'uid' => $row['uid'],
        ]);
    }
}
