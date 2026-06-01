<?php

/**
 * @file plugins/paymethod/qi/QiPaymentForm.php
 *
 * Copyright (c) 2014-2026 Simon Fraser University
 * Copyright (c) 2003-2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class QiPaymentForm
 *
 * Form for Qi-based payments.
 *
 */

namespace APP\plugins\paymethod\qi;

use APP\core\Application;
use APP\core\Request;
use APP\template\TemplateManager;
use PKP\config\Config;
use PKP\form\Form;
use PKP\payment\QueuedPayment;

class QiPaymentForm extends Form
{
    /** @var QiPaymentPlugin */
    public $_qiPaymentPlugin;

    /** @var QueuedPayment */
    public $_queuedPayment;

    /**
     * @param QiPaymentPlugin $qiPaymentPlugin
     * @param QueuedPayment $queuedPayment
     */
    public function __construct($qiPaymentPlugin, $queuedPayment)
    {
        $this->_qiPaymentPlugin = $qiPaymentPlugin;
        $this->_queuedPayment = $queuedPayment;
        parent::__construct(null);
    }

    /**
     * @copydoc Form::display()
     *
     * @param null|Request $request
     * @param null|mixed $template
     */
    public function display($request = null, $template = null)
    {
        // Check sandbox configuration
        if (Config::getVar('general', 'sandbox', false)) {
            error_log('Application is set to sandbox mode and no payment will be done via Qi');
            TemplateManager::getManager($request)
                ->assign('message', 'common.sandbox')
                ->display('frontend/pages/message.tpl');
            return;
        }

        try {
            $journal = $request->getJournal();
            $journalId = $journal->getId();
            
            // Set up API host
            $testMode = $this->_qiPaymentPlugin->getSetting($journalId, 'qiTestMode');
            $apiHost = $this->_qiPaymentPlugin->getSetting($journalId, 'apiHost');
            if (empty($apiHost)) {
                $apiHost = $testMode ? 'https://uat-sandbox-3ds-api.qi.iq' : 'https://3ds-api.qi.iq';
            }
            $apiHost = rtrim($apiHost, '/');

            // Setup credentials
            $terminalId = $this->_qiPaymentPlugin->getSetting($journalId, 'terminalId');
            $username = $this->_qiPaymentPlugin->getSetting($journalId, 'username');
            $password = $this->_qiPaymentPlugin->getSetting($journalId, 'password');

            // Construct callback return URL
            $returnUrl = $request->url(
                null,
                'payment',
                'plugin',
                [$this->_qiPaymentPlugin->getName(), 'return'],
                ['queuedPaymentId' => $this->_queuedPayment->getId()]
            );

            // Construct POST payload
            $payload = [
                'requestId' => uniqid('qi_', true),
                'amount' => (float) $this->_queuedPayment->getAmount(),
                'currency' => $this->_queuedPayment->getCurrencyCode(),
                'redirectUrl' => $returnUrl,
                'returnUrl' => $returnUrl,
                'callbackUrl' => $returnUrl,
                'withoutAuthenticate' => false, // Require 3DS challenge for security
                'appChannel' => false,
            ];

            $url = $apiHost . '/api/v1/payment';
            $headers = [
                'X-Terminal-Id: ' . $terminalId,
                'Authorization: Basic ' . base64_encode($username . ':' . $password),
                'Content-Type: application/json',
                'Accept: application/json',
            ];

            // Send Curl request
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);

            $responseBody = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                throw new \Exception("cURL error during payment creation: " . $curlError);
            }

            $responseData = json_decode($responseBody, true);
            if ($httpCode !== 200 && $httpCode !== 201) {
                throw new \Exception("Qi API returned HTTP code {$httpCode}. Response: {$responseBody}");
            }

            // Redirect user to the payment form URL returned by the gateway
            if (!empty($responseData['formUrl'])) {
                $request->redirectUrl($responseData['formUrl']);
                return;
            }

            throw new \Exception("No formUrl returned from Qi Card payment API! Response: {$responseBody}");

        } catch (\Exception $e) {
            error_log('Qi Card payment creation exception: ' . $e->getMessage());
            $templateMgr = TemplateManager::getManager($request);
            $templateMgr->assign('message', 'plugins.paymethod.qi.error');
            $templateMgr->display('frontend/pages/message.tpl');
        }
    }
}
