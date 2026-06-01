# 💳 Qi Payment Gateway Plugin for OJS

[![OJS Version](https://img.shields.io/badge/OJS-3.x-blue.svg)](https://pkp.sfu.ca/ojs/)
[![License](https://img.shields.io/badge/License-GPL%20v3-green.svg)](https://www.gnu.org/licenses/gpl-3.0)
[![Language Support](https://img.shields.io/badge/Languages-EN%20%7C%20AR-orange.svg)](#multilingual-support)

A modern, secure payment gateway plugin for **Open Journal Systems (OJS) 3.x** that integrates the **Qi Payment Gateway** (Iraq). This plugin enables journals to accept payments for Article Processing Charges (APCs), subscriptions, and other editorial fees in Iraqi Dinar (IQD) and other supported currencies.

---

## ✨ Features

- **Secure Gateway Redirection**: Safely initializes transactions and redirects authors to the secure Qi checkout host.
- **Robust 3D Secure (3DS) Verification**: Verifies payments using Qi's modern status validation query upon return.
- **Isolated Settings Scope**: Configuration fields (like Sandbox Mode) are uniquely namespaced to prevent collisions with other plugins (e.g., PayPal).
- **Flexible API Endpoints**: Easily switch between the Sandbox Environment, Production environment, or define a Custom API Host.
- **Multilingual UI**: Pre-configured with complete translation support for English and Arabic.

---

## 📋 Prerequisites

Before installing the plugin, ensure your server meets the following requirements:
- **OJS 3.x** installed and configured.
- **PHP 8.0 or newer** (with `curl` and `openssl` extensions enabled).
- An active merchant account with **Qi Card** (Terminal ID, API Username, and API Password).

---

## 🚀 Installation

You can install this plugin using either the OJS web dashboard or manually via server command line.

### Method 1: Uploading via OJS Dashboard (Recommended)

1. Create a compressed archive (`qi.tar.gz`) containing the `qi` directory:
   ```bash
   tar -czvf qi.tar.gz qi
   ```
   *(Note: The root directory inside the archive **must** be named `qi`).*
2. Log into OJS as an administrator or journal manager.
3. Go to **Settings ➔ Website ➔ Plugins**.
4. Click on **Upload A New Plugin** at the top right.
5. Select and upload the `qi.tar.gz` archive.

### Method 2: Manual Installation

1. Copy or clone the `qi` folder directly into your OJS installation path under:
   ```text
   plugins/paymethod/qi
   ```
2. Ensure the folder permissions permit your web server (e.g., Apache/Nginx) to read the files.
3. Open your terminal, navigate to the OJS root directory, and run the upgrade script to register the plugin in the database:
   ```bash
   php tools/upgrade.php upgrade
   ```

---

## ⚙️ Configuration

Once installed, follow these steps to configure the gateway settings:

1. Go to **Settings ➔ Workflow ➔ Payment**.
2. Under the **Payment Method** section, select **Qi Payment Gateway** as your active payment method.
3. Fill in the configuration fields:

| Configuration Field | Description |
| :--- | :--- |
| **Sandbox Test Mode** | Enable this checkbox to redirect transactions to the UAT sandbox environment for testing. |
| **Terminal ID** | The Merchant Terminal ID assigned by Qi Card. |
| **Username** | The API Username credentials. |
| **Password** | The API Password credentials. |
| **Custom API Host** | *(Optional)* Override host URL (e.g. `https://uat-sandbox-3ds-api.qi.iq`). Leave blank to use default production or sandbox endpoints. |

4. Click **Save**.

---

## 🧪 Sandbox Testing Guide

To test the payment flow end-to-end, enable **Sandbox Test Mode** and use the official sandbox credentials listed below:

### 🔑 Sandbox Credentials
- **Terminal ID**: `237984`
- **Username**: `paymentgatewaytest`
- **Password**: `WHaNFE5C3qlChqNbAzH4`

### 💳 Test Card Details
Use the following details on the Qi sandbox checkout page:
- **Card Number**: `5213 7203 0423 8582`
- **Expiry Date**: `01/32` (January 2032)
- **CVV**: `642`
- **3DS Verification OTP**: `123123`

> [!TIP]  
> Qi Sandbox requires the transaction currency to be set to **Iraqi Dinar (IQD)**. Verify that your journal's currency is set to IQD under **Settings ➔ Journal** when testing transactions.

---

## 🛠️ Troubleshooting & Technical Notes

### 1. Verification Endpoint Returning 500
The verification system queries Qi's `/status` endpoint to confirm if a card payment is finalized. If you override the **Custom API Host**, do **not** add a trailing slash or `/status` at the end of the host field. The plugin handles endpoint generation dynamically:
* Correct API Host format: `https://uat-sandbox-3ds-api.qi.iq`

### 2. Sandbox Form State `FORM_SHOWED`
If a payment status shows as `FORM_SHOWED` in your logs, this means the transaction was initialized successfully, but the user has not yet typed in the card details or finalized OTP verification.

---

## 🌍 Multilingual Support

The plugin is designed to adapt to your journal's primary language.

- **English**: Configuration labels, payment instructions, and error reports are localized in English.
- **Arabic (`بوابة دفع كي`)**: The plugin includes complete localization for Arabic-speaking users, adapting automatically when the OJS active language changes.

---

## 📄 License
This project is licensed under the GNU General Public License v3.0 - see [OJS COPYING](https://github.com/pkp/ojs/blob/main/docs/COPYING) for details.
