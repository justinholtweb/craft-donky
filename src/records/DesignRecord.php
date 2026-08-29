<?php

namespace justinholtweb\donky\records;

use craft\db\ActiveRecord;
use justinholtweb\donky\db\Table;

/**
 * @property int $id
 * @property string $name
 * @property string $handle
 * @property bool $isDefault
 * @property string $paperSize
 * @property string $paperOrientation
 * @property string|null $margins
 * @property string|null $typography
 * @property string|null $colors
 * @property int|null $logoAssetId
 * @property int $logoHeight
 * @property string|null $seller
 * @property string|null $blocks
 * @property string|null $customCss
 */
class DesignRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::DESIGNS;
    }
}
