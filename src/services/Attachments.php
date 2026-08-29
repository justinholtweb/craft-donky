<?php

namespace justinholtweb\donky\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use craft\commerce\events\MailEvent;
use craft\commerce\models\Email;
use craft\commerce\models\OrderHistory;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use justinholtweb\donky\db\Table;
use justinholtweb\donky\models\Document;
use justinholtweb\donky\models\Rule;
use justinholtweb\donky\Plugin;
use justinholtweb\donky\records\RuleRecord;

/**
 * Attachment rules: which document rides along with which email.
 *
 * Rules are evaluated at *send* time rather than at status-change time. An order can reach
 * "Shipped" from the CP, a webhook, a console command or a bulk edit, and only one of those is a
 * moment a customer sees — so the email is the event worth hanging a PDF on, and the order history
 * that triggered it tells us which status change it was.
 */
class Attachments extends Component
{
    /** @var Rule[]|null */
    private ?array $_rules = null;

    /**
     * @return Rule[]
     */
    public function getAllRules(): array
    {
        if ($this->_rules !== null) {
            return $this->_rules;
        }

        $rows = (new Query())
            ->select('*')
            ->from(Table::RULES)
            ->orderBy(['storeId' => SORT_ASC, 'sortOrder' => SORT_ASC, 'id' => SORT_ASC])
            ->all();

        $rules = [];

        foreach ($rows as $row) {
            $rule = $this->_toModel($row);
            $rules[$rule->id] = $rule;
        }

        return $this->_rules = $rules;
    }

    /**
     * @return Rule[]
     */
    public function getRulesForStore(?int $storeId): array
    {
        return array_filter(
            $this->getAllRules(),
            static fn(Rule $rule) => $storeId === null || $rule->storeId === $storeId
        );
    }

    public function getRuleById(?int $id): ?Rule
    {
        return $id ? ($this->getAllRules()[$id] ?? null) : null;
    }

    /**
     * The rules this install may actually run.
     *
     * Lite attaches one document to one email, and ignores any status or condition the rule
     * carries. The rows are kept exactly as they are, so upgrading turns the whole set back on.
     *
     * @return Rule[]
     */
    public function getActiveRules(?int $storeId): array
    {
        $enabled = array_filter($this->getRulesForStore($storeId), static fn(Rule $rule) => $rule->enabled);

        if (Plugin::getInstance()->isPro()) {
            return $enabled;
        }

        $first = reset($enabled);

        return $first ? [$first->id => $first] : [];
    }

    public function saveRule(Rule $rule, bool $runValidation = true): bool
    {
        if ($runValidation && !$rule->validate()) {
            return false;
        }

        $record = $rule->id ? RuleRecord::findOne($rule->id) : null;

        if ($record === null) {
            $record = new RuleRecord();
            $record->uid = StringHelper::UUID();
            $record->sortOrder = (int)(new Query())->from(Table::RULES)->where(['storeId' => $rule->storeId])->max('sortOrder') + 1;
        }

        $record->storeId = $rule->storeId;
        $record->name = $rule->name;
        $record->enabled = $rule->enabled;
        $record->typeId = $rule->typeId;
        $record->emailId = $rule->emailId ?: null;
        $record->orderStatusId = $rule->orderStatusId ?: null;
        $record->issueIfMissing = $rule->issueIfMissing;
        $record->orderCondition = $rule->orderCondition;

        if ($rule->sortOrder !== null) {
            $record->sortOrder = $rule->sortOrder;
        }

        if (!$record->save()) {
            $rule->addErrors($record->getErrors());
            return false;
        }

        $rule->id = $record->id;
        $rule->uid = $record->uid;
        $rule->sortOrder = $record->sortOrder;

        $this->_rules = null;

        return true;
    }

    public function deleteRuleById(int $id): bool
    {
        Db::delete(Table::RULES, ['id' => $id]);
        $this->_rules = null;

        return true;
    }

    /**
     * @param int[] $ids
     */
    public function reorderRules(array $ids): bool
    {
        foreach ($ids as $index => $id) {
            Db::update(Table::RULES, ['sortOrder' => $index + 1], ['id' => $id], [], false);
        }

        $this->_rules = null;

        return true;
    }

    /**
     * The rules that apply to one email being sent for one order.
     *
     * @return Rule[]
     */
    public function matchRules(Email $email, Order $order, ?OrderHistory $history = null): array
    {
        $isPro = Plugin::getInstance()->isPro();
        $matched = [];

        foreach ($this->getActiveRules($order->storeId) as $rule) {
            if ($rule->emailId !== null && $rule->emailId !== $email->id) {
                continue;
            }

            if ($isPro && $rule->orderStatusId !== null && $rule->orderStatusId !== ($history->newStatusId ?? null)) {
                continue;
            }

            if ($isPro && $rule->hasCondition() && !$rule->getCondition()->matchElement($order)) {
                continue;
            }

            $type = $rule->getType();

            if (!$type || !$type->enabled || $type->isAggregate()) {
                continue;
            }

            if (!Plugin::getInstance()->getDocumentTypes()->isLicensed($type)) {
                continue;
            }

            $matched[] = $rule;
        }

        return $matched;
    }

    /**
     * Attach whatever the rules say to an outgoing Commerce email.
     *
     * A failure here is logged and — by default — ignored: an order confirmation that arrives
     * without its invoice is a nuisance, and an order confirmation that never arrives at all is a
     * support ticket. The other stance is available in the settings for merchants who would rather
     * hold the email back.
     */
    public function handleMailEvent(MailEvent $event): void
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->attachToEmails) {
            return;
        }

        $documents = Plugin::getInstance()->getDocuments();
        $renderer = Plugin::getInstance()->getRenderer();

        foreach ($this->matchRules($event->commerceEmail, $event->order, $event->orderHistory) as $rule) {
            $type = $rule->getType();

            if (!$type) {
                continue;
            }

            try {
                $document = $documents->getDocumentForOrder($type, $event->order);

                if ($document === null) {
                    if (!$rule->issueIfMissing) {
                        continue;
                    }

                    $document = $documents->issue($type, $event->order, ['source' => Document::SOURCE_EMAIL]);
                }

                $event->craftEmail->attachContent($renderer->renderPdf([$document]), [
                    'fileName' => $document->getDownloadFileName(),
                    'contentType' => 'application/pdf',
                ]);
            } catch (\Throwable $e) {
                Craft::error(sprintf(
                    'Donky could not attach “%s” to %s for order %s: %s',
                    $type->name,
                    $event->commerceEmail->name,
                    $event->order->getShortNumber(),
                    $e->getMessage()
                ), 'donky');

                Craft::$app->getErrorHandler()->logException($e);

                if ($settings->onAttachFailure === \justinholtweb\donky\models\Settings::ON_ATTACH_FAILURE_HOLD) {
                    $event->isValid = false;
                    return;
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function _toModel(array $row): Rule
    {
        return new Rule([
            'id' => (int)$row['id'],
            'storeId' => (int)$row['storeId'],
            'name' => $row['name'],
            'enabled' => (bool)$row['enabled'],
            'sortOrder' => $row['sortOrder'] !== null ? (int)$row['sortOrder'] : null,
            'typeId' => (int)$row['typeId'],
            'emailId' => $row['emailId'] !== null ? (int)$row['emailId'] : null,
            'orderStatusId' => $row['orderStatusId'] !== null ? (int)$row['orderStatusId'] : null,
            'issueIfMissing' => (bool)$row['issueIfMissing'],
            'orderCondition' => $row['orderCondition'],
            'uid' => $row['uid'],
        ]);
    }
}
