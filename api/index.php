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
    $tokenReceived = ($authHeader !== '' && strpos($authHeader, 'Bearer ') === 0)
        ? substr($authHeader, 7)
        : $authHeader;
    if ($tokenReceived === '') {
        $tokenForResponse = '(empty)';
    } elseif (strlen($tokenReceived) > 16) {
        $tokenForResponse = substr($tokenReceived, 0, 8) . '…' . substr($tokenReceived, -4);
    } else {
        $tokenForResponse = substr($tokenReceived, 0, 4) . '…';
    }

    header('Content-Type: application/json; charset=utf-8');
    \CHTTP::setStatus("404 Not Found");
    echo \Bitrix\Main\Web\Json::encode([
        'error' => 'Invalid or missing token. Received: ' . $tokenForResponse
    ]);
    die();
}

use Yastore\Checkout\Api;

$api = new Api();
$api->handleRequest();
