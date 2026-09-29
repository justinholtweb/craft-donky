<?php

namespace justinholtweb\donky\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\helpers\Db;
use DateTime;
use justinholtweb\donky\db\Table;
use justinholtweb\donky\errors\NumberingException;
use justinholtweb\donky\models\Counter;
use justinholtweb\donky\models\DocumentType;
use justinholtweb\donky\Plugin;

/**
 * Document numbers.
 *
 * `allocate()` is the only place a number is ever handed out, and it holds a mutex while it does
 * it. `pick()` is deliberately public so that allocation and the preview agree, to the character,
 * about what comes next — one implementation, two callers.
 */
class Numbers extends Component
{
    /**
     * Hand out the next number for a type.
     *
     * @return array{number: int|null, periodKey: string, formatted: string|null}
     * @throws NumberingException
     */
    public function allocate(DocumentType $type, ?Order $order = null, ?DateTime $issuedAt = null): array
    {
        $issuedAt ??= new DateTime();

        if (!$type->isNumbered()) {
            return ['number' => null, 'periodKey' => '', 'formatted' => null];
        }

        if (!$type->usesOwnCounter()) {
            return [
                'number' => null,
                'periodKey' => '',
                'formatted' => $this->borrowedNumber($type, $order),
            ];
        }

        $periodKey = $this->periodKey($type, $issuedAt);
        $lockName = 'donky:type:' . $type->id;
        $mutex = Craft::$app->getMutex();
        $timeout = Plugin::getInstance()->getSettings()->lockTimeout;

        if (!$mutex->acquire($lockName, $timeout)) {
            throw new NumberingException(Craft::t('donky', 'Could not get the numbering lock for “{name}”.', ['name' => $type->name]));
        }

        try {
            $counter = $this->getCounter($type, $periodKey);
            $taken = $this->takenNumbers($type->id, $periodKey);
            $number = $this->pick($type, $counter->nextNumber, $taken);

            $this->setNextNumber($type, $periodKey, $number + $type->increment);

            return [
                'number' => $number,
                'periodKey' => $periodKey,
                'formatted' => $this->format($type, $number, $issuedAt, $order),
            ];
        } finally {
            $mutex->release($lockName);
        }
    }

    /**
     * What comes next, given a starting point and the numbers already spent.
     *
     * Shared by allocation and by the preview so that the number a merchant is shown while editing
     * a type is the number the next document will actually carry.
     *
     * @param int[] $taken
     */
    public function pick(DocumentType $type, int $from, array $taken = []): int
    {
        $number = max($from, $type->startNumber);
        $lookup = array_flip($taken);
        $guard = 0;

        while (isset($lookup[$number])) {
            $number += max(1, $type->increment);

            if (++$guard > 10000) {
                throw new NumberingException(Craft::t('donky', 'Could not find a free number for “{name}”.', ['name' => $type->name]));
            }
        }

        return $number;
    }

    /**
     * A preview of the next document number, without spending it.
     */
    public function preview(DocumentType $type, ?DateTime $at = null): ?string
    {
        $at ??= new DateTime();

        if (!$type->isNumbered()) {
            return null;
        }

        if (!$type->usesOwnCounter()) {
            return match ($type->numberSource) {
                DocumentType::SOURCE_ORDER_REFERENCE => Craft::t('donky', 'the order reference'),
                DocumentType::SOURCE_ORDER_NUMBER => Craft::t('donky', 'the short order number'),
                default => null,
            };
        }

        $periodKey = $this->periodKey($type, $at);

        if (!$type->id) {
            return $this->format($type, $type->startNumber, $at);
        }

        $counter = $this->getCounter($type, $periodKey, false);
        $number = $this->pick($type, $counter->nextNumber, $this->takenNumbers($type->id, $periodKey));

        return $this->format($type, $number, $at);
    }

    /**
     * The formatted number, with the tokens filled in.
     */
    public function format(DocumentType $type, int $number, ?DateTime $at = null, ?Order $order = null): string
    {
        $at ??= new DateTime();
        $padded = $type->padLength > 0
            ? str_pad((string)$number, $type->padLength, '0', STR_PAD_LEFT)
            : (string)$number;

        $store = null;

        if (Plugin::commerceIsReady() && $type->storeId) {
            $store = Commerce::getInstance()->getStores()->getStoreById($type->storeId);
        }

        return strtr($type->format, [
            '{number}' => $padded,
            '{rawNumber}' => (string)$number,
            '{yyyy}' => $at->format('Y'),
            '{yy}' => $at->format('y'),
            '{mm}' => $at->format('m'),
            '{dd}' => $at->format('d'),
            '{typeHandle}' => (string)$type->handle,
            '{storeHandle}' => (string)($store->handle ?? ''),
            '{orderNumber}' => (string)($order?->getShortNumber() ?? ''),
            '{orderReference}' => (string)($order->reference ?? ''),
        ]);
    }

    /**
     * The number a type takes from the order rather than counting itself. With Abacus installed,
     * `order.reference` *is* the sequential order number, so an invoice numbered this way carries
     * the same number as the order — which is what most merchants mean when they ask for
     * sequential invoice numbers.
     */
    public function borrowedNumber(DocumentType $type, ?Order $order): ?string
    {
        if (!$order) {
            return null;
        }

        $value = match ($type->numberSource) {
            DocumentType::SOURCE_ORDER_REFERENCE => $order->reference ?: $order->getShortNumber(),
            DocumentType::SOURCE_ORDER_NUMBER => $order->getShortNumber(),
            default => null,
        };

        if ($value === null) {
            return null;
        }

        // The format is still honoured, so `INV-{number}` works on a borrowed number too.
        return str_contains($type->format, '{number}')
            ? strtr($type->format, [
                '{number}' => $value,
                '{rawNumber}' => $value,
                '{yyyy}' => ($order->dateOrdered ?? new DateTime())->format('Y'),
                '{yy}' => ($order->dateOrdered ?? new DateTime())->format('y'),
                '{mm}' => ($order->dateOrdered ?? new DateTime())->format('m'),
                '{dd}' => ($order->dateOrdered ?? new DateTime())->format('d'),
                '{typeHandle}' => (string)$type->handle,
                '{storeHandle}' => '',
                '{orderNumber}' => $order->getShortNumber(),
                '{orderReference}' => (string)$order->reference,
            ])
            : $value;
    }

    /**
     * The reset bucket a date falls in. `''` for a sequence that never resets.
     */
    public function periodKey(DocumentType $type, ?DateTime $at = null): string
    {
        $at ??= new DateTime();

        return match ($type->resetPolicy) {
            DocumentType::RESET_YEARLY => $at->format('Y'),
            DocumentType::RESET_MONTHLY => $at->format('Y-m'),
            DocumentType::RESET_DAILY => $at->format('Y-m-d'),
            default => '',
        };
    }

    public function getCounter(DocumentType $type, string $periodKey, bool $create = true): Counter
    {
        $row = (new Query())
            ->select('*')
            ->from(Table::COUNTERS)
            ->where(['typeId' => $type->id, 'periodKey' => $periodKey])
            ->one();

        if ($row) {
            return new Counter([
                'id' => (int)$row['id'],
                'typeId' => (int)$row['typeId'],
                'periodKey' => $row['periodKey'],
                'nextNumber' => (int)$row['nextNumber'],
            ]);
        }

        $counter = new Counter([
            'typeId' => $type->id,
            'periodKey' => $periodKey,
            'nextNumber' => $type->startNumber,
        ]);

        if ($create && $type->id) {
            $this->setNextNumber($type, $periodKey, $type->startNumber);
        }

        return $counter;
    }

    /**
     * Move a counter forward. It will not go backwards: reissuing a number that may already be on
     * a printed invoice is worse than leaving a gap, and there is no way to tell from here which
     * of the two you are about to do.
     */
    public function setNextNumber(DocumentType $type, string $periodKey, int $next): void
    {
        $current = (new Query())
            ->select(['nextNumber'])
            ->from(Table::COUNTERS)
            ->where(['typeId' => $type->id, 'periodKey' => $periodKey])
            ->scalar();

        if ($current !== false && $current !== null && (int)$current >= $next) {
            return;
        }

        // The whole row goes in the second argument — `Db::upsert()`'s fourth is `$params`.
        Db::upsert(Table::COUNTERS, [
            'typeId' => $type->id,
            'periodKey' => $periodKey,
            'nextNumber' => $next,
        ], [
            'nextNumber' => $next,
        ]);
    }

    /**
     * Force a counter to a value, rewinding included. Only reachable from the CP with an explicit
     * confirmation and from the console, because it is the one operation that can duplicate a
     * number that is already printed.
     */
    public function forceNextNumber(DocumentType $type, string $periodKey, int $next): void
    {
        Db::upsert(Table::COUNTERS, [
            'typeId' => $type->id,
            'periodKey' => $periodKey,
            'nextNumber' => $next,
        ], [
            'nextNumber' => $next,
        ]);
    }

    /**
     * Every number this type has already spent in this period, void documents included — a voided
     * invoice keeps its number for exactly the same reason.
     *
     * @return int[]
     */
    public function takenNumbers(int $typeId, string $periodKey): array
    {
        return array_map('intval', (new Query())
            ->select(['number'])
            ->from(Table::DOCUMENTS)
            ->where(['typeId' => $typeId, 'periodKey' => $periodKey])
            ->andWhere(['not', ['number' => null]])
            ->column());
    }

    /**
     * The counters for a type, newest period first — what the CP shows on the type's screen.
     *
     * @return Counter[]
     */
    public function getCountersForType(int $typeId): array
    {
        $rows = (new Query())
            ->select('*')
            ->from(Table::COUNTERS)
            ->where(['typeId' => $typeId])
            ->orderBy(['periodKey' => SORT_DESC])
            ->all();

        return array_map(static fn(array $row) => new Counter([
            'id' => (int)$row['id'],
            'typeId' => (int)$row['typeId'],
            'periodKey' => $row['periodKey'],
            'nextNumber' => (int)$row['nextNumber'],
        ]), $rows);
    }
}
