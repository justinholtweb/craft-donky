<?php

namespace justinholtweb\donky\controllers;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\helpers\DateTimeHelper;
use craft\web\Controller;
use justinholtweb\donky\helpers\Kind;
use justinholtweb\donky\models\Document;
use justinholtweb\donky\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The document ledger, and everything you can do to one document.
 */
class DocumentsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('donky-viewDocuments');

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        $criteria = [
            'typeId' => (int)$this->request->getQueryParam('typeId') ?: null,
            'kind' => $this->request->getQueryParam('kind') ?: null,
            'status' => $this->request->getQueryParam('status') ?: null,
            'search' => trim((string)$this->request->getQueryParam('search')) ?: null,
        ];

        $perPage = 50;
        $page = max(1, (int)$this->request->getQueryParam('page', 1));
        $total = $plugin->getDocuments()->count($criteria);
        $documents = $plugin->getDocuments()->find($criteria, $perPage, ($page - 1) * $perPage);

        return $this->renderTemplate('donky/documents/_index', [
            'documents' => $documents,
            'criteria' => $criteria,
            'types' => $plugin->getDocumentTypes()->getAllTypes(),
            'kinds' => Kind::labels(),
            'statuses' => Document::statuses(),
            'total' => $total,
            'page' => $page,
            'pageCount' => max(1, (int)ceil($total / $perPage)),
            'isPro' => $plugin->isPro(),
        ]);
    }

    public function actionDetail(int $documentId): Response
    {
        $document = $this->_document($documentId);

        return $this->renderTemplate('donky/documents/_detail', [
            'document' => $document,
            'type' => $document->getType(),
            'order' => $document->getOrder(),
            'canVoid' => Craft::$app->getUser()->checkPermission('donky-voidDocuments'),
            'title' => $document->getLabel(),
        ]);
    }

    /**
     * Issue a document for an order, from the order edit screen.
     */
    public function actionIssue(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('donky-issueDocuments');

        $plugin = Plugin::getInstance();
        $typeId = (int)$this->request->getRequiredBodyParam('typeId');
        $orderId = (int)$this->request->getRequiredBodyParam('orderId');
        $type = $plugin->getDocumentTypes()->getTypeById($typeId);
        $order = Order::find()->id($orderId)->status(null)->one();

        if (!$type || !$order) {
            throw new NotFoundHttpException('Document type or order not found');
        }

        try {
            $document = $type->isAggregate()
                ? $plugin->getPickLists()->issue($type, [$order], ['source' => Document::SOURCE_MANUAL])
                : $plugin->getDocuments()->issue($type, $order, ['source' => Document::SOURCE_MANUAL]);
        } catch (\Throwable $e) {
            return $this->asFailure($e->getMessage());
        }

        return $this->asSuccess(Craft::t('donky', '{name} issued.', ['name' => $document->getLabel()]), [
            'documentId' => $document->id,
            'downloadUrl' => $document->getDownloadUrl(),
        ]);
    }

    /**
     * Reissue: void the old one and issue a fresh one, so the sequence keeps a record of both.
     */
    public function actionReissue(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('donky-issueDocuments');

        $plugin = Plugin::getInstance();
        $document = $this->_document((int)$this->request->getRequiredBodyParam('id'));
        $type = $document->getType();
        $order = $document->getOrder();

        if (!$type || !$order) {
            return $this->asFailure(Craft::t('donky', 'That document cannot be reissued.'));
        }

        try {
            $plugin->getDocuments()->voidDocument($document, Craft::t('donky', 'Superseded by a reissue.'));
            $fresh = $plugin->getDocuments()->issue($type, $order, ['source' => Document::SOURCE_MANUAL]);
        } catch (\Throwable $e) {
            return $this->asFailure($e->getMessage());
        }

        return $this->asSuccess(Craft::t('donky', 'Reissued as {number}.', ['number' => $fresh->getLabel()]), [
            'documentId' => $fresh->id,
        ]);
    }

    public function actionVoid(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('donky-voidDocuments');

        $document = $this->_document((int)$this->request->getRequiredBodyParam('id'));
        $reason = trim((string)$this->request->getBodyParam('reason')) ?: null;

        Plugin::getInstance()->getDocuments()->voidDocument($document, $reason);

        return $this->asSuccess(Craft::t('donky', 'Document voided. It keeps its number.'));
    }

    public function actionRestore(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('donky-voidDocuments');

        $document = $this->_document((int)$this->request->getRequiredBodyParam('id'));
        Plugin::getInstance()->getDocuments()->restoreDocument($document);

        return $this->asSuccess(Craft::t('donky', 'Document restored.'));
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('donky-voidDocuments');

        $id = (int)$this->request->getRequiredBodyParam('id');
        Plugin::getInstance()->getDocuments()->deleteDocumentById($id);

        return $this->asSuccess(Craft::t('donky', 'Document deleted. Its number stays spent.'));
    }

    /**
     * The PDF.
     */
    public function actionDownload(int $id): Response
    {
        $document = $this->_document($id);
        $pdf = Plugin::getInstance()->getRenderer()->renderPdf([$document]);
        $inline = (bool)$this->request->getQueryParam('inline');

        return $this->response->sendContentAsFile($pdf, $document->getDownloadFileName(), [
            'mimeType' => 'application/pdf',
            'inline' => $inline,
        ]);
    }

    /**
     * The same markup the PDF is built from, in a browser. What you preview is what you get.
     */
    public function actionPreview(int $id): Response
    {
        $document = $this->_document($id);
        $html = Plugin::getInstance()->getRenderer()->renderPreview([$document]);

        $this->response->format = Response::FORMAT_RAW;
        $this->response->data = $html;
        $this->response->getHeaders()->set('Content-Type', 'text/html; charset=UTF-8');

        return $this->response;
    }

    /**
     * Every document for one order, as one PDF.
     */
    public function actionOrder(int $orderId): Response
    {
        $plugin = Plugin::getInstance();
        $documents = $plugin->getDocuments()->getDocumentsForOrder($orderId, null, false);

        if (!$documents) {
            throw new NotFoundHttpException('No documents have been issued for that order.');
        }

        $pdf = $plugin->getRenderer()->renderPdf(array_reverse($documents));
        $order = Order::find()->id($orderId)->status(null)->one();
        $name = sprintf('order-%s-documents.pdf', $order?->reference ?: $orderId);

        return $this->response->sendContentAsFile($pdf, $name, ['mimeType' => 'application/pdf']);
    }

    /**
     * The ledger as CSV — the thing an accountant actually asks for.
     */
    public function actionExport(): Response
    {
        $criteria = [
            'typeId' => (int)$this->request->getQueryParam('typeId') ?: null,
            'kind' => $this->request->getQueryParam('kind') ?: null,
            'status' => $this->request->getQueryParam('status') ?: null,
        ];

        $documents = Plugin::getInstance()->getDocuments()->find($criteria);
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, ['Number', 'Kind', 'Type', 'Order', 'Status', 'Issued', 'Due', 'Currency', 'Total']);

        foreach ($documents as $document) {
            $order = $document->getOrder();

            fputcsv($handle, [
                $document->formattedNumber,
                $document->getKindLabel(),
                $document->getType()?->name,
                $order?->reference ?: $order?->getShortNumber(),
                $document->getStatusLabel(),
                $document->dateIssued?->format('Y-m-d H:i'),
                $document->dateDue?->format('Y-m-d'),
                $document->currency,
                $document->total,
            ]);
        }

        rewind($handle);
        $csv = (string)stream_get_contents($handle);
        fclose($handle);

        return $this->response->sendContentAsFile($csv, 'donky-documents-' . date('Ymd-Hi') . '.csv', [
            'mimeType' => 'text/csv',
        ]);
    }

    private function _document(int $id): Document
    {
        $document = Plugin::getInstance()->getDocuments()->getDocumentById($id);

        if ($document === null) {
            throw new NotFoundHttpException('Document not found');
        }

        return $document;
    }
}
