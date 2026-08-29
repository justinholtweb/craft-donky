<?php

namespace justinholtweb\donky;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\commerce\elements\Order;
use craft\commerce\events\MailEvent;
use craft\commerce\services\Emails;
use craft\commerce\services\OrderHistories;
use craft\events\RegisterElementActionsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use justinholtweb\donky\elements\actions\PrintDocuments;
use justinholtweb\donky\helpers\Kind;
use justinholtweb\donky\models\Document;
use justinholtweb\donky\models\DocumentType;
use justinholtweb\donky\models\Settings;
use justinholtweb\donky\services\Attachments;
use justinholtweb\donky\services\Bulk;
use justinholtweb\donky\services\CreditNotes;
use justinholtweb\donky\services\Designs;
use justinholtweb\donky\services\Documents;
use justinholtweb\donky\services\DocumentTypes;
use justinholtweb\donky\services\Numbers;
use justinholtweb\donky\services\PickLists;
use justinholtweb\donky\services\Renderer;
use justinholtweb\donky\twig\DonkyVariable;
use yii\base\Event;

/**
 * Donky — the document suite for Craft Commerce.
 *
 * @property-read DocumentTypes $documentTypes
 * @property-read Designs $designs
 * @property-read Documents $documents
 * @property-read Numbers $numbers
 * @property-read Renderer $renderer
 * @property-read Attachments $attachments
 * @property-read PickLists $pickLists
 * @property-read CreditNotes $creditNotes
 * @property-read Bulk $bulk
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const HANDLE = 'donky';

    public const EDITION_LITE = 'lite';
    public const EDITION_PRO = 'pro';

    public string $schemaVersion = '5.0.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    /**
     * @inheritdoc
     */
    public static function editions(): array
    {
        return [
            self::EDITION_LITE,
            self::EDITION_PRO,
        ];
    }

    /**
     * @inheritdoc
     */
    public static function config(): array
    {
        return [
            'components' => [
                'documentTypes' => ['class' => DocumentTypes::class],
                'designs' => ['class' => Designs::class],
                'documents' => ['class' => Documents::class],
                'numbers' => ['class' => Numbers::class],
                'renderer' => ['class' => Renderer::class],
                'attachments' => ['class' => Attachments::class],
                'pickLists' => ['class' => PickLists::class],
                'creditNotes' => ['class' => CreditNotes::class],
                'bulk' => ['class' => Bulk::class],
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();

        $this->_registerTwigVariable();
        $this->_registerPermissions();
        $this->_registerRoutes();

        // Donky can be installed while Commerce is disabled or part-way through an upgrade, and
        // everything below reaches for classes that would not be there.
        if (!self::commerceIsReady()) {
            return;
        }

        $this->_registerIssueTriggers();
        $this->_registerEmailAttachments();
        $this->_registerOrderActions();
        $this->_registerOrderPanel();
    }

    /**
     * Whether Commerce is present and enabled.
     */
    public static function commerceIsReady(): bool
    {
        return class_exists(\craft\commerce\Plugin::class)
            && Craft::$app->getPlugins()->isPluginEnabled('commerce');
    }

    /**
     * Whether Abacus is running, in which case `order.reference` is a real sequential order
     * number and an invoice can simply carry it.
     */
    public static function abacusIsReady(): bool
    {
        return Craft::$app->getPlugins()->isPluginEnabled('abacus');
    }

    /**
     * Pro adds credit notes, pick lists, every document type beyond the first invoice and packing
     * slip, several designs, bulk printing, and attachment rules matched by status and condition.
     */
    public function isPro(): bool
    {
        return $this->is(self::EDITION_PRO, '>=');
    }

    public function getDocumentTypes(): DocumentTypes
    {
        return $this->get('documentTypes');
    }

    public function getDesigns(): Designs
    {
        return $this->get('designs');
    }

    public function getDocuments(): Documents
    {
        return $this->get('documents');
    }

    public function getNumbers(): Numbers
    {
        return $this->get('numbers');
    }

    public function getRenderer(): Renderer
    {
        return $this->get('renderer');
    }

    public function getAttachments(): Attachments
    {
        return $this->get('attachments');
    }

    public function getPickLists(): PickLists
    {
        return $this->get('pickLists');
    }

    public function getCreditNotes(): CreditNotes
    {
        return $this->get('creditNotes');
    }

    public function getBulk(): Bulk
    {
        return $this->get('bulk');
    }

    /**
     * @inheritdoc
     */
    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    /**
     * @inheritdoc
     */
    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('donky/settings', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
        ]);
    }

    /**
     * @inheritdoc
     */
    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('donky', 'Donky');

        $user = Craft::$app->getUser();
        $subNav = [];

        if ($user->checkPermission('donky-viewDocuments')) {
            $subNav['documents'] = [
                'label' => Craft::t('donky', 'Documents'),
                'url' => 'donky/documents',
            ];
        }

        if ($user->checkPermission('donky-manageTypes')) {
            $subNav['types'] = [
                'label' => Craft::t('donky', 'Document types'),
                'url' => 'donky/types',
            ];
        }

        if ($user->checkPermission('donky-manageDesigns')) {
            $subNav['designs'] = [
                'label' => Craft::t('donky', 'Designer'),
                'url' => 'donky/designs',
            ];
        }

        if ($user->checkPermission('donky-manageRules')) {
            $subNav['rules'] = [
                'label' => Craft::t('donky', 'Attachment rules'),
                'url' => 'donky/rules',
            ];
        }

        if ($user->getIsAdmin() && Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            $subNav['settings'] = [
                'label' => Craft::t('donky', 'Settings'),
                'url' => 'settings/plugins/donky',
            ];
        }

        if (!$subNav) {
            return null;
        }

        $item['subnav'] = $subNav;

        return $item;
    }

    private function _registerTwigVariable(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            static function(Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('donky', DonkyVariable::class);
            }
        );
    }

    private function _registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            static function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => Craft::t('donky', 'Donky'),
                    'permissions' => [
                        'donky-viewDocuments' => [
                            'label' => Craft::t('donky', 'View and download documents'),
                            'nested' => [
                                'donky-issueDocuments' => [
                                    'label' => Craft::t('donky', 'Issue documents'),
                                ],
                                'donky-voidDocuments' => [
                                    'label' => Craft::t('donky', 'Void and delete documents'),
                                ],
                                'donky-bulkPrint' => [
                                    'label' => Craft::t('donky', 'Print in bulk'),
                                ],
                            ],
                        ],
                        'donky-manageTypes' => [
                            'label' => Craft::t('donky', 'Manage document types'),
                        ],
                        'donky-manageDesigns' => [
                            'label' => Craft::t('donky', 'Manage designs'),
                        ],
                        'donky-manageRules' => [
                            'label' => Craft::t('donky', 'Manage attachment rules'),
                        ],
                    ],
                ];
            }
        );
    }

    private function _registerRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function(RegisterUrlRulesEvent $event) {
                $event->rules['donky'] = 'donky/documents/index';
                $event->rules['donky/documents'] = 'donky/documents/index';
                // Literal segments first: `donky/documents/download` would otherwise fall through
                // to Craft's template routing and 404, because it is not a document id.
                $event->rules['donky/documents/download'] = 'donky/documents/download';
                $event->rules['donky/documents/preview'] = 'donky/documents/preview';
                $event->rules['donky/documents/export'] = 'donky/documents/export';
                $event->rules['donky/documents/<documentId:\d+>'] = 'donky/documents/detail';
                $event->rules['donky/types'] = 'donky/document-types/index';
                $event->rules['donky/types/new'] = 'donky/document-types/edit';
                $event->rules['donky/types/<typeId:\d+>'] = 'donky/document-types/edit';
                $event->rules['donky/designs'] = 'donky/designs/index';
                $event->rules['donky/designs/new'] = 'donky/designs/edit';
                $event->rules['donky/designs/preview'] = 'donky/designs/preview';
                $event->rules['donky/designs/<designId:\d+>'] = 'donky/designs/edit';
                $event->rules['donky/rules'] = 'donky/rules/index';
                $event->rules['donky/rules/new'] = 'donky/rules/edit';
                $event->rules['donky/rules/<ruleId:\d+>'] = 'donky/rules/edit';
                $event->rules['donky/orders/<orderId:\d+>'] = 'donky/documents/order';
                $event->rules['donky/orders/<orderId:\d+>/credit'] = 'donky/credit-notes/edit';
            }
        );

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            static function(RegisterUrlRulesEvent $event) {
                // `sig`, not `token` — Craft reserves `token` for preview and share links and
                // rejects a request carrying an unknown one before any controller runs.
                $event->rules['donky/document/<uid:[\w\-]+>'] = 'donky/public/download';
            }
        );
    }

    /**
     * Automatic issuing. Nothing here fires until a type is set to something other than "only when
     * asked", and the whole lot can be switched off in the settings.
     */
    private function _registerIssueTriggers(): void
    {
        Event::on(
            Order::class,
            Order::EVENT_AFTER_COMPLETE_ORDER,
            function(Event $event) {
                /** @var Order $order */
                $order = $event->sender;
                $this->_issueForTrigger(DocumentType::TRIGGER_COMPLETE, $order);
            }
        );

        Event::on(
            Order::class,
            Order::EVENT_AFTER_ORDER_PAID,
            function(Event $event) {
                /** @var Order $order */
                $order = $event->sender;
                $this->_issueForTrigger(DocumentType::TRIGGER_PAID, $order);
            }
        );

        Event::on(
            OrderHistories::class,
            OrderHistories::EVENT_ORDER_STATUS_CHANGE,
            function(\craft\commerce\events\OrderStatusEvent $event) {
                // Typed, non-nullable properties on Commerce's event: reading one that was
                // never set is a fatal, not a null.
                if (!isset($event->orderHistory, $event->order)) {
                    return;
                }

                $this->_issueForTrigger(
                    DocumentType::TRIGGER_STATUS,
                    $event->order,
                    $event->orderHistory->newStatusId
                );
            }
        );
    }

    private function _issueForTrigger(string $trigger, Order $order, ?int $statusId = null): void
    {
        if (!$this->getSettings()->autoIssue) {
            return;
        }

        foreach ($this->getDocumentTypes()->getTypesForTrigger($trigger, $order, $statusId) as $type) {
            try {
                $this->getDocuments()->ensure($type, $order, Document::SOURCE_AUTOMATIC);
            } catch (\Throwable $e) {
                // Issuing a document must never fail a checkout or a status change.
                Craft::error(sprintf(
                    'Donky could not issue “%s” for order %s: %s',
                    $type->name,
                    $order->getShortNumber(),
                    $e->getMessage()
                ), 'donky');

                Craft::$app->getErrorHandler()->logException($e);
            }
        }
    }

    private function _registerEmailAttachments(): void
    {
        Event::on(
            Emails::class,
            Emails::EVENT_BEFORE_SEND_MAIL,
            function(MailEvent $event) {
                $this->getAttachments()->handleMailEvent($event);
            }
        );
    }

    /**
     * One bulk action per printable document type, so the merchant picks the document from the
     * actions menu rather than from a second screen.
     */
    private function _registerOrderActions(): void
    {
        Event::on(
            Order::class,
            Element::EVENT_REGISTER_ACTIONS,
            function(RegisterElementActionsEvent $event) {
                if (!$this->isPro() || !Craft::$app->getUser()->checkPermission('donky-bulkPrint')) {
                    return;
                }

                foreach ($this->getDocumentTypes()->getUsableTypes() as $type) {
                    $event->actions[] = [
                        'type' => PrintDocuments::class,
                        'typeId' => $type->id,
                        'typeName' => $type->name,
                        'isPickList' => Kind::isAggregate($type->kind),
                    ];
                }
            }
        );
    }

    private function _registerOrderPanel(): void
    {
        Craft::$app->getView()->hook('cp.commerce.order.edit.details', function(array &$context) {
            $order = $context['order'] ?? null;

            if (!$order instanceof Order || !$order->id) {
                return null;
            }

            if (!Craft::$app->getUser()->checkPermission('donky-viewDocuments')) {
                return null;
            }

            return Craft::$app->getView()->renderTemplate('donky/_order-panel', [
                'order' => $order,
                'documents' => $this->getDocuments()->getDocumentsForOrder((int)$order->id),
                'types' => $this->getDocumentTypes()->getUsableTypes($order->storeId),
                'canIssue' => Craft::$app->getUser()->checkPermission('donky-issueDocuments'),
                'isPro' => $this->isPro(),
            ]);
        });
    }
}
