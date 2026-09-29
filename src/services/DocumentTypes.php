<?php

namespace justinholtweb\donky\services;

use craft\base\Component;
use craft\commerce\elements\Order;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use justinholtweb\donky\db\Table;
use justinholtweb\donky\helpers\Kind;
use justinholtweb\donky\models\DocumentType;
use justinholtweb\donky\Plugin;
use justinholtweb\donky\records\DocumentTypeRecord;

/**
 * Document types — what the suite is made of.
 */
class DocumentTypes extends Component
{
    /** @var DocumentType[]|null */
    private ?array $_types = null;

    /**
     * @return DocumentType[]
     */
    public function getAllTypes(): array
    {
        if ($this->_types !== null) {
            return $this->_types;
        }

        $rows = (new Query())
            ->select('*')
            ->from(Table::TYPES)
            ->orderBy(['storeId' => SORT_ASC, 'sortOrder' => SORT_ASC, 'id' => SORT_ASC])
            ->all();

        $types = [];

        foreach ($rows as $row) {
            $type = $this->_toModel($row);
            $types[$type->id] = $type;
        }

        return $this->_types = $types;
    }

    /**
     * @return DocumentType[]
     */
    public function getTypesForStore(?int $storeId, bool $enabledOnly = false): array
    {
        $out = [];

        foreach ($this->getAllTypes() as $type) {
            if ($storeId !== null && $type->storeId !== $storeId) {
                continue;
            }

            if ($enabledOnly && !$type->enabled) {
                continue;
            }

            $out[$type->id] = $type;
        }

        return $out;
    }

    /**
     * The types this install may actually use. On Lite that is the invoice and the packing slip;
     * a Pro type left behind by a downgrade is kept but never issues anything, so upgrading again
     * restores the suite exactly as it was.
     *
     * @return DocumentType[]
     */
    public function getUsableTypes(?int $storeId = null): array
    {
        $out = [];

        foreach ($this->getTypesForStore($storeId, true) as $type) {
            if ($this->isLicensed($type)) {
                $out[$type->id] = $type;
            }
        }

        return $out;
    }

    public function isLicensed(DocumentType $type): bool
    {
        if (Plugin::getInstance()->isPro()) {
            return true;
        }

        if (Kind::isPro($type->kind)) {
            return false;
        }

        // Lite gets one type per kind — the ones the install seeded.
        $sameKind = array_filter(
            $this->getTypesForStore($type->storeId),
            static fn(DocumentType $t) => $t->kind === $type->kind
        );

        $first = reset($sameKind);

        return $first && $first->id === $type->id;
    }

    public function getTypeById(?int $id): ?DocumentType
    {
        if (!$id) {
            return null;
        }

        return $this->getAllTypes()[$id] ?? null;
    }

    public function getTypeByHandle(string $handle, ?int $storeId = null): ?DocumentType
    {
        foreach ($this->getAllTypes() as $type) {
            if ($type->handle !== $handle) {
                continue;
            }

            if ($storeId !== null && $type->storeId !== $storeId) {
                continue;
            }

            return $type;
        }

        return null;
    }

    /**
     * The first enabled type of a kind for a store — what "the invoice" means when nobody has said
     * which invoice.
     */
    public function getPrimaryTypeOfKind(string $kind, ?int $storeId = null): ?DocumentType
    {
        foreach ($this->getUsableTypes($storeId) as $type) {
            if ($type->kind === $kind) {
                return $type;
            }
        }

        return null;
    }

    /**
     * The types that should issue on a given trigger for an order, in sort order.
     *
     * @return DocumentType[]
     */
    public function getTypesForTrigger(string $trigger, Order $order, ?int $statusId = null): array
    {
        $out = [];

        foreach ($this->getUsableTypes($order->storeId) as $type) {
            if ($type->issueTrigger !== $trigger || $type->isAggregate()) {
                continue;
            }

            if ($trigger === DocumentType::TRIGGER_STATUS && $type->issueStatusId !== $statusId) {
                continue;
            }

            if (!$this->matches($type, $order)) {
                continue;
            }

            $out[] = $type;
        }

        return $out;
    }

    /**
     * Whether a type's order condition matches. A type with no condition matches everything, and
     * on Lite a stored condition is ignored rather than enforced — the alternative is a downgrade
     * that silently stops issuing invoices.
     */
    public function matches(DocumentType $type, Order $order): bool
    {
        if (!$type->hasCondition() || !Plugin::getInstance()->isPro()) {
            return true;
        }

        return $type->getCondition()->matchElement($order);
    }

    public function saveType(DocumentType $type, bool $runValidation = true): bool
    {
        if ($runValidation && !$type->validate()) {
            return false;
        }

        $record = $type->id ? DocumentTypeRecord::findOne($type->id) : null;
        $isNew = $record === null;

        if ($isNew) {
            $record = new DocumentTypeRecord();
            $record->uid = StringHelper::UUID();
            $record->sortOrder = $this->_nextSortOrder($type->storeId);
        }

        $record->storeId = $type->storeId;
        $record->name = $type->name;
        $record->handle = $type->handle;
        $record->kind = $type->kind;
        $record->enabled = $type->enabled;
        $record->designId = $type->designId ?: null;
        $record->templatePath = $type->templatePath ?: null;
        $record->numberSource = $type->numberSource;
        $record->format = $type->format;
        $record->startNumber = $type->startNumber;
        $record->increment = $type->increment;
        $record->padLength = $type->padLength;
        $record->resetPolicy = $type->resetPolicy;
        $record->filenameFormat = $type->filenameFormat ?: null;
        $record->issueTrigger = $type->issueTrigger;
        $record->issueStatusId = $type->issueTrigger === DocumentType::TRIGGER_STATUS ? $type->issueStatusId : null;
        $record->dueDays = $type->dueDays;
        $record->availableToCustomer = $type->availableToCustomer;
        $record->orderCondition = $type->orderCondition;

        if ($type->sortOrder !== null) {
            $record->sortOrder = $type->sortOrder;
        }

        if (!$record->save()) {
            $type->addErrors($record->getErrors());
            return false;
        }

        $type->id = $record->id;
        $type->uid = $record->uid;
        $type->sortOrder = $record->sortOrder;

        $this->_types = null;

        return true;
    }

    /**
     * Deleting a type deletes its documents, because a document with no type has no template, no
     * design and no sequence — it is a row nobody can render. The confirmation says so.
     */
    public function deleteTypeById(int $id): bool
    {
        if (!$this->getTypeById($id)) {
            return false;
        }

        Db::delete(Table::TYPES, ['id' => $id]);

        $this->_types = null;

        return true;
    }

    /**
     * @param int[] $ids
     */
    public function reorderTypes(array $ids): bool
    {
        foreach ($ids as $index => $id) {
            Db::update(Table::TYPES, ['sortOrder' => $index + 1], ['id' => $id], [], false);
        }

        $this->_types = null;

        return true;
    }

    private function _nextSortOrder(?int $storeId): int
    {
        $max = (new Query())
            ->from(Table::TYPES)
            ->where(['storeId' => $storeId])
            ->max('sortOrder');

        return (int)$max + 1;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function _toModel(array $row): DocumentType
    {
        $row['id'] = (int)$row['id'];
        $row['storeId'] = (int)$row['storeId'];
        $row['enabled'] = (bool)$row['enabled'];
        $row['availableToCustomer'] = (bool)$row['availableToCustomer'];
        $row['startNumber'] = (int)$row['startNumber'];
        $row['increment'] = (int)$row['increment'];
        $row['padLength'] = (int)$row['padLength'];
        $row['dueDays'] = (int)$row['dueDays'];
        $row['designId'] = $row['designId'] !== null ? (int)$row['designId'] : null;
        $row['issueStatusId'] = $row['issueStatusId'] !== null ? (int)$row['issueStatusId'] : null;
        $row['sortOrder'] = $row['sortOrder'] !== null ? (int)$row['sortOrder'] : null;

        unset($row['dateCreated'], $row['dateUpdated']);

        return new DocumentType($row);
    }
}
