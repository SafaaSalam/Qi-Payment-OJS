<?php

/**
 * @file plugins/paymethod/qi/QiPaymentPlugin.php
 *
 * Copyright (c) 2014-2026 Simon Fraser University
 * Copyright (c) 2003-2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class QiPaymentPlugin
 *
 * @ingroup plugins_paymethod_qi
 *
 * @brief Qi payment plugin class
 */

namespace APP\plugins\paymethod\qi;

use APP\core\Application;
use APP\core\Request;
use APP\facades\Repo;
use APP\template\TemplateManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use PKP\components\forms\context\PKPPaymentSettingsForm;
use PKP\config\Config;
use PKP\db\DAORegistry;
use PKP\mail\Mailable;
use PKP\plugins\Hook;
use PKP\plugins\PaymethodPlugin;
use PKP\security\Role;
use PKP\stageAssignment\StageAssignment;

class QiPaymentPlugin extends PaymethodPlugin
{
    /**
     * @see Plugin::getName
     */
    public function getName()
    {
        return 'QiPayment';
    }

    /**
     * @see Plugin::getDisplayName
     */
    public function getDisplayName()
    {
        return __('plugins.paymethod.qi.displayName');
    }

    /**
     * @see Plugin::getDescription
     */
    public function getDescription()
    {
        return __('plugins.paymethod.qi.description');
    }

    /**
     * @copydoc Plugin::register()
     *
     * @param null|mixed $mainContextId
     */
    public function register($category, $path, $mainContextId = null)
    {
        if (!parent::register($category, $path, $mainContextId)) {
            return false;
        }

        $this->addLocaleData();
        Hook::add('Form::config::before', $this->addSettings(...));
        return true;
    }

    /**
     * Add settings to the payments form
     *
     * @param string $hookName
     * @param \PKP\components\forms\FormComponent $form
     */
    public function addSettings($hookName, $form)
    {
        // OJS 3.4.x defines FORM_PAYMENT_SETTINGS as a global constant (via define()) rather
        // than a class constant on PKPPaymentSettingsForm, so referencing
        // PKPPaymentSettingsForm::FORM_PAYMENT_SETTINGS directly fatal-errors there
        // ("Undefined constant") on every FormComponent-based settings page, not just Payments.
        // Resolve the form ID defensively so this works across OJS versions.
        $paymentSettingsFormId = defined(PKPPaymentSettingsForm::class . '::FORM_PAYMENT_SETTINGS')
            ? constant(PKPPaymentSettingsForm::class . '::FORM_PAYMENT_SETTINGS')
            : (defined('FORM_PAYMENT_SETTINGS') ? \FORM_PAYMENT_SETTINGS : 'paymentSettings');

        if ($form->id !== $paymentSettingsFormId) {
            return;
        }

        $context = Application::get()->getRequest()->getContext();
        if (!$context) {
            return;
        }

        $form->addGroup([
            'id' => 'qipayment',
            'label' => __('plugins.paymethod.qi.displayName'),
            'showWhen' => 'paymentsEnabled',
        ])
            ->addField(new \PKP\components\forms\FieldOptions('qiTestMode', [
                'label' => __('plugins.paymethod.qi.settings.testMode'),
                'options' => [
                    ['value' => true, 'label' => __('common.enable')]
                ],
                'value' => (bool) $this->getSetting($context->getId(), 'qiTestMode'),
                'groupId' => 'qipayment',
            ]))
            ->addField(new \PKP\components\forms\FieldText('apiHost', [
                'label' => __('plugins.paymethod.qi.settings.apiHost'),
                'value' => $this->getSetting($context->getId(), 'apiHost'),
                'groupId' => 'qipayment',
            ]))
            ->addField(new \PKP\components\forms\FieldText('terminalId', [
                'label' => __('plugins.paymethod.qi.settings.terminalId'),
                'value' => $this->getSetting($context->getId(), 'terminalId'),
                'groupId' => 'qipayment',
            ]))
            ->addField(new \PKP\components\forms\FieldText('username', [
                'label' => __('plugins.paymethod.qi.settings.username'),
                'value' => $this->getSetting($context->getId(), 'username'),
                'groupId' => 'qipayment',
            ]))
            ->addField(new \PKP\components\forms\FieldText('password', [
                'label' => __('plugins.paymethod.qi.settings.password'),
                'value' => $this->getSetting($context->getId(), 'password'),
                'groupId' => 'qipayment',
            ]))
            ->addField(new \PKP\components\forms\FieldTextarea('webhookPublicKey', [
                'label' => __('plugins.paymethod.qi.settings.webhookPublicKey'),
                'description' => __('plugins.paymethod.qi.settings.webhookPublicKey.description'),
                'value' => $this->getSetting($context->getId(), 'webhookPublicKey'),
                'groupId' => 'qipayment',
            ]));

        return;
    }

    /**
     * @copydoc PaymethodPlugin::saveSettings
     */
    public function saveSettings(string $hookname, array $args)
    {
        $illuminateRequest = $args[0]; /** @var \Illuminate\Http\Request $illuminateRequest */
        $request = $args[1]; /** @var Request $request */
        $updatedSettings = $args[3]; /** @var Collection $updatedSettings */

        $allParams = $illuminateRequest->input();
        $saveParams = [];
        foreach ($allParams as $param => $val) {
            switch ($param) {
                case 'apiHost':
                case 'terminalId':
                case 'username':
                case 'password':
                case 'webhookPublicKey':
                    $saveParams[$param] = (string) $val;
                    break;
                case 'qiTestMode':
                    $saveParams[$param] = $val === 'true';
                    break;
            }
        }
        $contextId = $request->getContext()->getId();
        foreach ($saveParams as $param => $val) {
            $this->updateSetting($contextId, $param, $val);
            $updatedSettings->put($param, $val);
        }
    }

    /**
     * @copydoc PaymethodPlugin::getPaymentForm()
     */
    public function getPaymentForm($context, $queuedPayment)
    {
        return new QiPaymentForm($this, $queuedPayment);
    }

    /**
     * @copydoc PaymethodPlugin::isConfigured
     */
    public function isConfigured($context)
    {
        if (!$context) {
            return false;
        }
        if ($this->getSetting($context->getId(), 'terminalId') == '') {
            return false;
        }
        if ($this->getSetting($context->getId(), 'username') == '') {
            return false;
        }
        if ($this->getSetting($context->getId(), 'password') == '') {
            return false;
        }
        return true;
    }

    /**
     * Entry point for both legs of a Qi transaction:
     *  - The customer's browser landing back on "finishPaymentUrl" (GET, no reliable
     *    payment identifier in the query string other than our own queuedPaymentId).
     *  - Qi's server-to-server webhook hitting "notificationUrl" (POST, JSON body
     *    containing the authoritative payment result).
     * Both URLs point here (they're the same URL); we branch on HTTP method.
     */
    public function handle($args, $request)
    {
        // Check sandbox configuration
        if (Config::getVar('general', 'sandbox', false)) {
            error_log('Application is set to sandbox mode and no payment will be done via Qi');
            return;
        }

        if ($request->isPost()) {
            $this->handleWebhook($request);
            return;
        }

        $this->handleReturn($request);
    }

    /**
     * Handle Qi's asynchronous webhook (POST notificationUrl). This is the
     * authoritative source of payment completion, since the browser redirect
     * carries no signed payment result. Always responds 200 (except on
     * signature failure) so Qi does not endlessly retry.
     */
    protected function handleWebhook($request)
    {
        $journal = $request->getJournal();
        $journalId = $journal->getId();

        $rawBody = file_get_contents('php://input');
        $data = json_decode((string) $rawBody, true);
        if (!is_array($data)) {
            error_log('Qi webhook: could not parse JSON body: ' . $rawBody);
            http_response_code(400);
            return;
        }

        $publicKeyPem = $this->getSetting($journalId, 'webhookPublicKey');
        $signature = $_SERVER['HTTP_X_SIGNATURE'] ?? null;
        if (empty($publicKeyPem) || empty($signature) || !$this->verifyWebhookSignature($data, (string) $signature, (string) $publicKeyPem)) {
            error_log('Qi webhook: missing or invalid X-Signature; rejecting notification for paymentId ' . ($data['paymentId'] ?? '?'));
            http_response_code(403);
            return;
        }

        $queuedPaymentId = $request->getUserVar('queuedPaymentId');
        $queuedPaymentDao = DAORegistry::getDAO('QueuedPaymentDAO'); /** @var \PKP\payment\QueuedPaymentDAO $queuedPaymentDao */
        $queuedPayment = $queuedPaymentDao->getById($queuedPaymentId);
        if (!$queuedPayment) {
            // Already fulfilled (e.g. the browser-return leg beat the webhook here) or invalid.
            // Respond 200 either way so Qi doesn't retry a stale/duplicate notification.
            http_response_code(200);
            return;
        }

        if ($this->isSuccessfulStatus($data) && $this->amountAndCurrencyMatch($data, $queuedPayment)) {
            $paymentManager = Application::get()->getPaymentManager($journal);
            $paymentManager->fulfillQueuedPayment($request, $queuedPayment, $this->getName());
            $this->notifyEditorPaymentReceived($journal, $queuedPayment);
        } else {
            error_log('Qi webhook: payment ' . ($data['paymentId'] ?? '?') . ' not confirmed successful. Payload: ' . $rawBody);
        }

        http_response_code(200);
    }

    /**
     * Handle the customer's browser landing back on finishPaymentUrl. Looks up
     * the paymentId we stored when the payment was created, confirms current
     * status directly against Qi (in case the webhook hasn't arrived yet), and
     * shows the author an explicit confirmation instead of a bare redirect.
     */
    protected function handleReturn($request)
    {
        $journal = $request->getJournal();
        $journalId = $journal->getId();
        $queuedPaymentDao = DAORegistry::getDAO('QueuedPaymentDAO'); /** @var \PKP\payment\QueuedPaymentDAO $queuedPaymentDao */

        $queuedPaymentId = $request->getUserVar('queuedPaymentId');
        $queuedPayment = $queuedPaymentDao->getById($queuedPaymentId);

        if (!$queuedPayment) {
            // No longer queued: the webhook already confirmed and fulfilled it.
            $this->showMessage($request, 'plugins.paymethod.qi.success');
            return;
        }

        try {
            $paymentId = $this->getSetting($journalId, 'qiPaymentId_' . $queuedPayment->getId());
            if (empty($paymentId)) {
                throw new \Exception("No Qi paymentId recorded for queuedPaymentId {$queuedPaymentId}!");
            }

            $responseData = $this->fetchPaymentStatus($journalId, $paymentId);

            if ($this->isSuccessfulStatus($responseData) && $this->amountAndCurrencyMatch($responseData, $queuedPayment)) {
                $paymentManager = Application::get()->getPaymentManager($journal);
                $paymentManager->fulfillQueuedPayment($request, $queuedPayment, $this->getName());
                $this->notifyEditorPaymentReceived($journal, $queuedPayment);
                $this->showMessage($request, 'plugins.paymethod.qi.success');
                return;
            }

            $status = strtoupper((string) ($responseData['status'] ?? ''));
            if ($status === 'CREATED') {
                // Payment not finalized yet (e.g. still awaiting the webhook); not an error.
                $this->showMessage($request, 'plugins.paymethod.qi.pending');
                return;
            }

            throw new \Exception('Payment state is not successful. Full response: ' . json_encode($responseData));
        } catch (\Exception $e) {
            error_log('Qi Card payment transaction exception: ' . $e->getMessage());
            $this->showMessage($request, 'plugins.paymethod.qi.error');
        }
    }

    /**
     * Query Qi's GET /status endpoint for the current state of a payment.
     */
    protected function fetchPaymentStatus($journalId, $paymentId)
    {
        $testMode = $this->getSetting($journalId, 'qiTestMode');
        $apiHost = $this->getSetting($journalId, 'apiHost');
        if (empty($apiHost)) {
            $apiHost = $testMode ? 'https://uat-sandbox-3ds-api.qi.iq' : 'https://3ds-api.qi.iq';
        }
        $apiHost = rtrim($apiHost, '/');

        $terminalId = $this->getSetting($journalId, 'terminalId');
        $username = $this->getSetting($journalId, 'username');
        $password = $this->getSetting($journalId, 'password');

        $url = $apiHost . '/api/v1/payment/' . urlencode($paymentId) . '/status';
        $headers = [
            'X-Terminal-Id: ' . $terminalId,
            'Authorization: Basic ' . base64_encode($username . ':' . $password),
            'Accept: application/json',
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $responseBody = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new \Exception("cURL error during payment validation: " . $curlError);
        }

        $responseData = json_decode($responseBody, true);
        if ($httpCode !== 200 || !$responseData) {
            throw new \Exception("Qi API returned HTTP code {$httpCode}. Response: {$responseBody}");
        }

        return $responseData;
    }

    /**
     * Per Qi's documented API, a payment's "status" field is one of:
     * SUCCESS, FAILED, CREATED, AUTHENTICATION_FAILED.
     */
    protected function isSuccessfulStatus($responseData)
    {
        if (!empty($responseData['canceled'])) {
            return false;
        }
        $status = strtoupper((string) ($responseData['status'] ?? ''));
        return $status === 'SUCCESS';
    }

    /**
     * Verify amount/currency against the queued payment to prevent tampering.
     */
    protected function amountAndCurrencyMatch($responseData, $queuedPayment)
    {
        if (isset($responseData['amount']) && abs((float) $responseData['amount'] - (float) $queuedPayment->getAmount()) > 0.01) {
            return false;
        }
        if (isset($responseData['currency']) && strtoupper((string) $responseData['currency']) !== strtoupper($queuedPayment->getCurrencyCode())) {
            return false;
        }
        return true;
    }

    /**
     * Verify Qi's webhook X-Signature: base64 RSA-SHA256 signature of
     * "paymentId|amount(.000)|currency|creationDate|status" against the
     * public key Qi's support team provides for this merchant account.
     */
    protected function verifyWebhookSignature($data, $signature, $publicKeyPem)
    {
        $required = ['paymentId', 'amount', 'currency', 'creationDate', 'status'];
        foreach ($required as $field) {
            if (!isset($data[$field])) {
                return false;
            }
        }

        $dataString = implode('|', [
            $data['paymentId'],
            number_format((float) $data['amount'], 3, '.', ''),
            $data['currency'],
            $data['creationDate'],
            $data['status'],
        ]);

        $decodedSignature = base64_decode($signature, true);
        if ($decodedSignature === false) {
            return false;
        }

        $result = openssl_verify($dataString, $decodedSignature, $publicKeyPem, OPENSSL_ALGO_SHA256);
        return $result === 1;
    }

    /**
     * Email all editors assigned to the submission to inform them that the
     * publication fee has been paid. Called after fulfillQueuedPayment() so
     * the CompletedPayment record already exists when the email lands.
     */
    protected function notifyEditorPaymentReceived($journal, $queuedPayment)
    {
        try {
            $submissionId = (int) $queuedPayment->getAssocId();
            $submission = Repo::submission()->get($submissionId);
            if (!$submission) {
                return;
            }

            $title = $submission->getCurrentPublication()
                ? $submission->getCurrentPublication()->getLocalizedTitle()
                : (string) $submissionId;

            // Collect editors assigned to this submission (managers + section editors).
            $editorIds = StageAssignment::withSubmissionIds([$submissionId])
                ->withRoleIds([Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR])
                ->get()
                ->pluck('user_id')
                ->unique()
                ->all();

            if (empty($editorIds)) {
                return;
            }

            foreach ($editorIds as $editorId) {
                $editorUser = Repo::user()->get((int) $editorId);
                if (!$editorUser) {
                    continue;
                }

                $mailable = new Mailable();
                $mailable->to($editorUser->getEmail(), $editorUser->getFullName());
                $mailable->from($journal->getData('contactEmail'), $journal->getData('contactName'));
                $mailable->subject(__('plugins.paymethod.qi.editor.paymentReceived.subject'));
                $mailable->body('<p>' . __('plugins.paymethod.qi.editor.paymentReceived.body', [
                    'submissionId' => $submissionId,
                    'submissionTitle' => htmlspecialchars((string) $title),
                ]) . '</p>');

                Mail::send($mailable);
            }
        } catch (\Exception $e) {
            error_log('Qi: failed to send editor payment notification: ' . $e->getMessage());
        }
    }

    /**
     * Show the author an explicit confirmation/status page instead of a bare redirect.
     */
    protected function showMessage($request, $messageKey)
    {
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign('message', $messageKey);
        $templateMgr->display('frontend/pages/message.tpl');
    }
}

if (!PKP_STRICT_MODE) {
    class_alias('\APP\plugins\paymethod\qi\QiPaymentPlugin', '\QiPaymentPlugin');
}
