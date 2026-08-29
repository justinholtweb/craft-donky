<?php
/**
 * Donky integration checks.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-donky/tests/integration/checks.php
 *
 * Idempotent and self-cleaning: every design, type, document, order and product it creates it
 * deletes again, whether the run passes or not. Nothing here touches an order it did not create.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\base\Element;
use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\events\RegisterElementActionsEvent;
use justinholtweb\donky\db\Table;
use justinholtweb\donky\elements\actions\PrintDocuments;
use justinholtweb\donky\errors\DocumentException;
use justinholtweb\donky\helpers\Kind;
use justinholtweb\donky\models\Design;
use justinholtweb\donky\models\Document;
use justinholtweb\donky\models\DocumentType;
use justinholtweb\donky\models\Rule;
use justinholtweb\donky\Plugin;
use justinholtweb\donky\twig\DonkyVariable;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();

        if ($result === true) {
            $passed++;
            echo "  ✓ $label\n";
            return;
        }

        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : 'returned ' . var_export($result, true)) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage() . "\n    " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

$plugin = Plugin::getInstance();
$commerce = Commerce::getInstance();
$types = $plugin->getDocumentTypes();
$designs = $plugin->getDesigns();
$documents = $plugin->getDocuments();
$numbers = $plugin->getNumbers();
$renderer = $plugin->getRenderer();
$storeId = $commerce->getStores()->getPrimaryStore()->id;

// Craft handles are alphanumeric, so the fixture suffix cannot carry a hyphen.
$suffix = substr(md5((string)microtime(true)), 0, 6);
$tag = 'DK' . strtoupper($suffix);

$originalEdition = $plugin->edition;
$originalSettings = clone $plugin->getSettings();
$createdTypes = [];
$createdDesigns = [];
$createdRules = [];
$createdOrders = [];
$createdProducts = [];

/**
 * Each fixture type gets its own prefix. Types share one namespace of formatted numbers, so a
 * shared format makes one fixture's numbers collide with the next one's.
 */
function prefix(string $handle): string
{
    global $tag;

    return $tag . strtoupper($handle);
}

function makeType(string $handle, array $attributes = []): DocumentType
{
    global $createdTypes, $storeId, $types, $designs;

    $type = new DocumentType(array_merge([
        'storeId' => $storeId,
        'name' => "Check $handle",
        'handle' => $handle,
        'kind' => Kind::INVOICE,
        'enabled' => true,
        'designId' => $designs->getDefaultDesign()->id,
        'numberSource' => DocumentType::SOURCE_OWN,
        'format' => prefix($handle) . '-{number}',
        'startNumber' => 1,
        'increment' => 1,
        'padLength' => 0,
        'resetPolicy' => DocumentType::RESET_NEVER,
        'issueTrigger' => DocumentType::TRIGGER_MANUAL,
    ], $attributes));

    if (!$types->saveType($type)) {
        throw new RuntimeException("Could not save type $handle: " . json_encode($type->getErrors()));
    }

    $createdTypes[] = $type;

    return $type;
}

function makeProduct(string $sku, float $price): Product
{
    global $createdProducts;

    $productType = Commerce::getInstance()->getProductTypes()->getAllProductTypes()[0];

    $product = new Product();
    $product->typeId = $productType->id;
    $product->title = "Donky fixture $sku";
    $product->enabled = true;

    $variant = new Variant();
    $variant->sku = $sku;
    $variant->basePrice = $price;
    $variant->isDefault = true;

    $product->setVariants([$variant]);

    if (!Craft::$app->getElements()->saveElement($product)) {
        throw new RuntimeException('Could not save fixture product: ' . json_encode($product->getErrors()));
    }

    $createdProducts[] = $product;

    return $product;
}

function makeOrder(Variant $variant, int $qty = 1): Order
{
    global $createdOrders, $storeId;

    $order = new Order();
    $order->storeId = $storeId;
    $order->orderSiteId = Craft::$app->getSites()->getPrimarySite()->id;
    $order->number = Commerce::getInstance()->getCarts()->generateCartNumber();
    $order->email = 'donky-checks@example.com';

    if (!Craft::$app->getElements()->saveElement($order, false)) {
        throw new RuntimeException('Could not save cart: ' . json_encode($order->getErrors()));
    }

    $createdOrders[] = $order;

    $order->setLineItems([
        Commerce::getInstance()->getLineItems()->createLineItem($order, $variant->id, [], $qty),
    ]);

    if (!Craft::$app->getElements()->saveElement($order, false)) {
        throw new RuntimeException('Could not save cart line items: ' . json_encode($order->getErrors()));
    }

    if (!$order->markAsComplete()) {
        throw new RuntimeException('Could not complete order: ' . json_encode($order->getErrors()));
    }

    return Order::find()->id($order->id)->status(null)->one();
}

function documentCount(int $typeId): int
{
    return (int)(new Query())->from(Table::DOCUMENTS)->where(['typeId' => $typeId])->count();
}

try {
    Craft::$app->getPlugins()->switchEdition('donky', Plugin::EDITION_PRO);
    $plugin = Plugin::getInstance();

    // Automatic issuing is off for the whole run: every check that expects a document to exist
    // creates it on purpose, and a trigger firing behind our back would make the counts lie.
    $plugin->getSettings()->autoIssue = false;
    $plugin->getSettings()->attachToEmails = false;

    $product = makeProduct($tag . '-SKU1', 25.00);
    $variant = $product->getVariants()[0];
    $order = makeOrder($variant, 2);

    section('Install');

    check('the install seeded a default design', function() use ($designs) {
        $design = $designs->getDefaultDesign();

        return $design->id !== null ?: 'no default design exists';
    });

    check('the install seeded an invoice and a packing slip', function() use ($types, $storeId) {
        $kinds = array_map(static fn(DocumentType $t) => $t->kind, $types->getTypesForStore($storeId));

        return (in_array(Kind::INVOICE, $kinds, true) && in_array(Kind::PACKING_SLIP, $kinds, true))
            ?: 'seeded kinds were ' . json_encode(array_values($kinds));
    });

    check('seeded types do not issue anything on their own', function() use ($types, $storeId) {
        foreach ($types->getTypesForStore($storeId) as $type) {
            if ($type->issueTrigger !== DocumentType::TRIGGER_MANUAL && $type->handle === 'invoice') {
                return 'the seeded invoice issues on ' . $type->issueTrigger;
            }
        }

        return true;
    });

    section('Numbering');

    $invoiceType = makeType('inv', ['padLength' => 4]);

    check('the first number is the start number, padded', function() use ($invoiceType, $numbers) {
        $expected = prefix('inv') . '-0001';

        return $numbers->preview($invoiceType) === $expected
            ?: 'previewed ' . $numbers->preview($invoiceType) . ', expected ' . $expected;
    });

    check('a preview does not spend a number', function() use ($invoiceType, $numbers) {
        $numbers->preview($invoiceType);
        $numbers->preview($invoiceType);

        return $numbers->preview($invoiceType) === prefix('inv') . '-0001'
            ?: 'the counter moved during a preview';
    });

    check('issuing hands out consecutive numbers', function() use ($invoiceType, $documents, $variant) {
        $first = $documents->issue($invoiceType, makeOrder($variant));
        $second = $documents->issue($invoiceType, makeOrder($variant));

        return ($first->number === 1 && $second->number === 2)
            ?: "got {$first->number} and {$second->number}";
    });

    check('the formatted number matches the format', function() use ($invoiceType, $documents, $variant) {
        $document = $documents->issue($invoiceType, makeOrder($variant));

        return $document->formattedNumber === prefix('inv') . '-000' . $document->number
            ?: 'got ' . $document->formattedNumber;
    });

    check('date tokens resolve', function() use ($numbers, $storeId) {
        $type = new DocumentType([
            'storeId' => $storeId,
            'name' => 'x',
            'handle' => 'x',
            'format' => 'X-{yyyy}{mm}-{number}',
            'padLength' => 3,
        ]);

        $at = new DateTime('2026-03-09');
        $expected = 'X-202603-007';

        return $numbers->format($type, 7, $at) === $expected
            ?: 'got ' . $numbers->format($type, 7, $at);
    });

    check('increment is honoured', function() use ($documents, $variant) {
        $type = makeType('step', ['increment' => 10, 'startNumber' => 100]);
        $a = $documents->issue($type, makeOrder($variant));
        $b = $documents->issue($type, makeOrder($variant));

        return ($a->number === 100 && $b->number === 110) ?: "got {$a->number} and {$b->number}";
    });

    check('a monthly reset buckets by month', function() use ($documents, $numbers, $variant) {
        $type = makeType('monthly', ['resetPolicy' => DocumentType::RESET_MONTHLY]);

        $january = $documents->issue($type, makeOrder($variant), ['issuedAt' => new DateTime('2026-01-15')]);
        $february = $documents->issue($type, makeOrder($variant), ['issuedAt' => new DateTime('2026-02-15')]);

        return ($january->number === 1 && $february->number === 1
                && $january->periodKey === '2026-01' && $february->periodKey === '2026-02')
            ?: "got {$january->number}/{$january->periodKey} and {$february->number}/{$february->periodKey}";
    });

    check('a counter never rewinds', function() use ($numbers, $variant, $documents) {
        $type = makeType('norewind');
        $documents->issue($type, makeOrder($variant));
        $numbers->setNextNumber($type, '', 1);

        return $numbers->getCounter($type, '')->nextNumber === 2
            ?: 'the counter went backwards to ' . $numbers->getCounter($type, '')->nextNumber;
    });

    check('a spent number is never handed out twice', function() use ($numbers, $variant, $documents) {
        $type = makeType('unique');
        $first = $documents->issue($type, makeOrder($variant));

        // Force the counter back over a number that is already on a document.
        $numbers->forceNextNumber($type, '', $first->number);
        $second = $documents->issue($type, makeOrder($variant));

        return $second->number !== $first->number
            ?: 'both documents got number ' . $first->number;
    });

    check('a type with no numbering issues an unnumbered document', function() use ($documents, $variant) {
        $type = makeType('slip', [
            'kind' => Kind::PACKING_SLIP,
            'numberSource' => DocumentType::SOURCE_NONE,
        ]);

        $document = $documents->issue($type, makeOrder($variant));

        return ($document->number === null && $document->formattedNumber === null)
            ?: 'got number ' . var_export($document->number, true);
    });

    check('a type can borrow the order reference', function() use ($documents, $variant) {
        $type = makeType('borrow', [
            'numberSource' => DocumentType::SOURCE_ORDER_REFERENCE,
            'format' => prefix('borrow') . '-{number}',
        ]);

        $borrowed = makeOrder($variant);
        $document = $documents->issue($type, $borrowed);
        $expected = prefix('borrow') . '-' . ($borrowed->reference ?: $borrowed->getShortNumber());

        return ($document->formattedNumber === $expected && $document->number === null)
            ?: 'got ' . $document->formattedNumber . ', expected ' . $expected;
    });

    check('a format with no {number} token is rejected', function() use ($storeId) {
        $type = new DocumentType([
            'storeId' => $storeId,
            'name' => 'no token',
            'handle' => 'notoken',
            'format' => 'INV-{yyyy}',
        ]);

        return (!$type->validate() && $type->hasErrors('format')) ?: 'the format validated';
    });

    section('Documents');

    check('issuing twice through ensure() reuses the first document', function() use ($documents, $invoiceType, $variant) {
        $order = makeOrder($variant);
        $first = $documents->ensure($invoiceType, $order);
        $second = $documents->ensure($invoiceType, $order);

        return $first->id === $second->id ?: 'ensure() issued a second document';
    });

    check('the snapshot freezes the line items', function() use ($documents, $invoiceType, $variant, $tag) {
        $order = makeOrder($variant, 3);
        $document = $documents->issue($invoiceType, $order);
        $line = $document->snapshot['lineItems'][0] ?? null;

        return ($line && $line['qty'] === 3 && $line['sku'] === $tag . '-SKU1')
            ?: 'snapshot line was ' . json_encode($line);
    });

    check('the snapshot survives the order changing afterwards', function() use ($documents, $invoiceType, $variant) {
        $order = makeOrder($variant, 1);
        $document = $documents->issue($invoiceType, $order);
        $before = $document->snapshot['totals']['total'];

        $order->setLineItems([
            Commerce::getInstance()->getLineItems()->createLineItem($order, $variant->id, [], 9),
        ]);
        Craft::$app->getElements()->saveElement($order, false);

        $reloaded = Plugin::getInstance()->getDocuments()->getDocumentById($document->id);

        return abs($reloaded->snapshot['totals']['total'] - $before) < 0.001
            ?: "the snapshot moved from $before to " . $reloaded->snapshot['totals']['total'];
    });

    check('the snapshot records the addresses as printable lines', function() use ($documents, $invoiceType, $variant) {
        $document = $documents->issue($invoiceType, makeOrder($variant));

        return (array_key_exists('lines', $document->snapshot['billingAddress'] ?? [])
                && array_key_exists('lines', $document->snapshot['shippingAddress'] ?? []))
            ?: 'the address blocks are missing';
    });

    check('the document total comes from the snapshot', function() use ($documents, $invoiceType, $variant) {
        $order = makeOrder($variant, 2);
        $document = $documents->issue($invoiceType, $order);

        return abs($document->total - $order->getTotalPrice()) < 0.001
            ?: "document total {$document->total} vs order " . $order->getTotalPrice();
    });

    check('the filename is built from the number', function() use ($documents, $invoiceType, $variant) {
        $document = $documents->issue($invoiceType, makeOrder($variant));

        return str_contains($document->getDownloadFileName(), $document->formattedNumber)
            && str_ends_with($document->getDownloadFileName(), '.pdf')
            ?: 'filename was ' . $document->getDownloadFileName();
    });

    check('a broken filename template still produces a document', function() use ($documents, $variant) {
        $type = makeType('badname', ['filenameFormat' => '{{ nope.explode() }}']);
        $document = $documents->issue($type, makeOrder($variant));

        return $document->fileName !== null && $document->fileName !== ''
            ?: 'no filename was produced';
    });

    check('voiding keeps the number', function() use ($documents, $variant) {
        $type = makeType('voidkeep');
        $document = $documents->issue($type, makeOrder($variant));
        $number = $document->number;

        $documents->voidDocument($document, 'because');
        $reloaded = $documents->getDocumentById($document->id);

        return ($reloaded->isVoid() && $reloaded->number === $number)
            ?: 'the void document lost its number';
    });

    check('a voided number is not reissued', function() use ($documents, $numbers, $variant) {
        $type = makeType('voidgap');
        $first = $documents->issue($type, makeOrder($variant));
        $documents->voidDocument($first);

        $second = $documents->issue($type, makeOrder($variant));

        return $second->number > $first->number
            ?: "the voided number {$first->number} came back as {$second->number}";
    });

    check('documents for an order come back newest first', function() use ($documents, $invoiceType, $variant) {
        $order = makeOrder($variant);
        $documents->issue($invoiceType, $order);
        $documents->issue($invoiceType, $order);

        $found = $documents->getDocumentsForOrder((int)$order->id, $invoiceType->id);

        return (count($found) === 2 && $found[0]->id > $found[1]->id)
            ?: 'got ' . count($found) . ' documents';
    });

    section('Rendering');

    check('a document renders to HTML', function() use ($documents, $invoiceType, $variant, $renderer) {
        $document = $documents->issue($invoiceType, makeOrder($variant));
        $html = $renderer->renderHtml([$document]);

        return (str_contains($html, '<!DOCTYPE html>')
                && str_contains($html, $document->formattedNumber)
                && str_contains($html, '@page'))
            ?: 'the HTML is missing the number or the page rules';
    });

    check('a document renders to a real PDF', function() use ($documents, $invoiceType, $variant, $renderer) {
        $document = $documents->issue($invoiceType, makeOrder($variant));
        $pdf = $renderer->renderPdf([$document]);

        return (str_starts_with($pdf, '%PDF') && strlen($pdf) > 2000)
            ?: 'got ' . strlen($pdf) . ' bytes starting ' . substr($pdf, 0, 8);
    });

    check('a packing slip leaves the prices off', function() use ($documents, $variant, $renderer) {
        $type = makeType('nopricing', [
            'kind' => Kind::PACKING_SLIP,
            'numberSource' => DocumentType::SOURCE_NONE,
        ]);

        $order = makeOrder($variant, 2);
        $html = $renderer->renderHtml([$documents->issue($type, $order)]);

        return !str_contains($html, 'Amount') ?: 'the packing slip has an amount column';
    });

    check('a bundle is one HTML document with page breaks', function() use ($documents, $invoiceType, $variant, $renderer) {
        $a = $documents->issue($invoiceType, makeOrder($variant));
        $b = $documents->issue($invoiceType, makeOrder($variant));
        $html = $renderer->renderHtml([$a, $b]);

        return (substr_count($html, '<!DOCTYPE html>') === 1
                && substr_count($html, 'class="dk-doc"') === 2)
            ?: 'the bundle has ' . substr_count($html, 'class="dk-doc"') . ' bodies';
    });

    check('a template error renders an error block, not an exception', function() use ($documents, $variant, $renderer) {
        $type = makeType('broken', ['templatePath' => 'donky/_render/does-not-exist']);
        $document = $documents->issue($type, makeOrder($variant));
        $html = $renderer->renderHtml([$document]);

        return str_contains($html, 'could not be rendered') ?: 'no error block was rendered';
    });

    check('the design drives the stylesheet', function() use ($designs) {
        $design = new Design([
            'name' => 'Check design',
            'handle' => 'checkdesign' . substr(md5(microtime(true)), 0, 5),
            'paperSize' => 'a4',
            'paperOrientation' => 'landscape',
            'colors' => ['accent' => '#ff0055'],
        ]);

        $css = $design->getCss();

        return (str_contains($css, 'size: a4 landscape') && str_contains($css, '#ff0055'))
            ?: 'the stylesheet ignored the design';
    });

    check('a colour posted without its hash is normalised', function() {
        $design = new Design([
            'name' => 'Colour check',
            'handle' => 'colourcheck',
            'colors' => ['accent' => '1B8EF2'],
        ]);

        $design->validate();

        return $design->getColor('accent') === '#1b8ef2'
            ?: 'got ' . $design->getColor('accent');
    });

    section('Pick lists');

    check('a pick list collapses the same SKU across orders', function() use ($plugin, $variant) {
        $type = makeType('pick', ['kind' => Kind::PICK_LIST, 'numberSource' => DocumentType::SOURCE_NONE]);
        $orders = [makeOrder($variant, 2), makeOrder($variant, 3)];

        $document = $plugin->getPickLists()->issue($type, $orders);
        $rows = $document->snapshot['rows'];

        return (count($rows) === 1 && $rows[0]['quantity'] === 5 && count($rows[0]['orders']) === 2)
            ?: 'got ' . json_encode($rows);
    });

    check('a pick list keeps the orders it covers', function() use ($plugin, $variant) {
        $type = makeType('pick2', ['kind' => Kind::PICK_LIST, 'numberSource' => DocumentType::SOURCE_NONE]);
        $orders = [makeOrder($variant), makeOrder($variant)];
        $document = $plugin->getPickLists()->issue($type, $orders);

        return (count($document->orderIds) === 2
                && $document->snapshot['summary']['orderCount'] === 2
                && count($document->getOrders()) === 2)
            ?: 'covered ' . count($document->orderIds) . ' orders';
    });

    check('a pick list renders', function() use ($plugin, $variant, $renderer) {
        $type = makeType('pick3', ['kind' => Kind::PICK_LIST, 'numberSource' => DocumentType::SOURCE_NONE]);
        $document = $plugin->getPickLists()->issue($type, [makeOrder($variant, 4)]);
        $html = $renderer->renderHtml([$document]);

        return str_contains($html, 'Orders covered') ?: 'the pick list did not render its orders';
    });

    check('a pick list refuses an empty selection', function() use ($plugin) {
        $type = makeType('pick4', ['kind' => Kind::PICK_LIST, 'numberSource' => DocumentType::SOURCE_NONE]);

        try {
            $plugin->getPickLists()->issue($type, []);
        } catch (DocumentException) {
            return true;
        }

        return 'an empty pick list was issued';
    });

    section('Credit notes');

    check('a credit note credits what was billed', function() use ($plugin, $variant, $documents) {
        $creditType = makeType('credit', ['kind' => Kind::CREDIT_NOTE]);
        $order = makeOrder($variant, 4);
        $lineItem = $order->getLineItems()[0];

        $note = $plugin->getCreditNotes()->issue($creditType, $order, [$lineItem->id => 2], [
            'reason' => 'Two came back',
        ]);

        $expected = round($lineItem->getTotal() / 4 * 2, 4);

        return abs($note->snapshot['credit']['total'] - $expected) < 0.001
            ?: 'credited ' . $note->snapshot['credit']['total'] . ', expected ' . $expected;
    });

    check('a credit note carries a negative total', function() use ($plugin, $variant) {
        $creditType = makeType('credit2', ['kind' => Kind::CREDIT_NOTE]);
        $order = makeOrder($variant, 2);
        $note = $plugin->getCreditNotes()->issue($creditType, $order, [$order->getLineItems()[0]->id => 1]);

        return $note->total < 0 ?: 'the credit note total is ' . $note->total;
    });

    check('credited quantities are remembered', function() use ($plugin, $variant) {
        $creditType = makeType('credit3', ['kind' => Kind::CREDIT_NOTE]);
        $order = makeOrder($variant, 5);
        $lineItem = $order->getLineItems()[0];

        $plugin->getCreditNotes()->issue($creditType, $order, [$lineItem->id => 2]);
        $lines = $plugin->getCreditNotes()->creditableLines($order);

        return ($lines[0]['credited'] === 2 && $lines[0]['remaining'] === 3)
            ?: 'got ' . json_encode($lines[0]);
    });

    check('over-crediting is refused', function() use ($plugin, $variant) {
        $creditType = makeType('credit4', ['kind' => Kind::CREDIT_NOTE]);
        $order = makeOrder($variant, 2);

        try {
            $plugin->getCreditNotes()->issue($creditType, $order, [$order->getLineItems()[0]->id => 3]);
        } catch (DocumentException) {
            return true;
        }

        return 'three of two were credited';
    });

    check('a credit note links to the invoice it credits', function() use ($plugin, $variant, $documents, $invoiceType) {
        $creditType = makeType('credit5', ['kind' => Kind::CREDIT_NOTE]);
        $order = makeOrder($variant, 2);
        $invoice = $documents->issue($invoiceType, $order);

        $note = $plugin->getCreditNotes()->issue($creditType, $order, [$order->getLineItems()[0]->id => 1], [
            'sourceDocumentId' => $invoice->id,
        ]);

        return ($note->sourceDocumentId === $invoice->id
                && $note->snapshot['credit']['invoiceNumber'] === $invoice->formattedNumber)
            ?: 'the credit note did not record the invoice';
    });

    check('a credit note renders', function() use ($plugin, $variant, $renderer) {
        $creditType = makeType('credit6', ['kind' => Kind::CREDIT_NOTE]);
        $order = makeOrder($variant, 2);
        $note = $plugin->getCreditNotes()->issue($creditType, $order, [$order->getLineItems()[0]->id => 1], [
            'reason' => 'Damaged in transit',
        ]);

        $html = $renderer->renderHtml([$note]);

        return str_contains($html, 'Damaged in transit') ?: 'the reason is not on the credit note';
    });

    section('Bulk printing');

    check('bulk print issues the missing documents and merges them', function() use ($plugin, $variant) {
        $type = makeType('bulk');
        $orders = [makeOrder($variant), makeOrder($variant), makeOrder($variant)];
        $ids = array_map(static fn(Order $o) => (int)$o->id, $orders);

        $result = $plugin->getBulk()->print($ids, $type);

        return ($result['issued'] === 3
                && count($result['documents']) === 3
                && str_starts_with($result['pdf'], '%PDF'))
            ?: 'issued ' . $result['issued'] . ', ' . count($result['documents']) . ' documents';
    });

    check('bulk print does not reissue what already exists', function() use ($plugin, $variant, $documents) {
        $type = makeType('bulk2');
        $orders = [makeOrder($variant), makeOrder($variant)];
        $documents->issue($type, $orders[0]);

        $result = $plugin->getBulk()->print(array_map(static fn(Order $o) => (int)$o->id, $orders), $type);

        return ($result['issued'] === 1 && count($result['documents']) === 2)
            ?: 'issued ' . $result['issued'];
    });

    check('bulk print keeps the selection order', function() use ($plugin, $variant) {
        $type = makeType('bulk3');
        $orders = [makeOrder($variant), makeOrder($variant)];
        $ids = array_reverse(array_map(static fn(Order $o) => (int)$o->id, $orders));

        $result = $plugin->getBulk()->print($ids, $type);
        $documentOrderIds = array_map(static fn(Document $d) => $d->orderId, $result['documents']);

        return $documentOrderIds === $ids ?: 'got ' . json_encode($documentOrderIds);
    });

    check('bulk print respects the limit', function() use ($plugin, $variant) {
        $type = makeType('bulk4');
        $settings = $plugin->getSettings();
        $original = $settings->bulkPrintLimit;
        $settings->bulkPrintLimit = 1;

        try {
            $plugin->getBulk()->print([1, 2, 3], $type);
        } catch (DocumentException) {
            return true;
        } finally {
            $settings->bulkPrintLimit = $original;
        }

        return 'the limit was ignored';
    });

    check('a bulk pick list is one document, not many', function() use ($plugin, $variant) {
        $type = makeType('bulkpick', ['kind' => Kind::PICK_LIST, 'numberSource' => DocumentType::SOURCE_NONE]);
        $orders = [makeOrder($variant, 2), makeOrder($variant, 1)];

        $result = $plugin->getBulk()->print(array_map(static fn(Order $o) => (int)$o->id, $orders), $type);

        return count($result['documents']) === 1 ?: 'got ' . count($result['documents']) . ' documents';
    });

    section('Attachment rules');

    check('a rule matches its email', function() use ($plugin, $invoiceType, $storeId, $variant, &$createdRules) {
        $emails = Commerce::getInstance()->getEmails()->getAllEmails($storeId);

        if ($emails->isEmpty()) {
            return true;
        }

        $email = $emails->first();

        $rule = new Rule([
            'storeId' => $storeId,
            'name' => 'Check rule',
            'typeId' => $invoiceType->id,
            'emailId' => $email->id,
            'enabled' => true,
        ]);

        if (!$plugin->getAttachments()->saveRule($rule)) {
            return 'could not save the rule: ' . json_encode($rule->getErrors());
        }

        $createdRules[] = $rule;

        $matched = $plugin->getAttachments()->matchRules($email, makeOrder($variant));

        return count($matched) === 1 ?: 'matched ' . count($matched) . ' rules';
    });

    check('a rule for another email does not match', function() use ($plugin, $storeId, $variant, $createdRules) {
        $emails = Commerce::getInstance()->getEmails()->getAllEmails($storeId);

        if ($emails->count() < 2 || !$createdRules) {
            return true;
        }

        $other = $emails->values()->get(1);
        $matched = $plugin->getAttachments()->matchRules($other, makeOrder($variant));

        return $matched === [] ?: 'matched a rule for the wrong email';
    });

    check('a rule for another status does not match', function() use ($plugin, $storeId, $variant, $createdRules) {
        if (!$createdRules) {
            return true;
        }

        $rule = $createdRules[0];
        $statuses = Commerce::getInstance()->getOrderStatuses()->getAllOrderStatuses($storeId);

        if ($statuses->isEmpty()) {
            return true;
        }

        $rule->orderStatusId = $statuses->first()->id;
        $plugin->getAttachments()->saveRule($rule);

        $history = new \craft\commerce\models\OrderHistory(['newStatusId' => -1]);
        $email = Commerce::getInstance()->getEmails()->getAllEmails($storeId)->first();
        $matched = $email ? $plugin->getAttachments()->matchRules($email, makeOrder($variant), $history) : [];

        $rule->orderStatusId = null;
        $plugin->getAttachments()->saveRule($rule);

        return $matched === [] ?: 'a rule matched the wrong status';
    });

    section('Customer links');

    check('a signed link verifies', function() use ($documents, $variant, $plugin) {
        $type = makeType('public', ['availableToCustomer' => true]);
        $document = $documents->issue($type, makeOrder($variant));
        $url = $documents->getPublicUrl($document);

        if (!$url) {
            return 'no public URL was produced';
        }

        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);

        return $documents->verifySignature($document->uid, (int)$query['expires'], $query['sig'])
            ?: 'the signature did not verify';
    });

    check('an expired link is refused', function() use ($documents, $variant) {
        $type = makeType('expired', ['availableToCustomer' => true]);
        $document = $documents->issue($type, makeOrder($variant));
        $expires = time() - 10;

        return !$documents->verifySignature($document->uid, $expires, $documents->signature($document->uid, $expires))
            ?: 'an expired link verified';
    });

    check('a tampered link is refused', function() use ($documents, $variant) {
        $type = makeType('tampered', ['availableToCustomer' => true]);
        $document = $documents->issue($type, makeOrder($variant));
        $expires = time() + 600;

        return !$documents->verifySignature($document->uid, $expires + 1, $documents->signature($document->uid, $expires))
            ?: 'a tampered link verified';
    });

    check('a type not available to customers has no public URL', function() use ($documents, $variant) {
        $type = makeType('private', ['availableToCustomer' => false]);
        $document = $documents->issue($type, makeOrder($variant));

        return $documents->getPublicUrl($document) === null ?: 'a private type produced a link';
    });

    section('Twig');

    check('craft.donky.documents() lists an order’s documents', function() use ($documents, $invoiceType, $variant) {
        $order = makeOrder($variant);
        $documents->issue($invoiceType, $order);

        $variable = new DonkyVariable();

        return count($variable->documents($order)) === 1
            ?: 'got ' . count($variable->documents($order));
    });

    check('craft.donky.type() finds a type by handle', function() use ($invoiceType) {
        $variable = new DonkyVariable();

        return $variable->type($invoiceType->handle)?->id === $invoiceType->id ?: 'not found';
    });

    check('craft.donky.customerDocuments() only offers public ones', function() use ($documents, $variant) {
        $publicType = makeType('twigpub', ['availableToCustomer' => true]);
        $privateType = makeType('twigpriv', ['availableToCustomer' => false]);
        $order = makeOrder($variant);

        $documents->issue($publicType, $order);
        $documents->issue($privateType, $order);

        $variable = new DonkyVariable();

        return count($variable->customerDocuments($order)) === 1
            ?: 'got ' . count($variable->customerDocuments($order));
    });

    section('Order index');

    check('one print action is registered per usable document type', function() use ($plugin, $types) {
        // The actions are permission-gated, and a console request has nobody signed in.
        Craft::$app->getUser()->setIdentity(craft\elements\User::find()->admin(true)->one());

        $event = new RegisterElementActionsEvent(['source' => '*', 'actions' => []]);
        yii\base\Event::trigger(Order::class, Element::EVENT_REGISTER_ACTIONS, $event);

        $ours = array_filter($event->actions, static fn($config) => is_array($config) && ($config['type'] ?? null) === PrintDocuments::class);
        $expected = count($types->getUsableTypes());

        return count($ours) === $expected
            ?: 'registered ' . count($ours) . ' actions for ' . $expected . ' types';
    });

    check('each print action names its own trigger element', function() {
        $event = new RegisterElementActionsEvent(['source' => '*', 'actions' => []]);
        yii\base\Event::trigger(Order::class, Element::EVENT_REGISTER_ACTIONS, $event);

        $ids = [];

        foreach ($event->actions as $config) {
            if (!is_array($config) || ($config['type'] ?? null) !== PrintDocuments::class) {
                continue;
            }

            $action = Craft::$app->getElements()->createAction($config);
            $action->setElementType(Order::class);
            $ids[] = $action->getTriggerId();
        }

        // Craft's default trigger id is just the class name, so without an override every print
        // action would point at the same element and only the last one would ever fire.
        return count($ids) === count(array_unique($ids))
            ?: 'trigger ids collided: ' . json_encode($ids);
    });

    check('Lite registers no print actions', function() {
        Craft::$app->getPlugins()->switchEdition('donky', Plugin::EDITION_LITE);

        try {
            $event = new RegisterElementActionsEvent(['source' => '*', 'actions' => []]);
            yii\base\Event::trigger(Order::class, Element::EVENT_REGISTER_ACTIONS, $event);

            $ours = array_filter($event->actions, static fn($config) => is_array($config) && ($config['type'] ?? null) === PrintDocuments::class);

            return $ours === [] ?: 'Lite registered ' . count($ours) . ' print actions';
        } finally {
            Craft::$app->getPlugins()->switchEdition('donky', Plugin::EDITION_PRO);
        }
    });

    section('Editions');

    check('Pro reports Pro', function() {
        return Plugin::getInstance()->isPro() ?: 'not Pro';
    });

    check('Lite refuses a Pro kind', function() use ($storeId, $designs) {
        Craft::$app->getPlugins()->switchEdition('donky', Plugin::EDITION_LITE);

        try {
            $type = new DocumentType([
                'storeId' => $storeId,
                'name' => 'Lite credit note',
                'handle' => 'liteCredit',
                'kind' => Kind::CREDIT_NOTE,
                'designId' => $designs->getDefaultDesign()->id,
                'format' => 'CN-{number}',
            ]);

            // The model itself still validates — the edition gate is in the service and the
            // controller, so that a downgrade keeps existing rows readable.
            return !Plugin::getInstance()->getDocumentTypes()->isLicensed($type)
                ?: 'Lite licensed a credit note type';
        } finally {
            Craft::$app->getPlugins()->switchEdition('donky', Plugin::EDITION_PRO);
        }
    });

    check('Lite refuses bulk printing', function() use ($plugin, $variant) {
        $type = makeType('litebulk');
        Craft::$app->getPlugins()->switchEdition('donky', Plugin::EDITION_LITE);

        try {
            $plugin->getBulk()->print([1], $type);

            return 'Lite printed in bulk';
        } catch (DocumentException) {
            return true;
        } finally {
            Craft::$app->getPlugins()->switchEdition('donky', Plugin::EDITION_PRO);
        }
    });

    check('Lite runs only the first attachment rule', function() use ($plugin, $storeId) {
        Craft::$app->getPlugins()->switchEdition('donky', Plugin::EDITION_LITE);

        try {
            $active = $plugin->getAttachments()->getActiveRules($storeId);

            return count($active) <= 1 ?: 'Lite ran ' . count($active) . ' rules';
        } finally {
            Craft::$app->getPlugins()->switchEdition('donky', Plugin::EDITION_PRO);
        }
    });
} finally {
    section('Cleanup');

    foreach ($createdRules as $rule) {
        if ($rule->id) {
            $plugin->getAttachments()->deleteRuleById($rule->id);
        }
    }

    foreach ($createdTypes as $type) {
        if ($type->id) {
            $types->deleteTypeById($type->id);
        }
    }

    foreach ($createdDesigns as $design) {
        if ($design->id) {
            $designs->deleteDesignById($design->id);
        }
    }

    foreach ($createdOrders as $createdOrder) {
        Craft::$app->getElements()->deleteElement($createdOrder, true);
    }

    foreach ($createdProducts as $createdProduct) {
        Craft::$app->getElements()->deleteElement($createdProduct, true);
    }

    Craft::$app->getPlugins()->switchEdition('donky', $originalEdition);
    Plugin::getInstance()->setSettings($originalSettings->toArray());

    echo "  ✓ fixtures removed\n";
}

echo "\n$passed passed, $failed failed\n";

exit($failed === 0 ? 0 : 1);
