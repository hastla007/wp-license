<?php
/**
 * Loads the license client library. Require this file from the add-on.
 *
 * Every class is declared only when it does not exist yet, so two add-ons that
 * carry an unchanged copy (same namespace) do not end in "Cannot declare
 * class". The copy that is loaded first wins; compare `LicenseClient::VERSION`
 * when an add-on needs a newer one. The reliable way is a copy with its own
 * namespace: `php bin/export-client.php` (docs/CLIENT-INTEGRATION.md).
 *
 * @package WPLC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/OptionStore.php';
require_once __DIR__ . '/Messages.php';
require_once __DIR__ . '/LicenseStatus.php';
require_once __DIR__ . '/LicenseClient.php';
require_once __DIR__ . '/Updater.php';
require_once __DIR__ . '/LicenseManager.php';
