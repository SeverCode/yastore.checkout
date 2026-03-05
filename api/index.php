<?php
/**
 * Точка входа API
 */

require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

\Bitrix\Main\Loader::includeModule('yastore.checkout');

$authHeader = \Yastore\Checkout\Api::getAuthorizationHeader();

$isValidToken = false;
if ($authHeader !== '' && strpos($authHeader, 'Bearer ') === 0) {
    $token = substr($authHeader, 7);
    $validToken = \Bitrix\Main\Config\Option::get('yastore.checkout', 'JWT_TOKEN', '');
    
    if (!empty($validToken) && is_string($token) && hash_equals($validToken, $token)) {
        $isValidToken = true;
    }
}

if (!$isValidToken) {
    if (!defined("ERROR_404")) {
        define("ERROR_404", "Y");
    }
    
    \CHTTP::setStatus("404 Not Found");
    
    global $APPLICATION;
    if ($APPLICATION->RestartWorkarea()) {
        require(\Bitrix\Main\Application::getDocumentRoot() . "/404.php");
    }
    die();
}

use Yastore\Checkout\Api;

$api = new Api();
$api->handleRequest();
