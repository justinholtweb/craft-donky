<?php

namespace justinholtweb\donky\models;

use craft\base\Model;

/**
 * One line of a pick list: a thing to fetch off a shelf, and how many of it.
 */
class PickListRow extends Model
{
    public ?string $sku = null;
    public ?string $description = null;
    public int $quantity = 0;

    /** @var array<string, string> The option values that make this a distinct thing to pick. */
    public array $options = [];

    /** @var string[] The order references this quantity is spread across. */
    public array $orders = [];

    public ?int $purchasableId = null;

    /**
     * The key that decides whether two line items are the same thing on a shelf. Two shirts of
     * different sizes are two rows even when they share a product.
     */
    public function getKey(): string
    {
        return ($this->sku ?: $this->description) . '|' . md5(serialize($this->options));
    }
}
