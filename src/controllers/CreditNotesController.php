<?php

namespace justinholtweb\donky\controllers;

use Craft;
use craft\commerce\elements\Order;
use craft\web\Controller;
use justinholtweb\donky\helpers\Kind;
use justinholtweb\donky\models\Document;
use justinholtweb\donky\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Credit notes.
 */
class CreditNotesController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('donky-issueDocuments');

        if (!Plugin::getInstance()->isPro()) {
            throw new ForbiddenHttpException('Credit notes are a Donky Pro feature.');
        }

        return true;
    }

    public function actionEdit(int $orderId): Response
    {
        $plugin = Plugin::getInstance();
        $order = Order::find()->id($orderId)->status(null)->one();

        if ($order === null) {
            throw new NotFoundHttpException('Order not found');
        }

        $types = array_filter(
            $plugin->getDocumentTypes()->getUsableTypes($order->storeId),
            static fn($type) => Kind::isCredit($type->kind)
        );

        if (!$types) {
            throw new NotFoundHttpException('No credit note type has been set up.');
        }

        $invoices = array_filter(
            $plugin->getDocuments()->getDocumentsForOrder((int)$order->id, null, false),
            static fn(Document $document) => $document->kind === Kind::INVOICE
        );

        return $this->renderTemplate('donky/documents/_credit', [
            'order' => $order,
            'types' => $types,
            'lines' => $plugin->getCreditNotes()->creditableLines($order),
            'refunded' => $plugin->getCreditNotes()->refundedTotal($order),
            'invoices' => $invoices,
            'title' => Craft::t('donky', 'Credit note for {order}', ['order' => $order->reference ?: $order->getShortNumber()]),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $orderId = (int)$this->request->getRequiredBodyParam('orderId');
        $typeId = (int)$this->request->getRequiredBodyParam('typeId');
        $order = Order::find()->id($orderId)->status(null)->one();
        $type = $plugin->getDocumentTypes()->getTypeById($typeId);

        if (!$order || !$type) {
            throw new NotFoundHttpException('Order or credit note type not found');
        }

        $quantities = [];

        foreach ((array)$this->request->getBodyParam('qty', []) as $lineItemId => $qty) {
            $quantities[(int)$lineItemId] = (int)$qty;
        }

        try {
            $document = $plugin->getCreditNotes()->issue($type, $order, $quantities, [
                'shipping' => (float)$this->request->getBodyParam('shipping', 0),
                'adjustment' => (float)$this->request->getBodyParam('adjustment', 0),
                'reason' => trim((string)$this->request->getBodyParam('reason')) ?: null,
                'sourceDocumentId' => (int)$this->request->getBodyParam('sourceDocumentId') ?: null,
            ]);
        } catch (\Throwable $e) {
            $this->setFailFlash($e->getMessage());

            return null;
        }

        $this->setSuccessFlash(Craft::t('donky', 'Credit note {number} issued.', ['number' => $document->getLabel()]));

        return $this->redirect($document->getCpUrl());
    }
}
