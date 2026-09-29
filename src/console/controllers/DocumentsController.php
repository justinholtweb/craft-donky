<?php

namespace justinholtweb\donky\console\controllers;

use craft\commerce\elements\Order;
use craft\console\Controller;
use craft\helpers\Console;
use craft\helpers\FileHelper;
use justinholtweb\donky\models\Document;
use justinholtweb\donky\Plugin;
use yii\console\ExitCode;

/**
 * Issue, print and audit documents from the command line.
 */
class DocumentsController extends Controller
{
    /**
     * @var string|null The document type handle to work with.
     */
    public ?string $type = null;

    /**
     * @var string|null An order number, short number or reference.
     */
    public ?string $order = null;

    /**
     * @var bool Report what would happen without issuing anything.
     */
    public bool $dryRun = false;

    /**
     * @var int How many orders a backfill works through.
     */
    public int $limit = 100;

    /**
     * @var string|null Where to write a rendered PDF.
     */
    public ?string $out = null;

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);

        return match ($actionID) {
            'issue' => array_merge($options, ['type', 'order', 'out']),
            'backfill' => array_merge($options, ['type', 'limit', 'dryRun']),
            'render' => array_merge($options, ['out']),
            default => $options,
        };
    }

    /**
     * List the document types on this install.
     */
    public function actionTypes(): int
    {
        $plugin = Plugin::getInstance();

        foreach ($plugin->getDocumentTypes()->getAllTypes() as $documentType) {
            $licensed = $plugin->getDocumentTypes()->isLicensed($documentType);

            $this->stdout(sprintf(
                "%-20s %-14s %-9s %-22s %s\n",
                $documentType->handle,
                $documentType->kind,
                $documentType->enabled ? 'enabled' : 'disabled',
                (string)$plugin->getNumbers()->preview($documentType),
                $licensed ? '' : '(Pro)'
            ));
        }

        return ExitCode::OK;
    }

    /**
     * Issue one document.
     *
     * donky/documents/issue --type=invoice --order=ORD-1042
     */
    public function actionIssue(): int
    {
        $plugin = Plugin::getInstance();

        if (!$this->type || !$this->order) {
            $this->stderr("Both --type and --order are required.\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        $documentType = $plugin->getDocumentTypes()->getTypeByHandle($this->type);

        if ($documentType === null) {
            $this->stderr("No document type with the handle “{$this->type}”.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $order = $this->_findOrder($this->order);

        if ($order === null) {
            $this->stderr("No order matching “{$this->order}”.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        try {
            $document = $plugin->getDocuments()->ensure($documentType, $order, Document::SOURCE_CONSOLE);
        } catch (\Throwable $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Issued {$document->getLabel()}\n", Console::FG_GREEN);

        if ($this->out) {
            return $this->_write($plugin->getRenderer()->renderPdf([$document]), $this->out);
        }

        return ExitCode::OK;
    }

    /**
     * Issue a type's document for every completed order that does not have one yet.
     *
     * Runs oldest order first so the numbers come out in the order the orders did, which is the
     * whole point of backfilling an invoice sequence.
     */
    public function actionBackfill(): int
    {
        $plugin = Plugin::getInstance();

        if (!$this->type) {
            $this->stderr("--type is required.\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        $documentType = $plugin->getDocumentTypes()->getTypeByHandle($this->type);

        if ($documentType === null) {
            $this->stderr("No document type with the handle “{$this->type}”.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        if (!$plugin->isPro()) {
            $this->stderr("Backfilling is a Donky Pro feature.\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $orders = Order::find()
            ->isCompleted(true)
            ->storeId($documentType->storeId)
            ->orderBy(['dateOrdered' => SORT_ASC])
            ->limit($this->limit * 4)
            ->all();

        $issued = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($orders as $order) {
            if ($issued >= $this->limit) {
                break;
            }

            if ($plugin->getDocuments()->getDocumentForOrder($documentType, $order) !== null) {
                $skipped++;
                continue;
            }

            if (!$plugin->getDocumentTypes()->matches($documentType, $order)) {
                $skipped++;
                continue;
            }

            if ($this->dryRun) {
                $this->stdout("would issue for {$order->getShortNumber()}\n");
                $issued++;
                continue;
            }

            try {
                $document = $plugin->getDocuments()->issue($documentType, $order, [
                    'source' => Document::SOURCE_CONSOLE,
                    // Date the document when the order happened, not when the backfill ran, or a
                    // yearly sequence puts last year's invoices in this year's numbers.
                    'issuedAt' => $order->dateOrdered ?? new \DateTime(),
                ]);

                $this->stdout("{$document->getLabel()}  ←  {$order->getShortNumber()}\n");
                $issued++;
            } catch (\Throwable $e) {
                $this->stderr("{$order->getShortNumber()}: {$e->getMessage()}\n", Console::FG_RED);
                $failed++;
            }
        }

        $this->stdout(sprintf(
            "\n%d issued, %d already had one, %d failed%s\n",
            $issued,
            $skipped,
            $failed,
            $this->dryRun ? ' (dry run)' : ''
        ), $failed ? Console::FG_YELLOW : Console::FG_GREEN);

        return $failed ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * Render an existing document to a file.
     *
     * donky/documents/render INV-2026-0042 --out=/tmp/invoice.pdf
     */
    public function actionRender(string $number): int
    {
        $plugin = Plugin::getInstance();
        $document = $plugin->getDocuments()->getDocumentByNumber($number);

        if ($document === null) {
            $this->stderr("No document numbered “{$number}”.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $path = $this->out ?: (getcwd() . DIRECTORY_SEPARATOR . $document->getDownloadFileName());

        return $this->_write($plugin->getRenderer()->renderPdf([$document]), $path);
    }

    private function _write(string $pdf, string $path): int
    {
        FileHelper::writeToFile($path, $pdf);
        $this->stdout("Wrote $path (" . number_format(strlen($pdf)) . " bytes)\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    private function _findOrder(string $needle): ?Order
    {
        return Order::find()->reference($needle)->status(null)->one()
            ?? Order::find()->number($needle)->status(null)->one()
            ?? Order::find()->shortNumber($needle)->status(null)->one();
    }
}
