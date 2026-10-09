<?php

namespace justinholtweb\donky\console\controllers;

use craft\commerce\elements\Order;
use craft\console\Controller;
use craft\helpers\Console;
use craft\helpers\FileHelper;
use justinholtweb\donky\models\Document;
use justinholtweb\donky\models\DocumentType;
use justinholtweb\donky\Plugin;
use yii\console\ExitCode;

/**
 * Issue, print and audit documents from the command line.
 *
 * Every bulk or maintenance action the control panel has is here too, and goes through the same
 * service method: printing a selection, voiding, restoring, reissuing, deleting and the CSV export.
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
     * @var string|null Where to write a rendered PDF or CSV.
     */
    public ?string $out = null;

    /**
     * @var string|null Comma-separated order numbers, short numbers or references, for `print`.
     */
    public ?string $orders = null;

    /**
     * @var string|null Comma-separated order IDs, for `print`.
     */
    public ?string $orderIds = null;

    /**
     * @var bool Whether `print` issues the documents that are missing, as the order index does.
     */
    public bool $issueMissing = true;

    /**
     * @var string|null Why a document is being voided.
     */
    public ?string $reason = null;

    /**
     * @var string|null A kind to export, e.g. `invoice`.
     */
    public ?string $kind = null;

    /**
     * @var string|null A status to export: `issued` or `void`.
     */
    public ?string $status = null;

    /**
     * @var int|null The number the counter should hand out next.
     */
    public ?int $next = null;

    /**
     * @var string|null The counter's reset period (`2026`, `2026-10`, `2026-10-09`). Defaults to the current one.
     */
    public ?string $period = null;

    /**
     * @var bool Let `set-next-number` move a counter backwards.
     */
    public bool $force = false;

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
            'print' => array_merge($options, ['type', 'orders', 'orderIds', 'issueMissing', 'out', 'dryRun']),
            'void' => array_merge($options, ['reason', 'dryRun']),
            'restore', 'reissue', 'delete' => array_merge($options, ['dryRun']),
            'export' => array_merge($options, ['type', 'kind', 'status', 'out']),
            'set-next-number' => array_merge($options, ['type', 'next', 'period', 'force', 'dryRun']),
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

    /**
     * Print a selection of orders as one PDF — the order index's “Print …” action. Pro.
     *
     * Issues whatever is missing first, unless `--issue-missing=0`.
     *
     * donky/documents/print --type=packingSlip --orders=ORD-1042,ORD-1043 --out=/tmp/slips.pdf
     */
    public function actionPrint(): int
    {
        $plugin = Plugin::getInstance();
        $documentType = $this->_type();

        if ($documentType === null) {
            return $this->type ? ExitCode::DATAERR : ExitCode::USAGE;
        }

        $orderIds = [];

        foreach ($this->_list($this->orderIds) as $id) {
            if (!ctype_digit($id)) {
                $this->stderr("“{$id}” is not an order ID.\n", Console::FG_RED);

                return ExitCode::USAGE;
            }

            $orderIds[] = (int)$id;
        }

        foreach ($this->_list($this->orders) as $needle) {
            $order = $this->_findOrder($needle);

            if ($order === null) {
                $this->stderr("No order matching “{$needle}”.\n", Console::FG_RED);

                return ExitCode::DATAERR;
            }

            $orderIds[] = (int)$order->id;
        }

        if (!$orderIds) {
            $this->stderr("Name some orders with --orders or --order-ids.\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        if ($this->dryRun) {
            if (!$plugin->isPro()) {
                $this->stderr("Bulk printing is a Donky Pro feature.\n", Console::FG_RED);

                return ExitCode::UNSPECIFIED_ERROR;
            }

            $plan = $plugin->getBulk()->plan($orderIds, $documentType);

            if ($documentType->isAggregate()) {
                $this->stdout(sprintf("would issue one %s covering %d order(s) (dry run)\n", $documentType->name, count($plan['orders'])));
            } else {
                $this->stdout(sprintf(
                    "%d order(s): %d already have a %s, %d %s (dry run)\n",
                    count($plan['orders']),
                    $plan['existing'],
                    $documentType->name,
                    $plan['missing'],
                    $this->issueMissing ? 'would be issued' : 'would be skipped'
                ));
            }

            if ($plan['overLimit']) {
                $this->stderr("That is over the bulk print limit of {$plan['limit']}.\n", Console::FG_RED);

                return ExitCode::UNSPECIFIED_ERROR;
            }

            return ExitCode::OK;
        }

        try {
            $result = $plugin->getBulk()->print($orderIds, $documentType, $this->issueMissing);
        } catch (\Throwable $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        foreach ($result['skipped'] as $orderId => $why) {
            $this->stderr("order {$orderId} skipped: {$why}\n", Console::FG_YELLOW);
        }

        $this->stdout(sprintf("%d document(s), %d newly issued\n", count($result['documents']), $result['issued']));
        $code = $this->_write($result['pdf'], $this->out ?: (getcwd() . DIRECTORY_SEPARATOR . $result['fileName']));

        // A skipped order is a failure only when it was meant to be printed.
        return $result['skipped'] && $this->issueMissing ? ExitCode::UNSPECIFIED_ERROR : $code;
    }

    /**
     * Void a document. It keeps its number.
     *
     * donky/documents/void INV-2026-0042 --reason="Wrong address"
     */
    public function actionVoid(string $number): int
    {
        $document = $this->_document($number);

        if ($document === null) {
            return ExitCode::DATAERR;
        }

        if ($document->status === Document::STATUS_VOID) {
            $this->stdout("{$document->getLabel()} is void already.\n");

            return ExitCode::OK;
        }

        if ($this->dryRun) {
            $this->stdout("would void {$document->getLabel()} (dry run)\n");

            return ExitCode::OK;
        }

        if (!Plugin::getInstance()->getDocuments()->voidDocument($document, trim((string)$this->reason) ?: null)) {
            $this->stderr("Could not void {$document->getLabel()}.\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Voided {$document->getLabel()}. It keeps its number.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Restore a void document.
     */
    public function actionRestore(string $number): int
    {
        $document = $this->_document($number);

        if ($document === null) {
            return ExitCode::DATAERR;
        }

        if ($document->status !== Document::STATUS_VOID) {
            $this->stdout("{$document->getLabel()} is not void.\n");

            return ExitCode::OK;
        }

        if ($this->dryRun) {
            $this->stdout("would restore {$document->getLabel()} (dry run)\n");

            return ExitCode::OK;
        }

        if (!Plugin::getInstance()->getDocuments()->restoreDocument($document)) {
            $this->stderr("Could not restore {$document->getLabel()}.\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Restored {$document->getLabel()}.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Issue a fresh document in place of this one, and void this one.
     *
     * donky/documents/reissue INV-2026-0042
     */
    public function actionReissue(string $number): int
    {
        $plugin = Plugin::getInstance();
        $document = $this->_document($number);

        if ($document === null) {
            return ExitCode::DATAERR;
        }

        if ($this->dryRun) {
            $type = $document->getType();
            $next = $type ? $plugin->getNumbers()->preview($type) : null;
            $this->stdout(sprintf(
                "would void %s and issue %s (dry run)\n",
                $document->getLabel(),
                $next ? "a fresh one as {$next}" : 'a fresh one'
            ));

            return ExitCode::OK;
        }

        try {
            $fresh = $plugin->getDocuments()->reissue($document, Document::SOURCE_CONSOLE);
        } catch (\Throwable $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Reissued {$document->getLabel()} as {$fresh->getLabel()}.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Delete a document. Its number stays spent.
     *
     * Asks first when run interactively; `--interactive=0` skips the question.
     */
    public function actionDelete(string $number): int
    {
        $document = $this->_document($number);

        if ($document === null) {
            return ExitCode::DATAERR;
        }

        if ($this->dryRun) {
            $this->stdout("would delete {$document->getLabel()} (dry run)\n");

            return ExitCode::OK;
        }

        if ($this->interactive && !$this->confirm("Delete {$document->getLabel()}? Its number stays spent.")) {
            $this->stdout("Nothing deleted.\n");

            return ExitCode::OK;
        }

        if (!Plugin::getInstance()->getDocuments()->deleteDocumentById((int)$document->id)) {
            $this->stderr("Could not delete {$document->getLabel()}.\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Deleted {$document->getLabel()}. Its number stays spent.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Export the ledger as CSV — the Documents screen's “Export CSV”. Pro.
     *
     * donky/documents/export --kind=invoice --status=issued --out=/tmp/ledger.csv
     *
     * Without `--out` the CSV goes to standard output.
     */
    public function actionExport(): int
    {
        $plugin = Plugin::getInstance();
        $criteria = [
            'kind' => $this->kind ?: null,
            'status' => $this->status ?: null,
        ];

        if ($this->type) {
            $documentType = $this->_type();

            if ($documentType === null) {
                return ExitCode::DATAERR;
            }

            $criteria['typeId'] = $documentType->id;
        }

        try {
            $csv = $plugin->getDocuments()->exportCsv($criteria);
        } catch (\Throwable $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        if (!$this->out) {
            $this->stdout($csv);

            return ExitCode::OK;
        }

        FileHelper::writeToFile($this->out, $csv);
        $this->stdout(sprintf("Wrote %s (%d row(s))\n", $this->out, max(0, substr_count($csv, "\n") - 1)), Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Set the number a type hands out next.
     *
     * Moving forward is always allowed and leaves a gap. Moving backwards can put a number on two
     * documents, so it needs `--force` and, when interactive, a yes.
     *
     * donky/documents/set-next-number --type=invoice --next=1000
     */
    public function actionSetNextNumber(): int
    {
        $numbers = Plugin::getInstance()->getNumbers();
        $documentType = $this->_type();

        if ($documentType === null) {
            return $this->type ? ExitCode::DATAERR : ExitCode::USAGE;
        }

        if ($this->next === null || $this->next < 1) {
            $this->stderr("--next must be a positive number.\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        if (!$documentType->usesOwnCounter()) {
            $this->stderr("“{$documentType->handle}” does not use Donky’s own sequence, so it has no counter.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $period = $this->period ?? $numbers->periodKey($documentType);
        $current = $numbers->getCounter($documentType, $period, false)->nextNumber;
        $label = $period === '' ? 'the counter' : "the {$period} counter";

        if ($this->next === $current) {
            $this->stdout(ucfirst($label) . " already hands out {$current} next.\n");

            return ExitCode::OK;
        }

        $backwards = $this->next < $current;

        if ($backwards && !$this->force) {
            $this->stderr("That would move {$label} back from {$current} to {$this->next}, which can put one number on two documents. Pass --force if that is what you want.\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        if ($this->dryRun) {
            $this->stdout("would move {$label} of {$documentType->handle} from {$current} to {$this->next} (dry run)\n");

            return ExitCode::OK;
        }

        if ($backwards && $this->interactive && !$this->confirm("Rewind {$label} from {$current} to {$this->next}?")) {
            $this->stdout("Nothing changed.\n");

            return ExitCode::OK;
        }

        if ($backwards) {
            $numbers->forceNextNumber($documentType, $period, $this->next);
        } else {
            $numbers->setNextNumber($documentType, $period, $this->next);
        }

        $this->stdout("Moved {$label} of {$documentType->handle} from {$current} to {$this->next}.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * The `--type` document type, reporting why there is not one.
     */
    private function _type(): ?DocumentType
    {
        if (!$this->type) {
            $this->stderr("--type is required.\n", Console::FG_RED);

            return null;
        }

        $documentType = Plugin::getInstance()->getDocumentTypes()->getTypeByHandle($this->type);

        if ($documentType === null) {
            $this->stderr("No document type with the handle “{$this->type}”.\n", Console::FG_RED);
        }

        return $documentType;
    }

    private function _document(string $number): ?Document
    {
        $document = Plugin::getInstance()->getDocuments()->getDocumentByNumber($number);

        if ($document === null) {
            $this->stderr("No document numbered “{$number}”.\n", Console::FG_RED);
        }

        return $document;
    }

    /**
     * @return string[]
     */
    private function _list(?string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string)$value)), static fn(string $v) => $v !== ''));
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
