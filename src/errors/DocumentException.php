<?php

namespace justinholtweb\donky\errors;

use yii\base\Exception;

/**
 * Thrown when a document cannot be issued or rendered.
 */
class DocumentException extends Exception
{
    public function getName(): string
    {
        return 'Donky document exception';
    }
}
