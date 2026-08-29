<?php

namespace justinholtweb\donky\services;

use Craft;
use craft\base\Component;
use craft\helpers\FileHelper;
use craft\helpers\Html;
use craft\web\View;
use Dompdf\Dompdf;
use Dompdf\Options;
use justinholtweb\donky\errors\DocumentException;
use justinholtweb\donky\events\RenderEvent;
use justinholtweb\donky\models\Design;
use justinholtweb\donky\models\Document;
use justinholtweb\donky\Plugin;

/**
 * Rendering: documents to HTML, HTML to PDF.
 *
 * Merging happens in HTML, not in PDF. A bundle is one HTML document with a page break between
 * each part, rendered once — so there is no PDF-merging library to install, no second engine to
 * keep in step, and a hundred packing slips come out as one file with continuous page numbers.
 * The trade is that one bundle shares one page setup, taken from the first document's design.
 */
class Renderer extends Component
{
    /**
     * @event RenderEvent Raised before the HTML is turned into a PDF. Set `pdf` to supply your own.
     */
    public const EVENT_BEFORE_RENDER = 'beforeRender';

    /**
     * @event RenderEvent Raised once the PDF exists.
     */
    public const EVENT_AFTER_RENDER = 'afterRender';

    /**
     * The whole HTML document for one or more documents.
     *
     * @param Document[] $documents
     * @param array<string, mixed> $variables
     */
    public function renderHtml(array $documents, array $variables = []): string
    {
        $documents = array_values(array_filter($documents));

        if (!$documents) {
            throw new DocumentException(Craft::t('donky', 'There is nothing to render.'));
        }

        $design = $documents[0]->getType()?->getDesign() ?? Plugin::getInstance()->getDesigns()->getDefaultDesign();
        $bodies = [];

        foreach ($documents as $document) {
            $bodies[] = $this->renderBody($document, $variables);
        }

        $title = count($documents) === 1
            ? ($documents[0]->getLabel())
            : Craft::t('donky', '{count} documents', ['count' => count($documents)]);

        return $this->wrap($design, implode("\n", $bodies), $title);
    }

    /**
     * One document's body, from its type's template.
     *
     * A type with a template path renders that site template — the merchant owns it. A type
     * without one renders the bundled template for its kind, which is a real Twig file in the
     * plugin, meant to be copied into the site's templates and edited.
     *
     * @param array<string, mixed> $variables
     */
    public function renderBody(Document $document, array $variables = []): string
    {
        $type = $document->getType();

        if (!$type) {
            throw new DocumentException(Craft::t('donky', 'Document {id} has no type.', ['id' => $document->id]));
        }

        [$template, $isSiteTemplate] = $type->resolveTemplate();

        $view = Craft::$app->getView();
        $request = Craft::$app->getRequest();
        $oldMode = $view->getTemplateMode();
        $wasCpRequest = $request instanceof \craft\web\Request ? $request->getIsCpRequest() : false;
        $order = $document->getOrder();
        $language = $order?->orderLanguage;
        $originalLanguage = Craft::$app->language;
        $switched = false;

        $vars = array_merge([
            'document' => $document,
            'type' => $type,
            'design' => $type->getDesign(),
            'order' => $order,
            'orders' => $document->getOrders(),
            'snapshot' => $document->snapshot,
            'settings' => Plugin::getInstance()->getSettings(),
        ], $variables);

        try {
            if ($isSiteTemplate) {
                $view->setTemplateMode(View::TEMPLATE_MODE_SITE);

                // Site templates rendered from the CP otherwise build control-panel URLs.
                if ($wasCpRequest) {
                    $request->setIsCpRequest(false);
                }
            } else {
                $view->setTemplateMode(View::TEMPLATE_MODE_CP);
            }

            if ($language && $language !== $originalLanguage && class_exists(\craft\commerce\helpers\Locale::class)) {
                \craft\commerce\helpers\Locale::switchAppLanguage($language);
                $switched = true;
            }

            if (!$view->doesTemplateExist($template)) {
                // Not a DocumentException: a missing template is a misconfiguration, and it must
                // behave like any other template failure — an error block on the page, so that a
                // mistyped path cannot take an order confirmation email down with it.
                throw new \RuntimeException(Craft::t('donky', 'The template “{template}” does not exist.', ['template' => $template]));
            }

            return $view->renderTemplate($template, $vars);
        } catch (DocumentException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // A template error must produce a document that says so, not a 500 in the middle of a
            // checkout email. The error is logged in full; the PDF carries a short version.
            Craft::$app->getErrorHandler()->logException($e);

            return Html::tag('div', Html::tag('h1', Craft::t('donky', 'This document could not be rendered.'))
                . Html::tag('p', Html::encode($e->getMessage()))
                . Html::tag('p', Html::encode($template), ['class' => 'muted']), ['class' => 'dk-doc']);
        } finally {
            if ($switched) {
                \craft\commerce\helpers\Locale::switchAppLanguage($originalLanguage);
            }

            $view->setTemplateMode($oldMode);

            if ($wasCpRequest && $request instanceof \craft\web\Request) {
                $request->setIsCpRequest(true);
            }
        }
    }

    /**
     * The page shell: doctype, the design's stylesheet inlined, and the bodies.
     *
     * The stylesheet is inlined rather than registered as an asset bundle on purpose — calling
     * `head()` in a print route invites every other installed plugin to inject its chrome into
     * the PDF.
     */
    public function wrap(Design $design, string $body, string $title = ''): string
    {
        $css = $design->getCss();
        $titleHtml = Html::encode($title);

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>$titleHtml</title>
<style>
$css
</style>
</head>
<body>
$body
</body>
</html>
HTML;
    }

    /**
     * Render documents to PDF bytes.
     *
     * @param Document[] $documents
     * @param array<string, mixed> $variables
     */
    public function renderPdf(array $documents, array $variables = []): string
    {
        $html = $this->renderHtml($documents, $variables);

        $event = new RenderEvent(['documents' => $documents, 'html' => $html]);
        $this->trigger(self::EVENT_BEFORE_RENDER, $event);

        if ($event->pdf !== null) {
            return $event->pdf;
        }

        $pdf = $this->htmlToPdf($event->html ?? $html, $documents[0]->getType()?->getDesign());

        $this->trigger(self::EVENT_AFTER_RENDER, new RenderEvent([
            'documents' => $documents,
            'html' => $event->html ?? $html,
            'pdf' => $pdf,
        ]));

        if (Plugin::getInstance()->getSettings()->logRenders) {
            Craft::info(sprintf('Rendered %d document(s), %d bytes', count($documents), strlen($pdf)), 'donky');
        }

        return $pdf;
    }

    /**
     * Turn HTML into PDF bytes.
     */
    public function htmlToPdf(string $html, ?Design $design = null): string
    {
        if (!class_exists(Dompdf::class)) {
            throw new DocumentException(Craft::t('donky', 'dompdf is not installed. Craft Commerce normally provides it; run `composer require dompdf/dompdf` to add it back.'));
        }

        $design ??= Plugin::getInstance()->getDesigns()->getDefaultDesign();
        $settings = Plugin::getInstance()->getSettings();
        $paths = Craft::$app->getPath();

        $tempDir = $paths->getTempPath() . DIRECTORY_SEPARATOR . 'donky';
        $fontCache = $paths->getCachePath() . DIRECTORY_SEPARATOR . 'donky_fonts';

        FileHelper::createDirectory($tempDir);
        FileHelper::createDirectory($fontCache);

        $options = new Options();
        $options->setTempDir($tempDir);
        $options->setFontCache($fontCache);
        $options->setFontDir($fontCache);
        $options->setIsRemoteEnabled($settings->allowRemoteImages);
        $options->setDefaultPaperSize($design->paperSize);
        $options->setDefaultPaperOrientation($design->paperOrientation);
        $options->setIsHtml5ParserEnabled(true);

        // dompdf refuses to read a local image outside its chroot, which is how a perfectly
        // correct logo path renders as nothing at all.
        $options->setChroot(array_values(array_unique(array_filter([
            Craft::getAlias('@webroot', false) ?: null,
            Craft::getAlias('@storage', false) ?: null,
            Craft::getAlias('@root', false) ?: null,
            $tempDir,
        ]))));

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper($design->paperSize, $design->paperOrientation);
        $dompdf->render();

        $output = $dompdf->output();

        if ($output === null) {
            throw new DocumentException(Craft::t('donky', 'dompdf produced no output.'));
        }

        return $output;
    }

    /**
     * The HTML for the CP preview: the same body, in a page that is meant to be looked at in a
     * browser and printed from there. Deliberately the same markup as the PDF, so what you preview
     * is what you get.
     *
     * @param Document[] $documents
     */
    public function renderPreview(array $documents): string
    {
        $html = $this->renderHtml($documents);

        // A screen preview wants page-sized white boxes on grey, which is not how @page works.
        $shim = <<<CSS
<style>
html { background: #E9EBEF; }
body { padding: 18px; }
.dk-doc { background: #fff; box-shadow: 0 1px 4px rgba(0,0,0,.18); padding: 26px 30px; margin: 0 auto 18px; max-width: 820px; }
.dk-footer { position: static; margin-top: 18px; }
</style>
CSS;

        return str_replace('</head>', $shim . '</head>', $html);
    }
}
