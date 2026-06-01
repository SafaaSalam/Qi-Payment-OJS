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
use APP\template\TemplateManager;
use Illuminate\Support\Collection;
use PKP\components\forms\context\PKPPaymentSettingsForm;
use PKP\config\Config;
use PKP\db\DAORegistry;
use PKP\plugins\Hook;
use PKP\plugins\PaymethodPlugin;

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
        if ($form->id !== PKPPaymentSettingsForm::FORM_PAYMENT_SETTINGS) {
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
     * Handle a handshake/callback with the Qi service
     */
    public function handle($args, $request)
    {
        // Check sandbox configuration
        if (Config::getVar('general', 'sandbox', false)) {
            error_log('Application is set to sandbox mode and no payment will be done via Qi');
            return;
        }

        $journal = $request->getJournal();
        $journalId = $journal->getId();
        $queuedPaymentDao = DAORegistry::getDAO('QueuedPaymentDAO'); /** @var \PKP\payment\QueuedPaymentDAO $queuedPaymentDao */

        try {
            $queuedPaymentId = $request->getUserVar('queuedPaymentId');
            $queuedPayment = $queuedPaymentDao->getById($queuedPaymentId);
            if (!$queuedPayment) {
                throw new \Exception("Invalid queued payment ID {$queuedPaymentId}!");
            }

            // Retrieve the payment ID appended by Qi Card redirect
            $paymentId = $request->getUserVar('paymentId') ?: $request->getUserVar('id') ?: $request->getUserVar('transactionId');
            if (empty($paymentId)) {
                throw new \Exception("Payment ID not found in callback query parameters!");
            }

            // Set up API host
            $testMode = $this->getSetting($journalId, 'qiTestMode');
            $apiHost = $this->getSetting($journalId, 'apiHost');
            if (empty($apiHost)) {
                $apiHost = $testMode ? 'https://uat-sandbox-3ds-api.qi.iq' : 'https://3ds-api.qi.iq';
            }
            $apiHost = rtrim($apiHost, '/');

            // Setup credentials
            $terminalId = $this->getSetting($journalId, 'terminalId');
            $username = $this->getSetting($journalId, 'username');
            $password = $this->getSetting($journalId, 'password');

            // Setup Curl Request for checking status
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

            // Verify payment status
            $isSuccess = false;

            // Check 1: Direct status / state fields
            if (isset($responseData['status'])) {
                $status = strtoupper($responseData['status']);
                if (in_array($status, ['SUCCESS', 'APPROVED', 'COMPLETED', 'SUCCESSFUL', 'MERCHANT_ACCEPT', 'AUTH_SUCCESS', 'PAID'])) {
                    $isSuccess = true;
                }
            } elseif (isset($responseData['state'])) {
                $state = strtoupper($responseData['state']);
                if (in_array($state, ['SUCCESS', 'APPROVED', 'COMPLETED', 'SUCCESSFUL', 'PAID'])) {
                    $isSuccess = true;
                }
            }

            // Check 2: Nested resultStatus field (used in some QiCard API versions)
            if (isset($responseData['result']['resultStatus'])) {
                if ($responseData['result']['resultStatus'] === 'S') {
                    $isSuccess = true;
                }
            }
            if (isset($responseData['paymentStatus'])) {
                $payStatus = strtoupper($responseData['paymentStatus']);
                if (in_array($payStatus, ['SUCCESS', 'APPROVED', 'COMPLETED', 'SUCCESSFUL', 'MERCHANT_ACCEPT', 'AUTH_SUCCESS', 'PAID'])) {
                    $isSuccess = true;
                }
            }

            if (!$isSuccess) {
                throw new \Exception("Payment state is not successful. Full Response: " . json_encode($responseData));
            }

            // Verify amount and currency to prevent bypass tampering
            $respAmount = null;
            if (isset($responseData['amount'])) {
                $respAmount = (float) $responseData['amount'];
            }
            $respCurrency = null;
            if (isset($responseData['currency'])) {
                $respCurrency = (string) $responseData['currency'];
            }

            if ($respAmount !== null && abs($respAmount - (float) $queuedPayment->getAmount()) > 0.01) {
                throw new \Exception("Amount mismatch: expected {$queuedPayment->getAmount()} but got {$respAmount}.");
            }
            if ($respCurrency !== null && strtoupper($respCurrency) !== strtoupper($queuedPayment->getCurrencyCode())) {
                throw new \Exception("Currency mismatch: expected {$queuedPayment->getCurrencyCode()} but got {$respCurrency}.");
            }

            // Payment is valid, fulfill it
            $paymentManager = Application::get()->getPaymentManager($journal);
            $paymentManager->fulfillQueuedPayment($request, $queuedPayment, $this->getName());
            
            // Redirect back to request URL
            $request->redirectUrl($queuedPayment->getRequestUrl());

        } catch (\Exception $e) {
            error_log('Qi Card payment transaction exception: ' . $e->getMessage());
            $templateMgr = TemplateManager::getManager($request);
            $templateMgr->assign('message', 'plugins.paymethod.qi.error');
            $templateMgr->display('frontend/pages/message.tpl');
        }
    }
}

if (!PKP_STRICT_MODE) {
    class_alias('\APP\plugins\paymethod\qi\QiPaymentPlugin', '\QiPaymentPlugin');
}
