<?php

namespace justinholtweb\donky\records;

use craft\db\ActiveRecord;
use justinholtweb\donky\db\Table;

/**
 * @property int $id
 * @property int $storeId
 * @property string $name
 * @property bool $enabled
 * @property int|null $sortOrder
 * @property int $typeId
 * @property int|null $emailId
 * @property int|null $orderStatusId
 * @property bool $issueIfMissing
 * @property string|null $orderCondition
 */
class RuleRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::RULES;
    }
}
