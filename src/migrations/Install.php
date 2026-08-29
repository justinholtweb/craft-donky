<?php

namespace justinholtweb\donky\migrations;

use craft\db\Migration;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use justinholtweb\donky\db\Table;
use justinholtweb\donky\helpers\Kind;
use justinholtweb\donky\models\Design;
use justinholtweb\donky\models\DocumentType;

/**
 * Donky install migration.
 */
class Install extends Migration
{
    private const COMMERCE_STORES = '{{%commerce_stores}}';
    private const COMMERCE_ORDERS = '{{%commerce_orders}}';

    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $this->createTables();
        $this->createIndexes();
        $this->addForeignKeys();
        $this->seed();

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists(Table::RULES);
        $this->dropTableIfExists(Table::DOCUMENTS);
        $this->dropTableIfExists(Table::COUNTERS);
        $this->dropTableIfExists(Table::TYPES);
        $this->dropTableIfExists(Table::DESIGNS);

        return true;
    }

    private function createTables(): void
    {
        $this->createTable(Table::DESIGNS, [
            'id' => $this->primaryKey(),
            'name' => $this->string()->notNull(),
            'handle' => $this->string()->notNull(),
            'isDefault' => $this->boolean()->notNull()->defaultValue(false),
            'paperSize' => $this->string(16)->notNull()->defaultValue('letter'),
            'paperOrientation' => $this->string(16)->notNull()->defaultValue('portrait'),
            'margins' => $this->text(),
            'typography' => $this->text(),
            'colors' => $this->text(),
            'logoAssetId' => $this->integer(),
            'logoHeight' => $this->integer()->notNull()->defaultValue(16),
            'seller' => $this->text(),
            'blocks' => $this->text(),
            'customCss' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::TYPES, [
            'id' => $this->primaryKey(),
            'storeId' => $this->integer()->notNull(),
            'name' => $this->string()->notNull(),
            'handle' => $this->string()->notNull(),
            'kind' => $this->string(32)->notNull()->defaultValue(Kind::INVOICE),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'sortOrder' => $this->integer(),
            'designId' => $this->integer(),
            'templatePath' => $this->string(500),
            'numberSource' => $this->string(24)->notNull()->defaultValue(DocumentType::SOURCE_OWN),
            'format' => $this->string(255)->notNull()->defaultValue('INV-{yyyy}-{number}'),
            'startNumber' => $this->integer()->notNull()->defaultValue(1),
            'increment' => $this->integer()->notNull()->defaultValue(1),
            'padLength' => $this->integer()->notNull()->defaultValue(4),
            'resetPolicy' => $this->string(16)->notNull()->defaultValue(DocumentType::RESET_NEVER),
            'filenameFormat' => $this->string(255),
            // Nothing issues itself until somebody says so: installing a document suite must not
            // start stamping invoice numbers on a live store's checkout.
            'issueTrigger' => $this->string(16)->notNull()->defaultValue(DocumentType::TRIGGER_MANUAL),
            'issueStatusId' => $this->integer(),
            'dueDays' => $this->integer()->notNull()->defaultValue(0),
            'availableToCustomer' => $this->boolean()->notNull()->defaultValue(false),
            'orderCondition' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // One row per type *per reset period*, so "start again in January" means something.
        $this->createTable(Table::COUNTERS, [
            'id' => $this->primaryKey(),
            'typeId' => $this->integer()->notNull(),
            'periodKey' => $this->string(16)->notNull()->defaultValue(''),
            'nextNumber' => $this->bigInteger()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::DOCUMENTS, [
            'id' => $this->primaryKey(),
            'typeId' => $this->integer()->notNull(),
            'orderId' => $this->integer(),
            'storeId' => $this->integer(),
            'kind' => $this->string(32)->notNull()->defaultValue(Kind::INVOICE),
            'number' => $this->bigInteger(),
            'periodKey' => $this->string(16)->notNull()->defaultValue(''),
            'formattedNumber' => $this->string(255),
            'fileName' => $this->string(255),
            'status' => $this->string(16)->notNull()->defaultValue('issued'),
            'source' => $this->string(16)->notNull()->defaultValue('manual'),
            'sourceDocumentId' => $this->integer(),
            'dateIssued' => $this->dateTime()->notNull(),
            'dateDue' => $this->dateTime(),
            'currency' => $this->string(8),
            'total' => $this->decimal(14, 4)->notNull()->defaultValue(0),
            // The frozen order. `mediumText` because a hundred-line order with options is bigger
            // than `text` on MySQL, and a truncated snapshot is a corrupt invoice.
            'snapshot' => $this->mediumText(),
            'orderIds' => $this->text(),
            'notes' => $this->text(),
            'issuedByUserId' => $this->integer(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::RULES, [
            'id' => $this->primaryKey(),
            'storeId' => $this->integer()->notNull(),
            'name' => $this->string()->notNull(),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'sortOrder' => $this->integer(),
            'typeId' => $this->integer()->notNull(),
            'emailId' => $this->integer(),
            'orderStatusId' => $this->integer(),
            'issueIfMissing' => $this->boolean()->notNull()->defaultValue(true),
            'orderCondition' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
    }

    private function createIndexes(): void
    {
        $this->createIndex(null, Table::DESIGNS, ['handle'], true);

        $this->createIndex(null, Table::TYPES, ['storeId', 'handle'], true);
        $this->createIndex(null, Table::TYPES, ['storeId', 'sortOrder'], false);
        $this->createIndex(null, Table::TYPES, ['kind'], false);

        $this->createIndex(null, Table::COUNTERS, ['typeId', 'periodKey'], true);

        // The invariant the database keeps for us: one type cannot issue the same number twice
        // inside one reset period. Voiding a document deliberately does *not* free its number.
        $this->createIndex(null, Table::DOCUMENTS, ['typeId', 'periodKey', 'number'], true);
        $this->createIndex(null, Table::DOCUMENTS, ['orderId'], false);
        $this->createIndex(null, Table::DOCUMENTS, ['typeId', 'orderId'], false);
        $this->createIndex(null, Table::DOCUMENTS, ['formattedNumber'], false);
        $this->createIndex(null, Table::DOCUMENTS, ['kind'], false);
        $this->createIndex(null, Table::DOCUMENTS, ['status'], false);
        $this->createIndex(null, Table::DOCUMENTS, ['dateIssued'], false);

        $this->createIndex(null, Table::RULES, ['storeId', 'sortOrder'], false);
        $this->createIndex(null, Table::RULES, ['typeId'], false);
        $this->createIndex(null, Table::RULES, ['emailId'], false);
        $this->createIndex(null, Table::RULES, ['orderStatusId'], false);
    }

    private function addForeignKeys(): void
    {
        $this->addForeignKey(null, Table::TYPES, ['designId'], Table::DESIGNS, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, Table::COUNTERS, ['typeId'], Table::TYPES, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::DOCUMENTS, ['typeId'], Table::TYPES, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::DOCUMENTS, ['sourceDocumentId'], Table::DOCUMENTS, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, Table::RULES, ['typeId'], Table::TYPES, ['id'], 'CASCADE', null);

        // Commerce is a composer requirement, but a plugin can be installed before Commerce has
        // run its own migrations.
        if ($this->db->tableExists(self::COMMERCE_STORES)) {
            $this->addForeignKey(null, Table::TYPES, ['storeId'], self::COMMERCE_STORES, ['id'], 'CASCADE', null);
            $this->addForeignKey(null, Table::RULES, ['storeId'], self::COMMERCE_STORES, ['id'], 'CASCADE', null);
        }

        if ($this->db->tableExists(self::COMMERCE_ORDERS)) {
            // A deleted order must not take the invoice with it. The document is the record; it
            // keeps its number, its snapshot and its place in the sequence.
            $this->addForeignKey(null, Table::DOCUMENTS, ['orderId'], self::COMMERCE_ORDERS, ['id'], 'SET NULL', null);
        }
    }

    /**
     * A default design and, per store, a disabled invoice and packing slip — so the first screen a
     * merchant sees is a suite with sensible defaults rather than an empty page, and nothing has
     * been numbered yet.
     */
    private function seed(): void
    {
        $now = new \DateTime();
        $timestamp = $now->format('Y-m-d H:i:s');

        $this->insert(Table::DESIGNS, [
            'name' => 'Default',
            'handle' => 'default',
            'isDefault' => true,
            'paperSize' => 'letter',
            'paperOrientation' => 'portrait',
            'margins' => Json::encode(Design::defaultMargins()),
            'typography' => Json::encode(Design::defaultTypography()),
            'colors' => Json::encode(Design::defaultColors()),
            'logoHeight' => 16,
            'seller' => Json::encode(Design::defaultSeller()),
            'blocks' => Json::encode(Design::defaultBlocks()),
            'dateCreated' => $timestamp,
            'dateUpdated' => $timestamp,
            'uid' => StringHelper::UUID(),
        ]);

        $designId = (int)$this->db->getLastInsertID(Table::DESIGNS);

        if (!$this->db->tableExists(self::COMMERCE_STORES)) {
            return;
        }

        $storeIds = (new \craft\db\Query())
            ->select(['id'])
            ->from(self::COMMERCE_STORES)
            ->column($this->db);

        foreach ($storeIds as $storeId) {
            $sortOrder = 0;

            foreach ([
                [Kind::INVOICE, 'Invoice', 'invoice', DocumentType::SOURCE_OWN, 'INV-{yyyy}-{number}'],
                [Kind::PACKING_SLIP, 'Packing slip', 'packingSlip', DocumentType::SOURCE_NONE, ''],
            ] as [$kind, $name, $handle, $source, $format]) {
                $this->insert(Table::TYPES, [
                    'storeId' => $storeId,
                    'name' => $name,
                    'handle' => $handle,
                    'kind' => $kind,
                    'enabled' => true,
                    'sortOrder' => ++$sortOrder,
                    'designId' => $designId,
                    'numberSource' => $source,
                    'format' => $format ?: '{number}',
                    'startNumber' => 1,
                    'increment' => 1,
                    'padLength' => 4,
                    'resetPolicy' => DocumentType::RESET_NEVER,
                    'issueTrigger' => DocumentType::TRIGGER_MANUAL,
                    'dueDays' => 0,
                    'availableToCustomer' => $kind === Kind::INVOICE,
                    'dateCreated' => $timestamp,
                    'dateUpdated' => $timestamp,
                    'uid' => StringHelper::UUID(),
                ]);
            }
        }
    }
}
