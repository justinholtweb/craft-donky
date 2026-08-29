<?php

namespace justinholtweb\donky\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\donky\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The customer-facing download.
 *
 * Two ways in, both checked here: a signed, expiring link (what goes in an email), or a logged-in
 * customer asking for a document on their own order. Neither one trusts the document id alone.
 */
class PublicController extends Controller
{
    public array|bool|int $allowAnonymous = true;

    public function actionDownload(string $uid): Response
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        if (!$settings->customerDownloads) {
            throw new NotFoundHttpException('Not found');
        }

        $document = $plugin->getDocuments()->getDocumentByUid($uid);

        if ($document === null || $document->isVoid()) {
            throw new NotFoundHttpException('Not found');
        }

        $type = $document->getType();

        if (!$type || !$type->availableToCustomer) {
            throw new NotFoundHttpException('Not found');
        }

        if (!$this->_authorised($document)) {
            throw new ForbiddenHttpException('This link has expired.');
        }

        $pdf = $plugin->getRenderer()->renderPdf([$document]);

        return $this->response->sendContentAsFile($pdf, $document->getDownloadFileName(), [
            'mimeType' => 'application/pdf',
            'inline' => (bool)$this->request->getQueryParam('inline'),
        ]);
    }

    private function _authorised(\justinholtweb\donky\models\Document $document): bool
    {
        $expires = (int)$this->request->getQueryParam('expires');
        $signature = (string)$this->request->getQueryParam('sig');

        if ($expires && $signature) {
            return Plugin::getInstance()->getDocuments()->verifySignature($document->uid, $expires, $signature);
        }

        // No signature: fall back to the logged-in customer owning the order.
        $user = Craft::$app->getUser()->getIdentity();
        $order = $document->getOrder();

        if (!$user || !$order) {
            return false;
        }

        return $order->customerId !== null && (int)$order->customerId === (int)$user->id;
    }
}
