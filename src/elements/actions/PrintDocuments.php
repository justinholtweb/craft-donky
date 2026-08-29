<?php

namespace justinholtweb\donky\elements\actions;

use Craft;
use craft\base\ElementAction;
use justinholtweb\donky\Plugin;

/**
 * "Print invoices", "Print packing slips", "Build pick list" — one action per document type on the
 * order index.
 *
 * The action never runs server-side: `getTriggerHtml()` posts the selection straight to Donky's
 * bulk controller in a throwaway form, which is the only way an element action can hand the
 * browser a file rather than a JSON response.
 */
class PrintDocuments extends ElementAction
{
    public ?int $typeId = null;
    public ?string $typeName = null;
    public bool $isPickList = false;

    /**
     * @inheritdoc
     */
    public static function isDownload(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public function getTriggerLabel(): string
    {
        if ($this->isPickList) {
            return Craft::t('donky', 'Build {name}', ['name' => $this->typeName]);
        }

        return Craft::t('donky', 'Print {name}', ['name' => $this->typeName]);
    }

    /**
     * @inheritdoc
     */
    public function getTriggerHtml(): ?string
    {
        Craft::$app->getView()->registerJsWithVars(fn($triggerId, $typeId) => <<<JS
(() => {
    new Craft.ElementActionTrigger({
        // Several print actions share one index, so each has to name its own trigger element.
        // Without this they all resolve to `<class>-actiontrigger` and only the last one works.
        triggerId: $triggerId,
        bulk: true,
        activate: (selectedItems) => {
            const \$form = Craft.createForm().appendTo(Garnish.\$bod);
            \$form.attr('target', '_blank');
            $(Craft.getCsrfInput()).appendTo(\$form);
            $('<input/>', { type: 'hidden', name: 'action', value: 'donky/bulk/print' }).appendTo(\$form);
            $('<input/>', { type: 'hidden', name: 'typeId', value: $typeId }).appendTo(\$form);
            selectedItems.each(function() {
                $('<input/>', { type: 'hidden', name: 'orderId[]', value: $(this).data('id') }).appendTo(\$form);
            });
            $('<input/>', { type: 'submit', value: 'Submit' }).appendTo(\$form);
            \$form.submit();
            \$form.remove();
        },
    });
})();
JS, [$this->getTriggerId(), $this->typeId]);

        return null;
    }

    /**
     * @inheritdoc
     */
    public function getTriggerId(): string
    {
        // Several of these live on one index, one per document type, so the default trigger id —
        // which is just the class name — would collide and only the last one would ever fire.
        return sprintf('%s-%s-actiontrigger', static::class, $this->typeId);
    }

    /**
     * @inheritdoc
     */
    public function performAction(\craft\elements\db\ElementQueryInterface $query): bool
    {
        $this->setMessage(Craft::t('donky', 'Nothing to do — printing happens in the browser.'));

        return true;
    }
}
