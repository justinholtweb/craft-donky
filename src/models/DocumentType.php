<?php

namespace justinholtweb\donky\models;

use Craft;
use craft\base\Model;
use craft\commerce\Plugin as Commerce;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use DateTime;
use justinholtweb\donky\elements\conditions\orders\DonkyOrderCondition;
use justinholtweb\donky\helpers\Kind;
use justinholtweb\donky\Plugin;

/**
 * A document type — one row in the suite. Invoice, packing slip, credit note, pick list, or
 * whatever a merchant invents.
 */
class DocumentType extends Model
{
    /** Donky counts, under its own lock. */
    public const SOURCE_OWN = 'own';

    /** Reuse `order.reference`, which is where Abacus puts its sequential order number. */
    public const SOURCE_ORDER_REFERENCE = 'order-reference';

    /** Reuse Commerce's short order number. */
    public const SOURCE_ORDER_NUMBER = 'order-number';

    /** No number at all — a packing slip is identified by the order it belongs to. */
    public const SOURCE_NONE = 'none';

    public const RESET_NEVER = 'never';
    public const RESET_YEARLY = 'yearly';
    public const RESET_MONTHLY = 'monthly';
    public const RESET_DAILY = 'daily';

    public const TRIGGER_MANUAL = 'manual';
    public const TRIGGER_COMPLETE = 'complete';
    public const TRIGGER_PAID = 'paid';
    public const TRIGGER_STATUS = 'status';

    public ?int $id = null;
    public ?int $storeId = null;
    public ?string $name = null;
    public ?string $handle = null;
    public string $kind = Kind::INVOICE;
    public bool $enabled = true;
    public ?int $sortOrder = null;
    public ?int $designId = null;

    /**
     * @var string|null A site template that renders this type. Empty means the bundled template
     *                  for the kind, which is the whole point of "template-driven": the default is
     *                  a real Twig file you can copy out and own.
     */
    public ?string $templatePath = null;

    public string $numberSource = self::SOURCE_OWN;
    public string $format = 'INV-{yyyy}-{number}';
    public int $startNumber = 1;
    public int $increment = 1;
    public int $padLength = 4;
    public string $resetPolicy = self::RESET_NEVER;

    /** @var string|null An object template for the download filename, without the extension. */
    public ?string $filenameFormat = null;

    public string $issueTrigger = self::TRIGGER_MANUAL;
    public ?int $issueStatusId = null;

    /** @var int Days added to the issue date to get a due date. Zero means no due date. */
    public int $dueDays = 0;

    /** @var bool Whether a customer may download this document for their own order. */
    public bool $availableToCustomer = false;

    /** @var string|null A serialised order condition, Pro only. */
    public ?string $orderCondition = null;

    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    private ?DonkyOrderCondition $_condition = null;

    /**
     * @return array<string, string>
     */
    public static function numberSources(): array
    {
        return [
            self::SOURCE_OWN => Craft::t('donky', 'Donky’s own sequence'),
            self::SOURCE_ORDER_REFERENCE => Craft::t('donky', 'The order reference'),
            self::SOURCE_ORDER_NUMBER => Craft::t('donky', 'The short order number'),
            self::SOURCE_NONE => Craft::t('donky', 'No number'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function resetPolicies(): array
    {
        return [
            self::RESET_NEVER => Craft::t('donky', 'Never'),
            self::RESET_YEARLY => Craft::t('donky', 'Every year'),
            self::RESET_MONTHLY => Craft::t('donky', 'Every month'),
            self::RESET_DAILY => Craft::t('donky', 'Every day'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function issueTriggers(): array
    {
        return [
            self::TRIGGER_MANUAL => Craft::t('donky', 'Only when asked'),
            self::TRIGGER_COMPLETE => Craft::t('donky', 'When the order completes'),
            self::TRIGGER_PAID => Craft::t('donky', 'When the order is paid in full'),
            self::TRIGGER_STATUS => Craft::t('donky', 'When the order reaches a status'),
        ];
    }

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        return [
            [['name', 'handle', 'kind', 'storeId'], 'required'],
            [['name', 'handle', 'format'], 'string', 'max' => 255],
            [['handle'], 'match', 'pattern' => '/^[a-zA-Z][a-zA-Z0-9_\-]*$/', 'message' => Craft::t('donky', 'Handles must start with a letter and contain only letters, numbers, hyphens and underscores.')],
            [['kind'], 'in', 'range' => array_keys(Kind::labels())],
            [['numberSource'], 'in', 'range' => array_keys(self::numberSources())],
            [['resetPolicy'], 'in', 'range' => array_keys(self::resetPolicies())],
            [['issueTrigger'], 'in', 'range' => array_keys(self::issueTriggers())],
            [['enabled', 'availableToCustomer'], 'boolean'],
            [['startNumber'], 'integer', 'min' => 0],
            [['increment'], 'integer', 'min' => 1, 'max' => 1000],
            [['padLength'], 'integer', 'min' => 0, 'max' => 20],
            [['dueDays'], 'integer', 'min' => 0, 'max' => 3650],
            [['designId', 'issueStatusId', 'sortOrder', 'id'], 'integer'],
            [['format'], 'validateFormat', 'skipOnEmpty' => false],
            [['issueStatusId'], 'validateIssueStatus'],
            [['templatePath'], 'string', 'max' => 500],
        ];
    }

    /**
     * A numbered type whose format has no `{number}` token would hand every document the same
     * name, which reads as a numbering bug months later when two invoices collide.
     */
    public function validateFormat(string $attribute): void
    {
        if ($this->numberSource !== self::SOURCE_OWN) {
            return;
        }

        if (!str_contains((string)$this->format, '{number}')) {
            $this->addError($attribute, Craft::t('donky', 'The format must contain {number}, or every document would be named the same.'));
        }
    }

    public function validateIssueStatus(string $attribute): void
    {
        if ($this->issueTrigger === self::TRIGGER_STATUS && !$this->issueStatusId) {
            $this->addError($attribute, Craft::t('donky', 'Choose the status that issues this document.'));
        }
    }

    public function isNumbered(): bool
    {
        return $this->numberSource !== self::SOURCE_NONE;
    }

    public function usesOwnCounter(): bool
    {
        return $this->numberSource === self::SOURCE_OWN;
    }

    public function isAggregate(): bool
    {
        return Kind::isAggregate($this->kind);
    }

    public function getKindLabel(): string
    {
        return Kind::label($this->kind);
    }

    public function getIssueTriggerLabel(): string
    {
        if ($this->issueTrigger === self::TRIGGER_STATUS) {
            return Craft::t('donky', 'Status: {name}', ['name' => $this->getIssueStatus()?->name ?? '—']);
        }

        return self::issueTriggers()[$this->issueTrigger] ?? $this->issueTrigger;
    }

    public function getResetPolicyLabel(): string
    {
        return self::resetPolicies()[$this->resetPolicy] ?? $this->resetPolicy;
    }

    public function getNumberSourceLabel(): string
    {
        return self::numberSources()[$this->numberSource] ?? $this->numberSource;
    }

    /**
     * The template that will actually render this type, and whether it is one of ours.
     *
     * @return array{0: string, 1: bool} The template path, and true when it is a site template.
     */
    public function resolveTemplate(): array
    {
        $path = trim((string)$this->templatePath);

        if ($path !== '') {
            return [$path, true];
        }

        return [Kind::defaultTemplate($this->kind), false];
    }

    public function getDesign(): Design
    {
        return Plugin::getInstance()->getDesigns()->getDesignById($this->designId)
            ?? Plugin::getInstance()->getDesigns()->getDefaultDesign();
    }

    public function getStore(): ?\craft\commerce\models\Store
    {
        if (!$this->storeId || !Plugin::commerceIsReady()) {
            return null;
        }

        return Commerce::getInstance()->getStores()->getStoreById($this->storeId);
    }

    public function getIssueStatus(): ?\craft\commerce\models\OrderStatus
    {
        if (!$this->issueStatusId || !Plugin::commerceIsReady()) {
            return null;
        }

        return Commerce::getInstance()->getOrderStatuses()->getOrderStatusById($this->issueStatusId);
    }

    /**
     * The condition an order must match before this type will issue automatically. Pro only; on
     * Lite the stored condition is ignored rather than deleted, so downgrading is reversible.
     */
    public function getCondition(): DonkyOrderCondition
    {
        if ($this->_condition !== null) {
            return $this->_condition;
        }

        $config = $this->orderCondition ? Json::decodeIfJson($this->orderCondition) : [];

        if (!is_array($config)) {
            $config = [];
        }

        $config['class'] = DonkyOrderCondition::class;

        /** @var DonkyOrderCondition $condition */
        $condition = Craft::$app->getConditions()->createCondition($config);
        $condition->mainTag = 'div';
        $condition->name = 'orderCondition';

        return $this->_condition = $condition;
    }

    public function setCondition(DonkyOrderCondition|string|array|null $condition): void
    {
        if ($condition === null || $condition === '') {
            $this->orderCondition = null;
            $this->_condition = null;
            return;
        }

        if (is_string($condition)) {
            $condition = Json::decodeIfJson($condition);
        }

        if (is_array($condition)) {
            $condition['class'] = DonkyOrderCondition::class;
            $condition = Craft::$app->getConditions()->createCondition($condition);
        }

        $this->_condition = $condition;
        $this->orderCondition = Json::encode($condition->getConfig());
    }

    public function hasCondition(): bool
    {
        return !empty($this->getCondition()->getConditionRules());
    }

    public function getCpEditUrl(): string
    {
        return UrlHelper::cpUrl('donky/types/' . $this->id);
    }
}
