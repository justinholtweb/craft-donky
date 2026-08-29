<?php

namespace justinholtweb\donky\models;

use Craft;
use craft\base\Model;
use craft\elements\Asset;
use craft\helpers\App;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use DateTime;

/**
 * A design — everything about how a document looks, kept out of the template so that a merchant
 * can restyle every document in the suite without touching Twig, and so that a merchant who *does*
 * write their own template still gets a stylesheet, a logo and an issuer block for free.
 */
class Design extends Model
{
    public ?int $id = null;
    public ?string $name = null;
    public ?string $handle = null;
    public bool $isDefault = false;

    public string $paperSize = 'letter';
    public string $paperOrientation = 'portrait';

    /** @var array<string, int> Page margins in millimetres. */
    public array $margins = [];

    /** @var array<string, mixed> Font family, base size, heading scale. */
    public array $typography = [];

    /** @var array<string, string> Hex colours. */
    public array $colors = [];

    public ?int $logoAssetId = null;

    /** @var int Logo height in millimetres. Width follows the aspect ratio. */
    public int $logoHeight = 16;

    /** @var array<string, mixed> Who the document is *from*. */
    public array $seller = [];

    /** @var array<string, mixed> Which blocks and columns the bundled templates draw. */
    public array $blocks = [];

    public ?string $customCss = null;

    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    private ?Asset $_logo = null;

    /**
     * @return array<string, int>
     */
    public static function defaultMargins(): array
    {
        return ['top' => 16, 'right' => 14, 'bottom' => 18, 'left' => 14];
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultTypography(): array
    {
        return ['family' => 'sans', 'size' => 10, 'headingScale' => 1.7];
    }

    /**
     * @return array<string, string>
     */
    public static function defaultColors(): array
    {
        return [
            'accent' => '#3F5E8C',
            'text' => '#1B1D21',
            'muted' => '#6C7079',
            'rule' => '#D7DAE0',
            'tableHead' => '#F2F4F7',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultSeller(): array
    {
        return [
            'name' => '',
            'address' => '',
            'taxId' => '',
            'registration' => '',
            'email' => '',
            'phone' => '',
            'website' => '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultBlocks(): array
    {
        return [
            'showSku' => true,
            'showOptions' => true,
            'showTaxColumn' => true,
            'showBillingAddress' => true,
            'showShippingAddress' => true,
            'showPaymentMethod' => true,
            'showCustomerNote' => true,
            'showTotalsBreakdown' => true,
            'showPaidStamp' => true,
            'showDueDate' => true,
            'showPageNumbers' => true,
            'headerNote' => '',
            'terms' => '',
            'footer' => '',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function paperSizes(): array
    {
        return [
            'letter' => Craft::t('donky', 'Letter'),
            'legal' => Craft::t('donky', 'Legal'),
            'a4' => Craft::t('donky', 'A4'),
            'a5' => Craft::t('donky', 'A5'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function orientations(): array
    {
        return [
            'portrait' => Craft::t('donky', 'Portrait'),
            'landscape' => Craft::t('donky', 'Landscape'),
        ];
    }

    /**
     * Only fonts dompdf ships with are offered. A font that has to be downloaded and cached is a
     * font that renders as boxes on the first server that cannot write to its cache directory.
     *
     * @return array<string, string>
     */
    public static function fontFamilies(): array
    {
        return [
            'sans' => Craft::t('donky', 'Sans serif (Helvetica)'),
            'serif' => Craft::t('donky', 'Serif (Times)'),
            'mono' => Craft::t('donky', 'Monospace (Courier)'),
            'dejavu' => Craft::t('donky', 'DejaVu Sans (widest character coverage)'),
        ];
    }

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();

        $this->margins = array_merge(self::defaultMargins(), $this->margins);
        $this->typography = array_merge(self::defaultTypography(), $this->typography);
        $this->colors = array_merge(self::defaultColors(), $this->colors);
        $this->seller = array_merge(self::defaultSeller(), $this->seller);
        $this->blocks = array_merge(self::defaultBlocks(), $this->blocks);
    }

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        return [
            [['name', 'handle'], 'required'],
            [['name', 'handle'], 'string', 'max' => 255],
            [['handle'], 'match', 'pattern' => '/^[a-zA-Z][a-zA-Z0-9_\-]*$/', 'message' => Craft::t('donky', 'Handles must start with a letter and contain only letters, numbers, hyphens and underscores.')],
            [['isDefault'], 'boolean'],
            [['paperSize'], 'in', 'range' => array_keys(self::paperSizes())],
            [['paperOrientation'], 'in', 'range' => array_keys(self::orientations())],
            [['logoHeight'], 'integer', 'min' => 4, 'max' => 60],
            [['logoAssetId'], 'integer'],
            [['margins'], 'validateMargins', 'skipOnEmpty' => false],
            [['colors'], 'validateColors', 'skipOnEmpty' => false],
        ];
    }

    public function validateMargins(string $attribute): void
    {
        foreach (self::defaultMargins() as $edge => $default) {
            $value = $this->margins[$edge] ?? $default;

            if (!is_numeric($value) || $value < 0 || $value > 80) {
                $this->addError($attribute, Craft::t('donky', 'Margins must be between 0 and 80 mm.'));
                return;
            }

            $this->margins[$edge] = (int)$value;
        }
    }

    /**
     * Craft's colour field posts hex *without* the leading hash, so normalising here is the
     * difference between a saved colour and a silent fall back to the default.
     */
    public function validateColors(string $attribute): void
    {
        foreach (self::defaultColors() as $key => $default) {
            $value = trim((string)($this->colors[$key] ?? ''));

            if ($value === '') {
                $this->colors[$key] = $default;
                continue;
            }

            if ($value[0] !== '#') {
                $value = '#' . $value;
            }

            if (!preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
                $this->addError($attribute, Craft::t('donky', '“{value}” is not a six-digit hex colour.', ['value' => $value]));
                continue;
            }

            $this->colors[$key] = strtolower($value);
        }
    }

    public function getLogo(): ?Asset
    {
        if ($this->_logo !== null) {
            return $this->_logo;
        }

        if (!$this->logoAssetId) {
            return null;
        }

        return $this->_logo = Craft::$app->getAssets()->getAssetById($this->logoAssetId);
    }

    /**
     * The logo as something dompdf can actually draw.
     *
     * dompdf fetches remote images only when remote access is enabled, and a signed S3 URL is a
     * network round trip inside a render. A local volume gives us a filesystem path, which always
     * works; anything else falls back to the URL and is the merchant's call.
     */
    public function getLogoSrc(): ?string
    {
        $logo = $this->getLogo();

        if (!$logo) {
            return null;
        }

        try {
            $fs = $logo->getVolume()->getFs();

            if ($fs instanceof \craft\fs\Local) {
                $root = rtrim(App::parseEnv($fs->getRootPath()), '/\\');
                $path = $root . DIRECTORY_SEPARATOR . ltrim($logo->getPath(), '/');

                if (is_file($path)) {
                    return $path;
                }
            }
        } catch (\Throwable) {
            // Fall through to the URL.
        }

        $url = $logo->getUrl();

        return $url ? UrlHelper::url($url) : null;
    }

    public function getBlock(string $key, mixed $default = null): mixed
    {
        return $this->blocks[$key] ?? $default ?? (self::defaultBlocks()[$key] ?? null);
    }

    public function getColor(string $key): string
    {
        return $this->colors[$key] ?? self::defaultColors()[$key] ?? '#000000';
    }

    /**
     * The seller block with empty lines dropped, ready to print.
     *
     * @return string[]
     */
    public function getSellerLines(): array
    {
        $lines = [];

        foreach (preg_split('/\R/', (string)($this->seller['address'] ?? '')) as $line) {
            $line = trim($line);

            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    public function getFontStack(): string
    {
        return match ($this->typography['family'] ?? 'sans') {
            'serif' => "'Times New Roman', Times, serif",
            'mono' => "'Courier New', Courier, monospace",
            'dejavu' => "'DejaVu Sans', sans-serif",
            default => "Helvetica, Arial, sans-serif",
        };
    }

    /**
     * The whole stylesheet for a document, built from the design.
     *
     * It is inlined into the rendered HTML rather than registered as an asset bundle: a PDF route
     * that calls `head()` invites every other installed plugin to inject its chrome into the page.
     */
    public function getCss(): string
    {
        $m = $this->margins;
        $size = (float)($this->typography['size'] ?? 10);
        $scale = (float)($this->typography['headingScale'] ?? 1.7);
        $accent = $this->getColor('accent');
        $text = $this->getColor('text');
        $muted = $this->getColor('muted');
        $rule = $this->getColor('rule');
        $head = $this->getColor('tableHead');
        $font = $this->getFontStack();
        $paper = $this->paperSize . ' ' . $this->paperOrientation;
        $h1 = round($size * $scale, 2);
        $h2 = round($size * 1.15, 2);

        $css = <<<CSS
@page { size: $paper; margin: {$m['top']}mm {$m['right']}mm {$m['bottom']}mm {$m['left']}mm; }
body { font-family: $font; font-size: {$size}pt; color: $text; line-height: 1.45; margin: 0; }
h1, h2, h3 { margin: 0 0 4pt; font-weight: bold; }
h1 { font-size: {$h1}pt; color: $accent; letter-spacing: -0.2pt; }
h2 { font-size: {$h2}pt; }
p { margin: 0 0 4pt; }
small, .muted { color: $muted; }
.dk-doc { page-break-after: always; }
.dk-doc:last-child { page-break-after: auto; }
.dk-head { width: 100%; margin-bottom: 10pt; }
.dk-head td { vertical-align: top; }
.dk-head .dk-meta { text-align: right; }
.dk-logo { height: {$this->logoHeight}mm; }
.dk-rule { border: 0; border-top: 0.6pt solid $rule; margin: 8pt 0; }
.dk-parties { width: 100%; margin-bottom: 10pt; }
.dk-parties td { vertical-align: top; width: 50%; padding-right: 8pt; }
.dk-label { text-transform: uppercase; letter-spacing: 0.6pt; font-size: 7.5pt; color: $muted; margin-bottom: 2pt; }
table.dk-lines { width: 100%; border-collapse: collapse; }
table.dk-lines th { background: $head; color: $text; text-align: left; padding: 5pt 6pt; border-bottom: 0.6pt solid $rule; font-size: 8.5pt; text-transform: uppercase; letter-spacing: 0.4pt; }
table.dk-lines td { padding: 5pt 6pt; border-bottom: 0.4pt solid $rule; vertical-align: top; }
table.dk-lines tbody tr { page-break-inside: avoid; }
.dk-num { text-align: right; white-space: nowrap; }
.dk-qty { text-align: center; white-space: nowrap; }
.dk-sku { color: $muted; font-size: 8pt; }
.dk-opts { color: $muted; font-size: 8pt; }
.dk-totals { width: 46%; margin-left: 54%; margin-top: 8pt; border-collapse: collapse; }
.dk-totals td { padding: 3pt 6pt; }
.dk-totals .dk-grand td { border-top: 0.8pt solid $accent; font-weight: bold; font-size: {$h2}pt; color: $accent; }
.dk-notes { margin-top: 12pt; page-break-inside: avoid; }
.dk-terms { margin-top: 10pt; font-size: 8.5pt; color: $muted; }
.dk-footer { position: fixed; bottom: -10mm; left: 0; right: 0; font-size: 7.5pt; color: $muted; text-align: center; }
.dk-stamp { float: right; border: 1.6pt solid $accent; color: $accent; padding: 3pt 8pt; font-weight: bold; text-transform: uppercase; letter-spacing: 1pt; transform: rotate(-4deg); }
.dk-box { border: 0.6pt solid $rule; padding: 6pt 8pt; }
CSS;

        if ($this->getBlock('showPageNumbers')) {
            $css .= "\n.dk-pageno:after { content: counter(page); }";
        }

        if ($this->customCss) {
            $css .= "\n/* custom */\n" . $this->customCss;
        }

        return $css;
    }

    public function getCpEditUrl(): string
    {
        return UrlHelper::cpUrl('donky/designs/' . $this->id);
    }

    /**
     * A tiny swatch strip for the designs index.
     */
    public function getSwatchHtml(): string
    {
        $out = '';

        foreach (['accent', 'text', 'rule', 'tableHead'] as $key) {
            $out .= Html::tag('span', '', [
                'style' => [
                    'display' => 'inline-block',
                    'width' => '14px',
                    'height' => '14px',
                    'border-radius' => '3px',
                    'border' => '1px solid rgba(0,0,0,0.12)',
                    'margin-right' => '3px',
                    'vertical-align' => 'middle',
                    'background' => $this->getColor($key),
                ],
            ]);
        }

        return $out;
    }
}
