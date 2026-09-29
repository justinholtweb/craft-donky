<?php

namespace justinholtweb\donky\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\donky\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Bulk printing, driven from the order index.
 */
class BulkController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('donky-bulkPrint');

        return true;
    }

    /**
     * Render the selected orders as one PDF and hand it straight back to the browser.
     *
     * Posted from the order index's action trigger, so this is a normal form POST rather than an
     * Ajax call — a JSON response cannot become a file the browser opens.
     */
    public function actionPrint(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();

        if (!$plugin->isPro()) {
            throw new ForbiddenHttpException('Bulk printing is a Donky Pro feature.');
        }

        $typeId = (int)$this->request->getRequiredBodyParam('typeId');
        $orderIds = array_map('intval', (array)$this->request->getBodyParam('orderId', []));
        $type = $plugin->getDocumentTypes()->getTypeById($typeId);

        if ($type === null) {
            throw new NotFoundHttpException('Document type not found');
        }

        try {
            $result = $plugin->getBulk()->print($orderIds, $type, true);
        } catch (\Throwable $e) {
            // The trigger opened a new tab, so a flash message on the index behind it would never
            // be seen. Say what went wrong in the tab that is already open.
            $this->response->format = Response::FORMAT_RAW;
            $this->response->getHeaders()->set('Content-Type', 'text/html; charset=UTF-8');
            $this->response->getHeaders()->set('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'");
            $this->response->data = '<!DOCTYPE html><meta charset="utf-8"><body style="font:14px/1.5 system-ui;padding:40px;max-width:36em">'
                . '<h1 style="font-size:18px">' . Craft::t('donky', 'Nothing was printed') . '</h1><p>'
                . \craft\helpers\Html::encode($e->getMessage()) . '</p>';

            return $this->response;
        }

        if ($result['skipped']) {
            Craft::warning(sprintf('Donky bulk print skipped %d order(s): %s',
                count($result['skipped']),
                implode('; ', $result['skipped'])
            ), 'donky');
        }

        return $this->response->sendContentAsFile($result['pdf'], $result['fileName'], [
            'mimeType' => 'application/pdf',
            'inline' => true,
        ]);
    }
}
