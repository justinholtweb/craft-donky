<?php

namespace justinholtweb\donky\models;

use Craft;
use craft\base\Model;

/**
 * Donky settings.
 */
class Settings extends Model
{
    /** Send the email anyway, without the attachment, and log why. */
    public const ON_ATTACH_FAILURE_SEND = 'send';

    /** Hold the email back rather than send an order confirmation with no invoice on it. */
    public const ON_ATTACH_FAILURE_HOLD = 'hold';

    /**
     * @var bool Whether document types issue documents on their own trigger. Off leaves every
     *           document a manual act, which is how you take Donky live on an established store
     *           without a queue full of surprises.
     */
    public bool $autoIssue = true;

    /**
     * @var bool Whether attachment rules run at all.
     */
    public bool $attachToEmails = true;

    /**
     * @var string What happens when a document cannot be built while an email is being sent.
     */
    public string $onAttachFailure = self::ON_ATTACH_FAILURE_SEND;

    /**
     * @var bool Whether customers may download their own documents from a signed link.
     */
    public bool $customerDownloads = true;

    /**
     * @var int How long a signed customer link stays valid, in seconds.
     */
    public int $linkExpiry = 86400;

    /**
     * @var bool Whether dompdf may fetch remote images. Off is the safe default: a template that
     *           pulls an image over the network turns every render into an outbound request, and
     *           an SSRF if the URL comes from content.
     */
    public bool $allowRemoteImages = false;

    /**
     * @var int The most orders a single bulk print may cover. One dompdf render holds every page
     *          in memory, so this is a memory limit wearing a different hat.
     */
    public int $bulkPrintLimit = 100;

    /**
     * @var int Seconds to wait for another request to finish with a counter.
     */
    public int $lockTimeout = 5;

    /**
     * @var string The default object template for download filenames.
     */
    public string $defaultFilenameFormat = '{{ type.handle }}-{{ document.formattedNumber ?: order.shortNumber }}';

    /**
     * @var bool Whether every render is written to the logs. Useful while setting up, noisy after.
     */
    public bool $logRenders = false;

    /**
     * @return array<string, string>
     */
    public static function attachFailureModes(): array
    {
        return [
            self::ON_ATTACH_FAILURE_SEND => Craft::t('donky', 'Send the email without it'),
            self::ON_ATTACH_FAILURE_HOLD => Craft::t('donky', 'Hold the email back'),
        ];
    }

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        return [
            [['autoIssue', 'attachToEmails', 'customerDownloads', 'allowRemoteImages', 'logRenders'], 'boolean'],
            [['onAttachFailure'], 'in', 'range' => array_keys(self::attachFailureModes())],
            [['linkExpiry'], 'integer', 'min' => 60],
            [['bulkPrintLimit'], 'integer', 'min' => 1, 'max' => 2000],
            [['lockTimeout'], 'integer', 'min' => 1, 'max' => 60],
            [['defaultFilenameFormat'], 'string', 'max' => 255],
        ];
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels(): array
    {
        return [
            'autoIssue' => Craft::t('donky', 'Issue documents automatically'),
            'attachToEmails' => Craft::t('donky', 'Attach documents to Commerce emails'),
            'onAttachFailure' => Craft::t('donky', 'If a document cannot be attached'),
            'customerDownloads' => Craft::t('donky', 'Let customers download their documents'),
            'linkExpiry' => Craft::t('donky', 'Link lifetime'),
            'allowRemoteImages' => Craft::t('donky', 'Allow remote images'),
            'bulkPrintLimit' => Craft::t('donky', 'Bulk print limit'),
            'lockTimeout' => Craft::t('donky', 'Lock timeout'),
            'defaultFilenameFormat' => Craft::t('donky', 'Default filename'),
            'logRenders' => Craft::t('donky', 'Log renders'),
        ];
    }
}
