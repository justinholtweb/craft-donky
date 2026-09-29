<?php

namespace justinholtweb\donky\controllers;

use Craft;
use craft\commerce\elements\Order;
use craft\web\Controller;
use justinholtweb\donky\models\Design;
use justinholtweb\donky\models\Document;
use justinholtweb\donky\models\DocumentType;
use justinholtweb\donky\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The Designer.
 */
class DesignsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('donky-manageDesigns');

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();

        return $this->renderTemplate('donky/designs/_index', [
            'designs' => $plugin->getDesigns()->getAllDesigns(),
            'isPro' => $plugin->isPro(),
            'canAdd' => $plugin->isPro(),
        ]);
    }

    public function actionEdit(?int $designId = null, ?Design $design = null): Response
    {
        $plugin = Plugin::getInstance();

        if ($design === null) {
            if ($designId !== null) {
                $design = $plugin->getDesigns()->getDesignById($designId);

                if ($design === null) {
                    throw new NotFoundHttpException('Design not found');
                }
            } else {
                if (!$plugin->isPro()) {
                    throw new ForbiddenHttpException('Donky Lite has one design.');
                }

                $design = new Design(['name' => '', 'handle' => '']);
            }
        }

        return $this->renderTemplate('donky/designs/_edit', [
            'design' => $design,
            'isNew' => !$design->id,
            'isPro' => $plugin->isPro(),
            'paperOptions' => $this->_options(Design::paperSizes()),
            'orientationOptions' => $this->_options(Design::orientations()),
            'fontOptions' => $this->_options(Design::fontFamilies()),
            'title' => $design->id ? $design->name : Craft::t('donky', 'New design'),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $service = $plugin->getDesigns();
        $id = $this->request->getBodyParam('id');
        $design = $id ? $service->getDesignById((int)$id) : new Design();

        if ($design === null) {
            throw new NotFoundHttpException('Design not found');
        }

        if (!$design->id && !$plugin->isPro()) {
            throw new ForbiddenHttpException('Donky Lite has one design.');
        }

        $design->name = $this->request->getBodyParam('name', $design->name);
        $design->handle = $this->request->getBodyParam('handle', $design->handle);
        $design->isDefault = (bool)$this->request->getBodyParam('isDefault', $design->isDefault);
        $design->paperSize = $this->request->getBodyParam('paperSize', $design->paperSize);
        $design->paperOrientation = $this->request->getBodyParam('paperOrientation', $design->paperOrientation);
        $design->logoHeight = (int)$this->request->getBodyParam('logoHeight', $design->logoHeight);
        $design->customCss = $this->request->getBodyParam('customCss') ?: null;

        // Craft's asset field posts an array of ids.
        $logo = $this->request->getBodyParam('logoAssetId');
        $design->logoAssetId = is_array($logo) ? ((int)reset($logo) ?: null) : ((int)$logo ?: null);

        $design->margins = array_map('intval', (array)$this->request->getBodyParam('margins', $design->margins));
        $design->typography = array_merge($design->typography, (array)$this->request->getBodyParam('typography', []));
        $design->colors = array_merge($design->colors, (array)$this->request->getBodyParam('colors', []));
        $design->seller = array_merge($design->seller, (array)$this->request->getBodyParam('seller', []));

        // Lightswitches only post when they are on, so the block list is rebuilt from its own
        // defaults rather than merged — otherwise nothing could ever be switched off.
        $postedBlocks = (array)$this->request->getBodyParam('blocks', []);
        $blocks = [];

        foreach (Design::defaultBlocks() as $key => $default) {
            $blocks[$key] = is_bool($default)
                ? (bool)($postedBlocks[$key] ?? false)
                : (string)($postedBlocks[$key] ?? $default);
        }

        $design->blocks = $blocks;

        if (!$service->saveDesign($design)) {
            $this->setFailFlash(Craft::t('donky', 'Couldn’t save the design.'));
            Craft::$app->getUrlManager()->setRouteParams(['design' => $design]);

            return null;
        }

        $this->setSuccessFlash(Craft::t('donky', 'Design saved.'));

        return $this->redirectToPostedUrl($design);
    }

    public function actionDuplicate(): ?Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();

        if (!$plugin->isPro()) {
            throw new ForbiddenHttpException('Donky Lite has one design.');
        }

        $design = $plugin->getDesigns()->getDesignById((int)$this->request->getRequiredBodyParam('id'));

        if ($design === null) {
            throw new NotFoundHttpException('Design not found');
        }

        $copy = $plugin->getDesigns()->duplicateDesign($design);

        if (!$plugin->getDesigns()->saveDesign($copy)) {
            return $this->asFailure(Craft::t('donky', 'Couldn’t duplicate the design.'));
        }

        return $this->asSuccess(Craft::t('donky', 'Design duplicated.'), [], $copy->getCpEditUrl());
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $id = (int)$this->request->getRequiredBodyParam('id');

        try {
            Plugin::getInstance()->getDesigns()->deleteDesignById($id);
        } catch (\Throwable $e) {
            return $this->asFailure($e->getMessage());
        }

        return $this->asSuccess(Craft::t('donky', 'Design deleted.'));
    }

    /**
     * A live preview of a design, drawn against a real order where there is one and a worked
     * example where there is not — so the Designer can be used on a brand new store.
     */
    public function actionPreview(?int $designId = null): Response
    {
        $plugin = Plugin::getInstance();
        $design = $plugin->getDesigns()->getDesignById($designId) ?? $plugin->getDesigns()->getDefaultDesign();
        $type = $plugin->getDocumentTypes()->getPrimaryTypeOfKind(\justinholtweb\donky\helpers\Kind::INVOICE);

        if ($type === null) {
            $type = new DocumentType([
                'id' => 0,
                'name' => Craft::t('donky', 'Invoice'),
                'handle' => 'invoice',
                'kind' => \justinholtweb\donky\helpers\Kind::INVOICE,
            ]);
        }

        $order = Order::find()->isCompleted(true)->orderBy(['dateOrdered' => SORT_DESC])->one();

        $document = new Document([
            'kind' => $type->kind,
            'formattedNumber' => 'INV-' . date('Y') . '-0042',
            'dateIssued' => new \DateTime(),
            'currency' => $order->currency ?? 'USD',
            'status' => Document::STATUS_ISSUED,
        ]);

        $document->setType($type);
        $document->setOrder($order);
        $document->snapshot = $order
            ? $plugin->getDocuments()->buildSnapshot($document, $order)
            : $this->_sampleSnapshot($design);

        // Preview the design the merchant is looking at, even when the type points elsewhere.
        $preview = $plugin->getRenderer()->renderBody($document);
        $html = $plugin->getRenderer()->wrap($design, $preview, Craft::t('donky', 'Design preview'));

        $this->response->format = Response::FORMAT_RAW;
        $this->response->data = $html;
        $this->response->getHeaders()->set('Content-Type', 'text/html; charset=UTF-8');
        // The preview is HTML served from the control panel's own origin. Nothing in a document
        // needs script, so the browser is told to run none of it.
        $this->response->getHeaders()->set('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; img-src * data: blob:; font-src * data:; base-uri 'none'; form-action 'none'; frame-ancestors 'self'");

        return $this->response;
    }

    /**
     * @return array<string, mixed>
     */
    private function _sampleSnapshot(Design $design): array
    {
        return [
            'version' => 1,
            'kind' => 'invoice',
            'issuedAt' => (new \DateTime())->format(DATE_ATOM),
            'number' => 'INV-' . date('Y') . '-0042',
            'currency' => 'USD',
            'seller' => $design->seller,
            'order' => [
                'shortNumber' => 'a1b2c3d',
                'reference' => 'ORD-1042',
                'email' => 'sample@example.com',
                'dateOrdered' => (new \DateTime())->format(DATE_ATOM),
                'status' => Craft::t('donky', 'Processing'),
                'shippingMethod' => Craft::t('donky', 'Standard shipping'),
                'gateway' => Craft::t('donky', 'Sample gateway'),
            ],
            'billingAddress' => ['name' => 'Sam Sample', 'lines' => ['221B Baker Street', 'London NW1 6XE', 'United Kingdom']],
            'shippingAddress' => ['name' => 'Sam Sample', 'lines' => ['221B Baker Street', 'London NW1 6XE', 'United Kingdom']],
            'lineItems' => [
                ['description' => 'Field notebook', 'sku' => 'NB-01', 'qty' => 2, 'price' => 12.5, 'salePrice' => 12.5, 'subtotal' => 25.0, 'total' => 25.0, 'tax' => 2.5, 'options' => ['Cover' => 'Kraft']],
                ['description' => 'Fountain pen', 'sku' => 'PEN-14', 'qty' => 1, 'price' => 48.0, 'salePrice' => 44.0, 'subtotal' => 44.0, 'total' => 44.0, 'tax' => 4.4, 'options' => []],
            ],
            'adjustments' => [
                'shipping' => [['name' => 'Standard shipping', 'amount' => 6.0, 'included' => false]],
                'discount' => [['name' => 'Launch discount', 'amount' => -4.0, 'included' => false]],
                'tax' => [['name' => 'VAT 20%', 'amount' => 6.9, 'included' => false]],
                'other' => [],
            ],
            'totals' => [
                'itemSubtotal' => 69.0,
                'discount' => -4.0,
                'shipping' => 6.0,
                'tax' => 6.9,
                'taxIncluded' => 0.0,
                'total' => 77.9,
                'paid' => 77.9,
                'outstanding' => 0.0,
            ],
            'payments' => [['type' => 'purchase', 'amount' => 77.9, 'gateway' => 'Sample gateway', 'reference' => 'ch_sample', 'date' => (new \DateTime())->format(DATE_ATOM)]],
        ];
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
