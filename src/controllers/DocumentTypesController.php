<?php

namespace justinholtweb\donky\controllers;

use Craft;
use craft\commerce\Plugin as Commerce;
use craft\helpers\Json;
use craft\web\Controller;
use justinholtweb\donky\helpers\Kind;
use justinholtweb\donky\models\DocumentType;
use justinholtweb\donky\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Managing document types.
 */
class DocumentTypesController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('donky-manageTypes');

        if (!Plugin::commerceIsReady()) {
            throw new ForbiddenHttpException('Craft Commerce is not installed.');
        }

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        $stores = Commerce::getInstance()->getStores()->getAllStores();
        $groups = [];

        foreach ($stores as $store) {
            $rows = [];

            foreach ($plugin->getDocumentTypes()->getTypesForStore($store->id) as $type) {
                $rows[] = [
                    'type' => $type,
                    'licensed' => $plugin->getDocumentTypes()->isLicensed($type),
                    'next' => $plugin->getNumbers()->preview($type),
                    'count' => $plugin->getDocuments()->count(['typeId' => $type->id]),
                ];
            }

            $groups[] = ['store' => $store, 'rows' => $rows];
        }

        return $this->renderTemplate('donky/documenttypes/_index', [
            'groups' => $groups,
            'isPro' => $plugin->isPro(),
            'abacus' => Plugin::abacusIsReady(),
        ]);
    }

    public function actionEdit(?int $typeId = null, ?DocumentType $type = null): Response
    {
        $plugin = Plugin::getInstance();
        $service = $plugin->getDocumentTypes();

        if ($type === null) {
            if ($typeId !== null) {
                $type = $service->getTypeById($typeId);

                if ($type === null) {
                    throw new NotFoundHttpException('Document type not found');
                }
            } else {
                $storeId = (int)($this->request->getQueryParam('storeId')
                    ?: Commerce::getInstance()->getStores()->getPrimaryStore()->id);

                $type = new DocumentType([
                    'storeId' => $storeId,
                    'designId' => $plugin->getDesigns()->getDefaultDesign()->id,
                ]);
            }
        }

        $kindOptions = [];

        foreach (Kind::labels() as $value => $label) {
            $kindOptions[] = [
                'value' => $value,
                'label' => Kind::isPro($value) && !$plugin->isPro() ? $label . ' — Pro' : $label,
                'disabled' => Kind::isPro($value) && !$plugin->isPro(),
            ];
        }

        $statusOptions = [['value' => '', 'label' => Craft::t('donky', 'Choose a status')]];

        foreach (Commerce::getInstance()->getOrderStatuses()->getAllOrderStatuses($type->storeId) as $status) {
            $statusOptions[] = ['value' => $status->id, 'label' => $status->name];
        }

        $designOptions = [];

        foreach ($plugin->getDesigns()->getAllDesigns() as $design) {
            $designOptions[] = ['value' => $design->id, 'label' => $design->name];
        }

        return $this->renderTemplate('donky/documenttypes/_edit', [
            'type' => $type,
            'isNew' => !$type->id,
            'isPro' => $plugin->isPro(),
            'abacus' => Plugin::abacusIsReady(),
            'kindOptions' => $kindOptions,
            'statusOptions' => $statusOptions,
            'designOptions' => $designOptions,
            'sourceOptions' => $this->_options(DocumentType::numberSources()),
            'resetOptions' => $this->_options(DocumentType::resetPolicies()),
            'triggerOptions' => $this->_options(DocumentType::issueTriggers()),
            'counters' => $type->id ? $plugin->getNumbers()->getCountersForType($type->id) : [],
            'preview' => $type->id ? $plugin->getNumbers()->preview($type) : null,
            'defaultTemplate' => Kind::defaultTemplate($type->kind),
            'title' => $type->id ? $type->name : Craft::t('donky', 'New document type'),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $service = $plugin->getDocumentTypes();
        $id = $this->request->getBodyParam('id');
        $type = $id ? $service->getTypeById((int)$id) : new DocumentType();

        if ($type === null) {
            throw new NotFoundHttpException('Document type not found');
        }

        $type->storeId = (int)$this->request->getBodyParam('storeId', $type->storeId);
        $type->name = $this->request->getBodyParam('name', $type->name);
        $type->handle = $this->request->getBodyParam('handle', $type->handle);
        $type->kind = $this->request->getBodyParam('kind', $type->kind);
        $type->enabled = (bool)$this->request->getBodyParam('enabled', $type->enabled);
        $type->designId = (int)$this->request->getBodyParam('designId') ?: null;
        $type->templatePath = trim((string)$this->request->getBodyParam('templatePath', '')) ?: null;
        $type->numberSource = $this->request->getBodyParam('numberSource', $type->numberSource);
        $type->format = (string)$this->request->getBodyParam('format', $type->format);
        $type->startNumber = (int)$this->request->getBodyParam('startNumber', $type->startNumber);
        $type->increment = max(1, (int)$this->request->getBodyParam('increment', $type->increment));
        $type->padLength = (int)$this->request->getBodyParam('padLength', $type->padLength);
        $type->resetPolicy = $this->request->getBodyParam('resetPolicy', $type->resetPolicy);
        $type->filenameFormat = trim((string)$this->request->getBodyParam('filenameFormat', '')) ?: null;
        $type->issueTrigger = $this->request->getBodyParam('issueTrigger', $type->issueTrigger);
        $type->issueStatusId = (int)$this->request->getBodyParam('issueStatusId') ?: null;
        $type->dueDays = (int)$this->request->getBodyParam('dueDays', $type->dueDays);
        $type->availableToCustomer = (bool)$this->request->getBodyParam('availableToCustomer', $type->availableToCustomer);

        if ($plugin->isPro()) {
            $type->setCondition($this->request->getBodyParam('orderCondition'));
        }

        if (Kind::isPro($type->kind) && !$plugin->isPro()) {
            $type->addError('kind', Craft::t('donky', '{kind} documents are a Donky Pro feature.', ['kind' => Kind::label($type->kind)]));
        } elseif ($service->saveType($type)) {
            $this->setSuccessFlash(Craft::t('donky', 'Document type saved.'));

            return $this->redirectToPostedUrl($type);
        }

        $this->setFailFlash(Craft::t('donky', 'Couldn’t save the document type.'));
        Craft::$app->getUrlManager()->setRouteParams(['type' => $type]);

        return null;
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $id = (int)$this->request->getRequiredBodyParam('id');

        return $this->asSuccess(
            Craft::t('donky', 'Document type deleted.'),
            ['deleted' => Plugin::getInstance()->getDocumentTypes()->deleteTypeById($id)]
        );
    }

    public function actionReorder(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $ids = Json::decodeIfJson($this->request->getRequiredBodyParam('ids'));
        Plugin::getInstance()->getDocumentTypes()->reorderTypes(array_map('intval', $ids));

        return $this->asSuccess();
    }

    /**
     * A live preview of a format the merchant has typed but not saved. Never spends a number.
     */
    public function actionPreviewNumber(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $type = new DocumentType([
            'id' => (int)$this->request->getBodyParam('id') ?: null,
            'storeId' => (int)$this->request->getBodyParam('storeId') ?: null,
            'name' => 'preview',
            'handle' => 'preview',
            'numberSource' => (string)$this->request->getBodyParam('numberSource', DocumentType::SOURCE_OWN),
            'format' => (string)$this->request->getBodyParam('format', '{number}'),
            'startNumber' => (int)$this->request->getBodyParam('startNumber', 1),
            'increment' => max(1, (int)$this->request->getBodyParam('increment', 1)),
            'padLength' => (int)$this->request->getBodyParam('padLength', 0),
            'resetPolicy' => (string)$this->request->getBodyParam('resetPolicy', DocumentType::RESET_NEVER),
        ]);

        try {
            return $this->asJson(['preview' => Plugin::getInstance()->getNumbers()->preview($type)]);
        } catch (\Throwable $e) {
            return $this->asJson(['preview' => null, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @param array<string, string> $map
     * @return array<int, array<string, string>>
     */
    private function _options(array $map): array
    {
        return array_map(
            static fn($value, $label) => ['value' => $value, 'label' => $label],
            array_keys($map),
            array_values($map)
        );
    }
}
