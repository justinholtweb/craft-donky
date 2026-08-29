<?php

namespace justinholtweb\donky\errors;

use yii\base\Exception;

/**
 * Thrown when a document number cannot be allocated.
 */
class NumberingException extends Exception
{
    public function getName(): string
    {
        return 'Donky numbering exception';
    }
}
