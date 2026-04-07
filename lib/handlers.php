<?php
namespace Yastore\Checkout;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Page\Asset;
use Bitrix\Sale\Notify;
use Bitrix\Sale\Order;


class Handlers
{
    static $MODULE_ID = "yastore.checkout";

    /**
     * Ранний обработчик OnSaleOrderSaved (sort=1): отключает почтовые уведомления Sale,
     * если в настройках модуля не включено «Отправлять письма о заказах».
     */
    public static function onSaleOrderSavedMailGate($event)
    {
        if (Option::get(self::$MODULE_ID, 'SEND_ORDER_EMAILS', 'N') !== 'Y') {
            if (Loader::includeModule('sale')) {
                Notify::setNotifyDisable(true);
            }
        }
    }

    /**
     * Подключение JS/CSS кнопки «Купить в 1 клик» на странице корзины.
     */
    public static function appendYandexCheckoutJs()
    {
        $request = \Bitrix\Main\Context::getCurrent()->getRequest();
        $requestPage = $request->getRequestedPage();
        $basketPath = trim((string) Option::get(self::$MODULE_ID, 'YAKIT_BASKET_PAGE_PATH', '/personal/cart/'));
        if ($basketPath === '') {
            $basketPath = '/personal/cart/';
        }
        $basketPathNorm = trim(trim($basketPath), '/');
        $showButton = Option::get(self::$MODULE_ID, 'SHOW_BUTTON', 'N');

        $onBasketPage = ($basketPathNorm !== '' && (strpos($requestPage, $basketPathNorm) !== false));
        if ($showButton !== 'Y' || !$onBasketPage) {
            return;
        }

        $buttonAnchor = Option::get(self::$MODULE_ID, 'BUTTON_ANCHOR', '.basket-checkout-section-inner');
        $buttonInsertAfter = Option::get(self::$MODULE_ID, 'YAKIT_BUTTON_INSERT_AFTER', '');
        $buttonCss = trim(Option::get(self::$MODULE_ID, 'YAKIT_BUTTON_CSS', ''));
        if ($buttonCss === '') {
            $buttonCss = self::getDefaultButtonCss();
        }
        $buttonCss = str_replace('</style>', '', $buttonCss);
        $buttonCssJs = str_replace(["\\", "\r", "\n"], ["\\\\", "\\r", "\\n"], $buttonCss);
        $buttonCssJs = str_replace(["'"], ["\\'"], $buttonCssJs);

        $buttonAnchor = str_replace(["'", '"', '\\'], ["\\'", '\\"', '\\\\'], $buttonAnchor);
        $buttonInsertAfter = str_replace(["'", '"', '\\'], ["\\'", '\\"', '\\\\'], $buttonInsertAfter);

        Asset::getInstance()->addString(
            "<script>\n" .
            "var BUTTON_ANCHOR = '" . $buttonAnchor . "';\n" .
            "var YAKIT_BUTTON_INSERT_AFTER = '" . $buttonInsertAfter . "';\n" .
            "var YAKIT_BUTTON_CSS = '" . $buttonCssJs . "';\n" .
            "</script>"
        );
        Asset::getInstance()->addJs('/bitrix/js/yastore.checkout/script.js');
    }

    private static function getDefaultButtonCss()
    {
        return "#yastore-checkout-button {\n"
            . "    border: none;\n"
            . "    outline: none;\n"
            . "    border-radius: 12px;\n"
            . "    background-color: rgb(255, 99, 41);\n"
            . "    color: #ffffff;\n"
            . "    padding: 0 12px;\n"
            . "    margin: 12px 0 0 10px;\n"
            . "    height: 42px;\n"
            . "    width: 100%;\n"
            . "    font-weight: 600;\n"
            . "    font-family: inherit;\n"
            . "    line-height: 16px;\n"
            . "    display: flex;\n"
            . "    gap: 6px;\n"
            . "    align-items: center;\n"
            . "    justify-content: center;\n"
            . "    transition: transform .12s ease-out, filter .12s ease-out;\n"
            . "}\n"
            . "#yastore-checkout-button:hover {\n"
            . "    background: linear-gradient(245deg, rgba(255, 99, 41, 0) 85%, #FFC002 109%), linear-gradient(77deg, rgb(255, 192, 2, .5) .5%, rgba(255, 99, 41, .18) 24%), radial-gradient(57% 134.79% at 0% 0%, #FF27F5 0%, #FF6329 100%);\n"
            . "}\n"
            . "#yastore-checkout-button:active {\n"
            . "    transform: scale(.97);\n"
            . "    filter: brightness(0.9);\n"
            . "}";
    }

    /**
     * Обработчик события OnSaleOrderSaved
     * Вызывается при каждом сохранении заказа
     * Проверяет статус заказа и отправляет запрос на отмену, если статус = статусу отмены
     * 
     * @param \Bitrix\Main\Event|Order $eventOrOrder
     * @return void
     */
    public static function onSaleOrderSaved($eventOrOrder)
    {
        try {
            // Проверяем, включена ли автоматическая отмена
            $autoCancelEnabled = Option::get(self::$MODULE_ID, 'AUTO_CANCEL_ON_STATUS_CHANGE', 'N');
            
            if ($autoCancelEnabled !== 'Y') {
                return;
            }

            // Получаем объект Order
            /** @var Order $order */
            if ($eventOrOrder instanceof \Bitrix\Main\Event) {
                // Если передан Event, получаем Order из параметров
                $parameters = $eventOrOrder->getParameters();
                $order = $parameters['ENTITY'] ?? null;
            } elseif ($eventOrOrder instanceof Order) {
                // Если передан напрямую Order
                $order = $eventOrOrder;
            } else {
                return;
            }

            // Проверяем, что это объект Order
            if (!$order instanceof Order) {
                return;
            }

            // Получаем ID заказа
            $orderId = $order->getId();

            if ($orderId <= 0) {
                return;
            }

            // Получаем статус для автоматической отмены из настроек
            $autoCancelStatus = Option::get(self::$MODULE_ID, 'AUTO_CANCEL_STATUS', 'C');
            
            // Ориентируемся только на текущий статус заказа
            $currentStatus = $order->getField('STATUS_ID');

            // Проверяем, соответствует ли текущий статус настройке для отмены
            if ($currentStatus === $autoCancelStatus) {
                // Записываем в историю заказа об изменении статуса на статус отмены
                if (class_exists('\Bitrix\Sale\OrderHistory')) {
                    \Bitrix\Sale\OrderHistory::addAction(
                        'ORDER',
                        $orderId,
                        'ORDER_COMMENTED',
                        $orderId,
                        $order,
                        ['COMMENTS' => 'Статус заказа изменен на статус отмены (' . $currentStatus . '). Инициирована автоматическая отмена через Яндекс KIT API.']
                    );
                }

                // Отправляем curl запрос на отмену
                self::sendCancelRequest($orderId, $order);
            }

            // Проверяем автоматическое завершение заказа
            $autoCompleteEnabled = Option::get(self::$MODULE_ID, 'AUTO_COMPLETE_ON_STATUS_CHANGE', 'N');
            if ($autoCompleteEnabled === 'Y') {
                $autoCompleteStatus = Option::get(self::$MODULE_ID, 'AUTO_COMPLETE_STATUS', 'F');
                
                // Проверяем, соответствует ли текущий статус настройке для завершения
                if ($currentStatus === $autoCompleteStatus) {
                    // Записываем в историю заказа об изменении статуса на статус завершения
                    if (class_exists('\Bitrix\Sale\OrderHistory')) {
                        \Bitrix\Sale\OrderHistory::addAction(
                            'ORDER',
                            $orderId,
                            'ORDER_COMMENTED',
                            $orderId,
                            $order,
                            ['COMMENTS' => 'Статус заказа изменен на статус завершения (' . $currentStatus . '). Инициировано автоматическое завершение через Яндекс KIT API.']
                        );
                    }
                    
                    // Отправляем curl запрос на завершение
                    self::sendCompleteRequest($orderId, $order);
                }
            }

        } catch (\Exception $e) {
            \Bitrix\Main\Application::getInstance()->getExceptionHandler()->writeToLog($e);
        }
    }

    /**
     * Обработчик события OnSaleStatusOrderChange
     * Вызывается при изменении статуса заказа
     * 
     * @param \Bitrix\Main\Event|Order $eventOrOrder
     * @return void
     */
    public static function onSaleStatusOrderChange($eventOrOrder)
    {
        try {
            // Проверяем, включена ли автоматическая отмена
            $autoCancelEnabled = Option::get(self::$MODULE_ID, 'AUTO_CANCEL_ON_STATUS_CHANGE', 'N');
            
            if ($autoCancelEnabled !== 'Y') {
                return;
            }

            // Получаем объект Order
            /** @var Order $order */
            if ($eventOrOrder instanceof \Bitrix\Main\Event) {
                // Если передан Event, получаем Order из параметров
                $parameters = $eventOrOrder->getParameters();
                $order = $parameters['ENTITY'] ?? null;
            } elseif ($eventOrOrder instanceof Order) {
                // Если передан напрямую Order
                $order = $eventOrOrder;
            } else {
                return;
            }

            // Проверяем, что это объект Order
            if (!$order instanceof Order) {
                return;
            }

            // Получаем ID заказа
            $orderId = $order->getId();

            // Получаем статус для автоматической отмены из настроек
            $autoCancelStatus = Option::get(self::$MODULE_ID, 'AUTO_CANCEL_STATUS', 'C');
            
            // Ориентируемся только на текущий статус заказа
            $currentStatus = $order->getField('STATUS_ID');

            // Проверяем, соответствует ли текущий статус настройке для отмены
            if ($currentStatus === $autoCancelStatus) {
                if ($orderId > 0) {
                    // Отправляем curl запрос на отмену
                    self::sendCancelRequest($orderId, $order);
                }
            }

            // Проверяем автоматическое завершение заказа
            $autoCompleteEnabled = Option::get(self::$MODULE_ID, 'AUTO_COMPLETE_ON_STATUS_CHANGE', 'N');
            if ($autoCompleteEnabled === 'Y') {
                $autoCompleteStatus = Option::get(self::$MODULE_ID, 'AUTO_COMPLETE_STATUS', 'F');
                
                // Проверяем, соответствует ли текущий статус настройке для завершения
                if ($currentStatus === $autoCompleteStatus && $orderId > 0) {
                    // Записываем в историю заказа об изменении статуса на статус завершения
                    if (class_exists('\Bitrix\Sale\OrderHistory')) {
                        \Bitrix\Sale\OrderHistory::addAction(
                            'ORDER',
                            $orderId,
                            'ORDER_COMMENTED',
                            $orderId,
                            $order,
                            ['COMMENTS' => 'Статус заказа изменен на статус завершения (' . $currentStatus . '). Инициировано автоматическое завершение через Яндекс KIT API.']
                        );
                    }
                    
                    // Отправляем curl запрос на завершение
                    self::sendCompleteRequest($orderId, $order);
                }
            }

        } catch (\Exception $e) {
            \Bitrix\Main\Application::getInstance()->getExceptionHandler()->writeToLog($e);
        }
    }

    /**
     * Отправка curl запроса для отмены заказа
     * 
     * @param int $orderId ID заказа
     * @param Order $order Объект заказа
     * @return void
     */
    private static function sendCancelRequest($orderId, Order $order)
    {
        // Получаем токен API из настроек
        // Получаем токен в формате STORE_ID#TOKEN
        $credentials = Option::get(self::$MODULE_ID, 'YANDEX_KIT_CREDENTIALS', '');
        
        // Если новое поле пустое, используем старые поля для обратной совместимости
        if (empty($credentials)) {
            $storeId = Option::get(self::$MODULE_ID, 'YANDEX_KIT_STORE_ID', '');
            $apiToken = Option::get(self::$MODULE_ID, 'YANDEX_KIT_API_TOKEN', '');
        } else {
            // Разбиваем на STORE_ID и TOKEN
            $parts = explode('#', $credentials, 2);
            if (count($parts) === 2) {
                $storeId = trim($parts[0]);
                $apiToken = trim($parts[1]);
            } else {
                $storeId = '';
                $apiToken = '';
            }
        }
        
        if (empty($apiToken) || empty($storeId)) {
            return;
        }

        // Получаем внешний ID заказа из свойств заказа
        $externalOrderId = self::getExternalOrderId($order);
        
        if (empty($externalOrderId)) {
            return;
        }

        // Получаем адрес API из настроек
        $apiUrl = Option::get(self::$MODULE_ID, 'YANDEX_KIT_API_URL', 'https://integration.yastore.yandex.net/');
        // Убираем завершающий слэш, если есть
        $apiUrl = rtrim($apiUrl, '/');
        
        // URL для отмены заказа
        $url = "{$apiUrl}/api/public/v1/orders/{$externalOrderId}/cancel";

        // Заголовки для запроса
        $headers = [
            'authorization: Bearer ' . $apiToken,
            'yandex-kit-store-id: ' . $storeId,
            'Content-Type: application/json'
        ];

        // Логируем запрос
        $logFile = sys_get_temp_dir() . '/yastore_checkout_cancel.log';
        $logData = [
            'timestamp' => date('Y-m-d H:i:s'),
            'order_id' => $orderId,
            'external_order_id' => $externalOrderId,
            'request' => [
                'url' => $url,
                'method' => 'POST',
                'headers' => [
                    'authorization' => 'Bearer ' . substr($apiToken, 0, 10) . '...',
                    'yandex-kit-store-id' => $storeId,
                    'Content-Type' => 'application/json'
                ]
            ]
        ];

        // Подготавливаем curl запрос
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        // Выполняем запрос
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        // Логируем ответ
        $logData['response'] = [
            'http_code' => $httpCode,
            'error' => $error ?: null,
            'body' => $response ?: null
        ];

        // Записываем в лог-файл
        file_put_contents($logFile, json_encode($logData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n", FILE_APPEND);

        // Обрабатываем результат
        if ($error) {
            \Bitrix\Main\Application::getInstance()->getExceptionHandler()->writeToLog(
                new \Exception("Curl error for order cancel: {$error}")
            );
            
            // Записываем в историю заказа об ошибке отправки отмены
            if (class_exists('\Bitrix\Sale\OrderHistory')) {
                \Bitrix\Sale\OrderHistory::addAction(
                    'ORDER',
                    $orderId,
                    'ORDER_COMMENTED',
                    $orderId,
                    $order,
                    ['COMMENTS' => 'Ошибка отправки отмены заказа в Яндекс KIT API: ' . $error]
                );
            }
        } elseif ($httpCode < 200 || $httpCode >= 300) {
            \Bitrix\Main\Application::getInstance()->getExceptionHandler()->writeToLog(
                new \Exception("Failed to cancel order {$externalOrderId}. HTTP code: {$httpCode}, Response: {$response}")
            );
            
            // Записываем в историю заказа об ошибке отправки отмены
            if (class_exists('\Bitrix\Sale\OrderHistory')) {
                \Bitrix\Sale\OrderHistory::addAction(
                    'ORDER',
                    $orderId,
                    'ORDER_COMMENTED',
                    $orderId,
                    $order,
                    ['COMMENTS' => 'Ошибка отправки отмены заказа в Яндекс KIT API. HTTP код: ' . $httpCode . ', Внешний ID: ' . $externalOrderId]
                );
            }
        } else {
            // Записываем в историю заказа об успешной отправке отмены
            if (class_exists('\Bitrix\Sale\OrderHistory')) {
                \Bitrix\Sale\OrderHistory::addAction(
                    'ORDER',
                    $orderId,
                    'ORDER_COMMENTED',
                    $orderId,
                    $order,
                    ['COMMENTS' => 'Отмена заказа успешно отправлена в Яндекс KIT API. Внешний ID: ' . $externalOrderId]
                );
            }
        }
    }

    /**
     * Отправка curl запроса для завершения заказа
     * 
     * @param int $orderId ID заказа
     * @param Order $order Объект заказа
     * @return void
     */
    private static function sendCompleteRequest($orderId, Order $order)
    {
        // Получаем токен API из настроек
        // Получаем токен в формате STORE_ID#TOKEN
        $credentials = Option::get(self::$MODULE_ID, 'YANDEX_KIT_CREDENTIALS', '');
        
        // Если новое поле пустое, используем старые поля для обратной совместимости
        if (empty($credentials)) {
            $storeId = Option::get(self::$MODULE_ID, 'YANDEX_KIT_STORE_ID', '');
            $apiToken = Option::get(self::$MODULE_ID, 'YANDEX_KIT_API_TOKEN', '');
        } else {
            // Разбиваем на STORE_ID и TOKEN
            $parts = explode('#', $credentials, 2);
            if (count($parts) === 2) {
                $storeId = trim($parts[0]);
                $apiToken = trim($parts[1]);
            } else {
                $storeId = '';
                $apiToken = '';
            }
        }
        
        if (empty($apiToken) || empty($storeId)) {
            return;
        }

        // Получаем внешний ID заказа из свойств заказа
        $externalOrderId = self::getExternalOrderId($order);
        
        if (empty($externalOrderId)) {
            return;
        }

        // Получаем адрес API из настроек
        $apiUrl = Option::get(self::$MODULE_ID, 'YANDEX_KIT_API_URL', 'https://integration.yastore.yandex.net/');
        // Убираем завершающий слэш, если есть
        $apiUrl = rtrim($apiUrl, '/');
        
        // URL для завершения заказа
        $url = "{$apiUrl}/api/public/v1/orders/{$externalOrderId}/delivery/total_complete/self_pick_up";

        // Заголовки для запроса
        $headers = [
            'authorization: Bearer ' . $apiToken,
            'yandex-kit-store-id: ' . $storeId,
            'Content-Type: application/json'
        ];

        // Логируем запрос
        $logFile = sys_get_temp_dir() . '/yastore_checkout_complete.log';
        $logData = [
            'timestamp' => date('Y-m-d H:i:s'),
            'order_id' => $orderId,
            'external_order_id' => $externalOrderId,
            'request' => [
                'url' => $url,
                'method' => 'POST',
                'headers' => [
                    'authorization' => 'Bearer ' . substr($apiToken, 0, 10) . '...',
                    'yandex-kit-store-id' => $storeId,
                    'Content-Type' => 'application/json'
                ]
            ]
        ];

        // Подготавливаем curl запрос
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        // Выполняем запрос
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        // Логируем ответ
        $logData['response'] = [
            'http_code' => $httpCode,
            'error' => $error ?: null,
            'body' => $response ?: null
        ];

        // Записываем в лог-файл
        file_put_contents($logFile, json_encode($logData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n", FILE_APPEND);

        // Обрабатываем результат
        if ($error) {
            \Bitrix\Main\Application::getInstance()->getExceptionHandler()->writeToLog(
                new \Exception("Curl error for order complete: {$error}")
            );
            
            // Записываем в историю заказа об ошибке отправки завершения
            if (class_exists('\Bitrix\Sale\OrderHistory')) {
                \Bitrix\Sale\OrderHistory::addAction(
                    'ORDER',
                    $orderId,
                    'ORDER_COMMENTED',
                    $orderId,
                    $order,
                    ['COMMENTS' => 'Ошибка отправки завершения заказа в Яндекс KIT API: ' . $error]
                );
            }
        } elseif ($httpCode < 200 || $httpCode >= 300) {
            \Bitrix\Main\Application::getInstance()->getExceptionHandler()->writeToLog(
                new \Exception("Failed to complete order {$externalOrderId}. HTTP code: {$httpCode}, Response: {$response}")
            );
            
            // Записываем в историю заказа об ошибке отправки завершения
            if (class_exists('\Bitrix\Sale\OrderHistory')) {
                \Bitrix\Sale\OrderHistory::addAction(
                    'ORDER',
                    $orderId,
                    'ORDER_COMMENTED',
                    $orderId,
                    $order,
                    ['COMMENTS' => 'Ошибка отправки завершения заказа в Яндекс KIT API. HTTP код: ' . $httpCode . ', Внешний ID: ' . $externalOrderId]
                );
            }
        } else {
            // Записываем в историю заказа об успешной отправке завершения
            if (class_exists('\Bitrix\Sale\OrderHistory')) {
                \Bitrix\Sale\OrderHistory::addAction(
                    'ORDER',
                    $orderId,
                    'ORDER_COMMENTED',
                    $orderId,
                    $order,
                    ['COMMENTS' => 'Завершение заказа успешно отправлено в Яндекс KIT API. Внешний ID: ' . $externalOrderId]
                );
            }
        }
    }

    /**
     * Получение внешнего ID заказа из свойств заказа.
     * Возвращает ID только для заказов, созданных через KIT (есть YANDEX_ORDER_ID или EXTERNAL_ORDER_ID).
     * Обычные заказы Bitrix (только XML_ID вида bx_XXXX) намеренно игнорируются.
     * Приоритет: YANDEX_ORDER_ID (kit_order_id) > EXTERNAL_ORDER_ID
     * 
     * @param Order $order Объект заказа
     * @return string|null Внешний ID заказа или null
     */
    private static function getExternalOrderId(Order $order)
    {
        try {
            $propertyCollection = $order->getPropertyCollection();
            
            // Сначала ищем свойство YANDEX_ORDER_ID (kit_order_id)
            foreach ($propertyCollection as $property) {
                $code = $property->getField('CODE');
                if ($code === 'YANDEX_ORDER_ID') {
                    $value = $property->getValue();
                    if (!empty($value)) {
                        return $value;
                    }
                }
            }
            
            // Если не нашли YANDEX_ORDER_ID, ищем EXTERNAL_ORDER_ID
            foreach ($propertyCollection as $property) {
                $code = $property->getField('CODE');
                if ($code === 'EXTERNAL_ORDER_ID') {
                    $value = $property->getValue();
                    if (!empty($value)) {
                        return $value;
                    }
                }
            }
            
            // XML_ID намеренно не используется: у обычных заказов Bitrix там всегда bx_XXXX,
            // что приводило бы к отправке запросов в KIT для не-KIT заказов.
            
        } catch (\Exception $e) {
            \Bitrix\Main\Application::getInstance()->getExceptionHandler()->writeToLog($e);
        }
        
        return null;
    }
}
