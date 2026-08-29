<?php

namespace justinholtweb\donky\controllers;

use Craft;
use craft\commerce\Plugin as Commerce;
use craft\helpers\Json;
use craft\web\Controller;
use justinholtweb\donky\models\Rule;
use justinholtweb\donky\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Attachment rules.
 */
class RulesController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('donky-manageRules');

        if (!Plugin::commerceIsReady()) {
            throw new ForbiddenHttpException('Craft Commerce is not installed.');
        }

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        $rules = $plugin->getAttachments()->getAllRules();
        $active = $plugin->getAttachments()->getActiveRules(null);

        return $this->renderTemplate('donky/rules/_index', [
            'rules' => $rules,
            'activeIds' => array_keys($active),
            'isPro' => $plugin->isPro(),
            'attachEnabled' => $plugin->getSettings()->attachToEmails,
            'canAdd' => $plugin->isPro() || $rules === [],
        ]);
    }

    public function actionEdit(?int $ruleId = null, ?Rule $rule = null): Response
    {
        $plugin = Plugin::getInstance();
        $stores = Commerce::getInstance()->getStores();

        if ($rule === null) {
            if ($ruleId !== null) {
                $rule = $plugin->getAttachments()->getRuleById($ruleId);

                if ($rule === null) {
                    throw new NotFoundHttpException('Rule not found');
                }
            } else {
                if (!$plugin->isPro() && $plugin->getAttachments()->getAllRules() !== []) {
                    throw new ForbiddenHttpException('Donky Lite supports one attachment rule.');
                }

                $rule = new Rule(['storeId' => $stores->getPrimaryStore()->id]);
            }
        }

        $typeOptions = [];

        foreach ($plugin->getDocumentTypes()->getUsableTypes($rule->storeId) as $type) {
            if ($type->isAggregate()) {
                continue;
            }

            $typeOptions[] = ['value' => $type->id, 'label' => $type->name];
        }

        $emailOptions = [['value' => '', 'label' => Craft::t('donky', 'Any email')]];

        foreach (Commerce::getInstance()->getEmails()->getAllEmails($rule->storeId) as $email) {
            $emailOptions[] = ['value' => $email->id, 'label' => $email->name];
        }

        $statusOptions = [['value' => '', 'label' => Craft::t('donky', 'Any status')]];

        foreach (Commerce::getInstance()->getOrderStatuses()->getAllOrderStatuses($rule->storeId) as $status) {
            $statusOptions[] = ['value' => $status->id, 'label' => $status->name];
        }

        return $this->renderTemplate('donky/rules/_edit', [
            'rule' => $rule,
            'isNew' => !$rule->id,
            'isPro' => $plugin->isPro(),
            'typeOptions' => $typeOptions,
            'emailOptions' => $emailOptions,
            'statusOptions' => $statusOptions,
            'title' => $rule->id ? $rule->name : Craft::t('donky', 'New attachment rule'),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $service = $plugin->getAttachments();
        $id = $this->request->getBodyParam('id');
        $rule = $id ? $service->getRuleById((int)$id) : new Rule();

        if ($rule === null) {
            throw new NotFoundHttpException('Rule not found');
        }

        if (!$rule->id && !$plugin->isPro() && $service->getAllRules() !== []) {
            throw new ForbiddenHttpException('Donky Lite supports one attachment rule.');
        }

        $rule->storeId = (int)$this->request->getBodyParam('storeId', $rule->storeId);
        $rule->name = $this->request->getBodyParam('name', $rule->name);
        $rule->enabled = (bool)$this->request->getBodyParam('enabled', $rule->enabled);
        $rule->typeId = (int)$this->request->getBodyParam('typeId') ?: null;
        $rule->emailId = (int)$this->request->getBodyParam('emailId') ?: null;
        $rule->issueIfMissing = (bool)$this->request->getBodyParam('issueIfMissing', $rule->issueIfMissing);

        if ($plugin->isPro()) {
            $rule->orderStatusId = (int)$this->request->getBodyParam('orderStatusId') ?: null;
            $rule->setCondition($this->request->getBodyParam('orderCondition'));
        }

        if (!$service->saveRule($rule)) {
            $this->setFailFlash(Craft::t('donky', 'Couldn’t save the rule.'));
            Craft::$app->getUrlManager()->setRouteParams(['rule' => $rule]);

            return null;
        }

        $this->setSuccessFlash(Craft::t('donky', 'Rule saved.'));

        return $this->redirectToPostedUrl($rule);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        Plugin::getInstance()->getAttachments()->deleteRuleById((int)$this->request->getRequiredBodyParam('id'));

        return $this->asSuccess(Craft::t('donky', 'Rule deleted.'));
    }

    public function actionReorder(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $ids = Json::decodeIfJson($this->request->getRequiredBodyParam('ids'));
        Plugin::getInstance()->getAttachments()->reorderRules(array_map('intval', $ids));

        return $this->asSuccess();
    }
}
