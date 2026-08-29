<?php

namespace justinholtweb\donky\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use justinholtweb\donky\db\Table;
use justinholtweb\donky\models\Design;
use justinholtweb\donky\records\DesignRecord;

/**
 * Designs — the look of the documents.
 */
class Designs extends Component
{
    /** @var Design[]|null */
    private ?array $_designs = null;

    /**
     * @return Design[]
     */
    public function getAllDesigns(): array
    {
        if ($this->_designs !== null) {
            return $this->_designs;
        }

        $rows = (new Query())
            ->select('*')
            ->from(Table::DESIGNS)
            ->orderBy(['isDefault' => SORT_DESC, 'name' => SORT_ASC])
            ->all();

        $designs = [];

        foreach ($rows as $row) {
            $design = $this->_toModel($row);
            $designs[$design->id] = $design;
        }

        return $this->_designs = $designs;
    }

    public function getDesignById(?int $id): ?Design
    {
        if (!$id) {
            return null;
        }

        return $this->getAllDesigns()[$id] ?? null;
    }

    public function getDesignByHandle(string $handle): ?Design
    {
        foreach ($this->getAllDesigns() as $design) {
            if ($design->handle === $handle) {
                return $design;
            }
        }

        return null;
    }

    /**
     * The default design. There is always one: a document with no design is a document that cannot
     * be rendered, so an install that has somehow lost its default gets a fresh one in memory
     * rather than a fatal in the middle of a checkout email.
     */
    public function getDefaultDesign(): Design
    {
        foreach ($this->getAllDesigns() as $design) {
            if ($design->isDefault) {
                return $design;
            }
        }

        $first = reset($this->_designs);

        if ($first instanceof Design) {
            return $first;
        }

        return new Design([
            'name' => Craft::t('donky', 'Default'),
            'handle' => 'default',
            'isDefault' => true,
        ]);
    }

    public function saveDesign(Design $design, bool $runValidation = true): bool
    {
        if ($runValidation && !$design->validate()) {
            return false;
        }

        $record = $design->id ? DesignRecord::findOne($design->id) : null;

        if ($record === null) {
            $record = new DesignRecord();
            $record->uid = StringHelper::UUID();
        }

        $record->name = $design->name;
        $record->handle = $design->handle;
        $record->isDefault = $design->isDefault;
        $record->paperSize = $design->paperSize;
        $record->paperOrientation = $design->paperOrientation;
        $record->margins = Json::encode($design->margins);
        $record->typography = Json::encode($design->typography);
        $record->colors = Json::encode($design->colors);
        $record->logoAssetId = $design->logoAssetId ?: null;
        $record->logoHeight = $design->logoHeight;
        $record->seller = Json::encode($design->seller);
        $record->blocks = Json::encode($design->blocks);
        $record->customCss = $design->customCss;

        if (!$record->save()) {
            $design->addErrors($record->getErrors());
            return false;
        }

        $design->id = $record->id;
        $design->uid = $record->uid;

        // Exactly one default, always — enforced here rather than by a partial index, because
        // MySQL has none and a second default silently changes what every unassigned type renders.
        if ($design->isDefault) {
            Db::update(Table::DESIGNS, ['isDefault' => false], ['not', ['id' => $design->id]], [], true);
        } elseif (!$this->_anyDefaultExists()) {
            Db::update(Table::DESIGNS, ['isDefault' => true], ['id' => $design->id], [], true);
            $design->isDefault = true;
        }

        $this->_designs = null;

        return true;
    }

    public function deleteDesignById(int $id): bool
    {
        $design = $this->getDesignById($id);

        if (!$design) {
            return false;
        }

        // Losing the default would leave every type that relies on it rendering nothing.
        if ($design->isDefault && count($this->getAllDesigns()) > 1) {
            throw new \yii\base\InvalidArgumentException(Craft::t('donky', 'Make another design the default before deleting this one.'));
        }

        Db::delete(Table::DESIGNS, ['id' => $id]);

        $this->_designs = null;

        return true;
    }

    /**
     * A copy, ready to be edited into something else. The commonest way anyone makes a second
     * design is by starting from the first one.
     */
    public function duplicateDesign(Design $design): Design
    {
        $copy = new Design($design->toArray([
            'paperSize', 'paperOrientation', 'margins', 'typography', 'colors',
            'logoAssetId', 'logoHeight', 'seller', 'blocks', 'customCss',
        ]));

        $copy->name = Craft::t('donky', '{name} copy', ['name' => $design->name]);
        $copy->handle = $this->_uniqueHandle($design->handle);
        $copy->isDefault = false;

        return $copy;
    }

    private function _uniqueHandle(string $base): string
    {
        $handle = $base . 'Copy';
        $i = 1;

        while ($this->getDesignByHandle($handle) !== null) {
            $handle = $base . 'Copy' . (++$i);
        }

        return $handle;
    }

    private function _anyDefaultExists(): bool
    {
        return (new Query())->from(Table::DESIGNS)->where(['isDefault' => true])->exists();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function _toModel(array $row): Design
    {
        foreach (['margins', 'typography', 'colors', 'seller', 'blocks'] as $key) {
            $decoded = $row[$key] ? Json::decodeIfJson($row[$key]) : [];
            $row[$key] = is_array($decoded) ? $decoded : [];
        }

        $row['isDefault'] = (bool)$row['isDefault'];
        $row['logoHeight'] = (int)$row['logoHeight'];
        $row['logoAssetId'] = $row['logoAssetId'] !== null ? (int)$row['logoAssetId'] : null;
        $row['id'] = (int)$row['id'];

        unset($row['dateCreated'], $row['dateUpdated']);

        return new Design($row);
    }
}
