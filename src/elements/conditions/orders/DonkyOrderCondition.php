<?php

namespace justinholtweb\donky\elements\conditions\orders;

use craft\commerce\base\HasStoreInterface;
use craft\commerce\base\StoreTrait;
use craft\commerce\elements\conditions\orders\OrderCondition;
use craft\commerce\elements\Order;
use craft\elements\db\ElementQueryInterface;
use yii\base\NotSupportedException;

/**
 * The condition builder that decides which orders a document type or an attachment rule applies to.
 *
 * Always evaluated against one order that already exists, never used to build a query — an
 * attachment rule runs inside the send of a single email.
 */
class DonkyOrderCondition extends OrderCondition implements HasStoreInterface
{
    use StoreTrait;

    /**
     * @inheritdoc
     */
    public ?string $elementType = Order::class;

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['storeId'], 'safe'];

        return $rules;
    }

    protected function config(): array
    {
        return array_merge(parent::config(), $this->toArray(['storeId']));
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        throw new NotSupportedException('Donky conditions are matched against a single order, not queried.');
    }
}
