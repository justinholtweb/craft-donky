<?php

namespace justinholtweb\donky\events;

use craft\events\CancelableEvent;
use justinholtweb\donky\models\Document;

/**
 * Raised around issuing a document.
 */
class DocumentEvent extends CancelableEvent
{
    public Document $document;
    public bool $isNew = false;
}
