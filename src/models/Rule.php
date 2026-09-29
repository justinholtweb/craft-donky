<?php

namespace justinholtweb\donky\models;

use Craft;
use craft\base\Model;
use craft\commerce\Plugin as Commerce;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use DateTime;
use justinholtweb\donky\elements\conditions\orders\DonkyOrderCondition;
use justinholtweb\donky\Plugin;

/**
 * An attachment rule: which document rides along with which email, for which order status.
 *
 * Rules are matched at send time rather than at status-change time, because the thing a customer
 * actually receives is the email — an order can reach "Shipped" through the CP, a webhook, a
 * console command or a bulk edit, and only one of those is a moment worth hanging a PDF on.
 */
class Rule extends Model
{
    public ?int $id = null;
    public ?int $storeId = null;
    public ?string $name = null;
    public bool $enabled = true;
    public ?int $sortOrder = null;
    public ?int $typeId = null;

    /** @var int|null The Commerce email this rule attaches to. Null means every email. */
    public ?int $emailId = null;

    /** @var int|null The order status whose change sends the email. Null means every status. */
    public ?int $orderStatusId = null;

    /**
     * @var bool Whether a missing document is issued on the spot. Off means the rule only attaches
     *           documents that already exist, which is what you want for a credit note.
     */
    public bool $issueIfMissing = true;

    public ?string $orderCondition = null;

    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    private ?DonkyOrderCondition $_condition = null;

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        return [
            [['name', 'typeId', 'storeId'], 'required'],
            [['name'], 'string', 'max' => 255],
            [['enabled', 'issueIfMissing'], 'boolean'],
            [['typeId', 'emailId', 'orderStatusId', 'sortOrder', 'id'], 'integer'],
        ];
    }

    public function getType(): ?DocumentType
    {
        return $this->typeId ? Plugin::getInstance()->getDocumentTypes()->getTypeById($this->typeId) : null;
    }

    public function getEmail(): ?\craft\commerce\models\Email
    {
        if (!$this->emailId || !Plugin::commerceIsReady()) {
            return null;
        }

        return Commerce::getInstance()->getEmails()->getEmailById($this->emailId, $this->storeId);
    }

    public function getOrderStatus(): ?\craft\commerce\models\OrderStatus
    {
        if (!$this->orderStatusId || !Plugin::commerceIsReady()) {
            return null;
        }

        return Commerce::getInstance()->getOrderStatuses()->getOrderStatusById($this->orderStatusId);
    }

    public function getEmailLabel(): string
    {
        return $this->getEmail()->name ?? Craft::t('donky', 'Any email');
    }

    public function getStatusLabel(): string
    {
        return $this->getOrderStatus()->name ?? Craft::t('donky', 'Any status');
    }

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
        return UrlHelper::cpUrl('donky/rules/' . $this->id);
    }
}
