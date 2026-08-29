<?php

namespace justinholtweb\donky\events;

use justinholtweb\donky\models\Document;
use yii\base\Event;

/**
 * Raised around rendering a document, so another plugin can rewrite the HTML or hand back its own
 * PDF bytes without Donky ever calling dompdf.
 */
class RenderEvent extends Event
{
    /** @var Document[] */
    public array $documents = [];

    /** @var string|null The HTML about to be rendered, writable in the before event. */
    public ?string $html = null;

    /** @var string|null Set this in the before event to supply the PDF yourself. */
    public ?string $pdf = null;
}
