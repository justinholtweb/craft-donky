<?php

namespace justinholtweb\donky\models;

use Craft;
use craft\base\Model;
use craft\commerce\elements\Order;
use craft\helpers\ArrayHelper;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use DateTime;
use justinholtweb\donky\helpers\Kind;
use justinholtweb\donky\Plugin;

/**
 * One issued document.
 *
 * A document is a *record*, not a view. Everything it says about the order is frozen into
 * `snapshot` at the moment it is issued, because an invoice that quietly restates itself every
 * time it is reprinted is not an invoice — it is a report. The live order is still handed to the
 * template as `order`, but the bundled templates read the snapshot.
 */
class Document extends Model
{
    public const STATUS_ISSUED = 'issued';
    public const STATUS_VOID = 'void';

    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_AUTOMATIC = 'automatic';
    public const SOURCE_EMAIL = 'email';
    public const SOURCE_BULK = 'bulk';
    public const SOURCE_CONSOLE = 'console';

    public ?int $id = null;
    public ?int $typeId = null;
    public ?int $orderId = null;
    public ?int $storeId = null;
    public string $kind = Kind::INVOICE;

    /** @var int|null The raw sequence number, when Donky counted it. */
    public ?int $number = null;

    public string $periodKey = '';

    /** @var string|null What people actually call it: `INV-2026-00142`. */
    public ?string $formattedNumber = null;

    public ?string $fileName = null;
    public string $status = self::STATUS_ISSUED;
    public string $source = self::SOURCE_MANUAL;

    /** @var int|null The invoice a credit note credits. */
    public ?int $sourceDocumentId = null;

    public ?DateTime $dateIssued = null;
    public ?DateTime $dateDue = null;
    public ?string $currency = null;
    public float $total = 0.0;

    /** @var array<string, mixed> The frozen order data. */
    public array $snapshot = [];

    /** @var int[] The orders an aggregate document covers. */
    public array $orderIds = [];

    public ?string $notes = null;
    public ?int $issuedByUserId = null;

    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    private ?Order $_order = null;
    private ?DocumentType $_type = null;

    /**
     * @return array<string, string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_ISSUED => Craft::t('donky', 'Issued'),
            self::STATUS_VOID => Craft::t('donky', 'Void'),
        ];
    }

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        return [
            [['typeId', 'kind'], 'required'],
            [['status'], 'in', 'range' => array_keys(self::statuses())],
            [['typeId', 'orderId', 'storeId', 'number', 'sourceDocumentId', 'issuedByUserId', 'id'], 'integer'],
        ];
    }

    public function getType(): ?DocumentType
    {
        if ($this->_type !== null) {
            return $this->_type;
        }

        return $this->_type = $this->typeId
            ? Plugin::getInstance()->getDocumentTypes()->getTypeById($this->typeId)
            : null;
    }

    public function setType(DocumentType $type): void
    {
        $this->_type = $type;
        $this->typeId = $type->id;
    }

    public function getOrder(): ?Order
    {
        if ($this->_order !== null) {
            return $this->_order;
        }

        if (!$this->orderId) {
            return null;
        }

        return $this->_order = Order::find()->id($this->orderId)->status(null)->one();
    }

    public function setOrder(?Order $order): void
    {
        $this->_order = $order;
        $this->orderId = $order?->id;
    }

    /**
     * The orders an aggregate document covers, in the order they were selected.
     *
     * @return Order[]
     */
    public function getOrders(): array
    {
        if (!$this->orderIds) {
            $order = $this->getOrder();

            return $order ? [$order] : [];
        }

        $orders = Order::find()->id($this->orderIds)->status(null)->limit(null)->all();
        $byId = ArrayHelper::index($orders, 'id');
        $out = [];

        foreach ($this->orderIds as $id) {
            if (isset($byId[$id])) {
                $out[] = $byId[$id];
            }
        }

        return $out;
    }

    public function getLabel(): string
    {
        return $this->formattedNumber ?: ($this->getType()->name ?? Kind::label($this->kind));
    }

    public function getKindLabel(): string
    {
        return Kind::label($this->kind);
    }

    public function getStatusLabel(): string
    {
        return self::statuses()[$this->status] ?? $this->status;
    }

    public function isVoid(): bool
    {
        return $this->status === self::STATUS_VOID;
    }

    /**
     * Read a dotted path out of the snapshot: `document.get('totals.total')`.
     */
    public function get(string $path, mixed $default = null): mixed
    {
        return ArrayHelper::getValue($this->snapshot, $path, $default);
    }

    /**
     * Format a number as money in the document's own currency, which is the currency the order was
     * placed in and not whatever the site happens to be set to today.
     */
    public function money(mixed $amount, array $options = []): string
    {
        $currency = $this->currency ?: 'USD';

        return Craft::$app->getFormatter()->asCurrency((float)$amount, $currency, $options, [], true);
    }

    /**
     * The download filename, extension included.
     */
    public function getDownloadFileName(): string
    {
        return ($this->fileName ?: 'document') . '.pdf';
    }

    public function getCpUrl(): string
    {
        return UrlHelper::cpUrl('donky/documents/' . $this->id);
    }

    /**
     * The control-panel PDF link. Permission-checked at the other end.
     */
    public function getDownloadUrl(array $params = []): string
    {
        return UrlHelper::cpUrl('donky/documents/download', array_merge(['id' => $this->id], $params));
    }

    public function getPreviewUrl(): string
    {
        return UrlHelper::cpUrl('donky/documents/preview', ['id' => $this->id]);
    }

    /**
     * A signed front-end URL a customer can use, valid for the configured window.
     */
    public function getPublicUrl(): ?string
    {
        return Plugin::getInstance()->getDocuments()->getPublicUrl($this);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArrayForLedger(): array
    {
        return [
            'id' => $this->id,
            'uid' => $this->uid,
            'kind' => $this->kind,
            'number' => $this->formattedNumber,
            'order' => $this->getOrder()->reference ?? $this->getOrder()?->getShortNumber(),
            'status' => $this->status,
            'total' => $this->total,
            'currency' => $this->currency,
            'issued' => $this->dateIssued?->format(DATE_ATOM),
        ];
    }

    public function encodeSnapshot(): string
    {
        return Json::encode($this->snapshot, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE);
    }
}
