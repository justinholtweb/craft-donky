<?php

namespace justinholtweb\donky\records;

use craft\db\ActiveRecord;
use justinholtweb\donky\db\Table;

/**
 * @property int $id
 * @property int $typeId
 * @property int|null $orderId
 * @property int|null $storeId
 * @property string $kind
 * @property int|null $number
 * @property string $periodKey
 * @property string|null $formattedNumber
 * @property string|null $fileName
 * @property string $status
 * @property string $source
 * @property int|null $sourceDocumentId
 * @property string|null $dateIssued
 * @property string|null $dateDue
 * @property string|null $currency
 * @property float $total
 * @property string|null $snapshot
 * @property string|null $orderIds
 * @property string|null $notes
 * @property int|null $issuedByUserId
 */
class DocumentRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::DOCUMENTS;
    }
}
