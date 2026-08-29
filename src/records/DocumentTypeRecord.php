<?php

namespace justinholtweb\donky\records;

use craft\db\ActiveRecord;
use justinholtweb\donky\db\Table;

/**
 * @property int $id
 * @property int $storeId
 * @property string $name
 * @property string $handle
 * @property string $kind
 * @property bool $enabled
 * @property int|null $sortOrder
 * @property int|null $designId
 * @property string|null $templatePath
 * @property string $numberSource
 * @property string $format
 * @property int $startNumber
 * @property int $increment
 * @property int $padLength
 * @property string $resetPolicy
 * @property string|null $filenameFormat
 * @property string $issueTrigger
 * @property int|null $issueStatusId
 * @property int $dueDays
 * @property bool $availableToCustomer
 * @property string|null $orderCondition
 */
class DocumentTypeRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::TYPES;
    }
}
