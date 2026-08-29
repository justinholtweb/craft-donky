<?php

namespace justinholtweb\donky\helpers;

use Craft;

/**
 * The document kinds Donky knows how to build.
 *
 * A kind is not a template — it is what a document *is*: whether it is numbered, whether it
 * belongs to one order or to a selection of them, and whether it inverts the money. The template
 * is free to render any of them however it likes.
 */
abstract class Kind
{
    public const INVOICE = 'invoice';
    public const PACKING_SLIP = 'packing-slip';
    public const DELIVERY_NOTE = 'delivery-note';
    public const PROFORMA = 'proforma';
    public const RECEIPT = 'receipt';
    public const CREDIT_NOTE = 'credit-note';
    public const PICK_LIST = 'pick-list';
    public const CUSTOM = 'custom';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::INVOICE => Craft::t('donky', 'Invoice'),
            self::PACKING_SLIP => Craft::t('donky', 'Packing slip'),
            self::DELIVERY_NOTE => Craft::t('donky', 'Delivery note'),
            self::PROFORMA => Craft::t('donky', 'Proforma invoice'),
            self::RECEIPT => Craft::t('donky', 'Receipt'),
            self::CREDIT_NOTE => Craft::t('donky', 'Credit note'),
            self::PICK_LIST => Craft::t('donky', 'Pick list'),
            self::CUSTOM => Craft::t('donky', 'Custom document'),
        ];
    }

    public static function label(string $kind): string
    {
        return self::labels()[$kind] ?? $kind;
    }

    /**
     * The kinds Lite may create. Everything else is a Pro document.
     *
     * @return string[]
     */
    public static function liteKinds(): array
    {
        return [self::INVOICE, self::PACKING_SLIP];
    }

    public static function isPro(string $kind): bool
    {
        return !in_array($kind, self::liteKinds(), true);
    }

    /**
     * Whether documents of this kind carry a number of their own by default.
     */
    public static function isNumberedByDefault(string $kind): bool
    {
        return in_array($kind, [self::INVOICE, self::CREDIT_NOTE, self::PROFORMA, self::RECEIPT], true);
    }

    /**
     * Whether a document of this kind is built from a *selection* of orders rather than one order.
     */
    public static function isAggregate(string $kind): bool
    {
        return $kind === self::PICK_LIST;
    }

    /**
     * Whether the money on this kind of document runs the other way.
     */
    public static function isCredit(string $kind): bool
    {
        return $kind === self::CREDIT_NOTE;
    }

    /**
     * Whether a document of this kind shows prices at all. A packing slip that quotes prices is
     * the classic customs and gifting complaint, so the bundled templates leave them off.
     */
    public static function showsPrices(string $kind): bool
    {
        return !in_array($kind, [self::PACKING_SLIP, self::DELIVERY_NOTE, self::PICK_LIST], true);
    }

    /**
     * The bundled template that renders this kind when the type has no template of its own.
     */
    public static function defaultTemplate(string $kind): string
    {
        return match ($kind) {
            self::PICK_LIST => 'donky/_render/pick-list',
            self::CREDIT_NOTE => 'donky/_render/credit-note',
            self::PACKING_SLIP, self::DELIVERY_NOTE => 'donky/_render/packing-slip',
            default => 'donky/_render/invoice',
        };
    }

    /**
     * A sensible starting number format for a kind.
     */
    public static function defaultFormat(string $kind): string
    {
        return match ($kind) {
            self::CREDIT_NOTE => 'CN-{yyyy}-{number}',
            self::PROFORMA => 'PF-{yyyy}-{number}',
            self::RECEIPT => 'REC-{yyyy}-{number}',
            default => 'INV-{yyyy}-{number}',
        };
    }
}
