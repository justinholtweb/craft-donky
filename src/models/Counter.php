<?php

namespace justinholtweb\donky\models;

use craft\base\Model;
use DateTime;

/**
 * One counter, for one document type, for one reset period.
 *
 * A yearly type grows a row a year and each row counts on its own — that is the whole mechanism
 * behind "start at 1 again in January".
 */
class Counter extends Model
{
    public ?int $id = null;
    public ?int $typeId = null;
    public string $periodKey = '';
    public int $nextNumber = 1;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;
}
