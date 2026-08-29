<?php

namespace justinholtweb\donky\records;

use craft\db\ActiveRecord;
use justinholtweb\donky\db\Table;

/**
 * @property int $id
 * @property int $typeId
 * @property string $periodKey
 * @property int $nextNumber
 */
class CounterRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::COUNTERS;
    }
}
