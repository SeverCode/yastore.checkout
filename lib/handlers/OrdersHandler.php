<?php
namespace Yastore\Checkout\Handlers;

use Bitrix\Main\Loader;
use Bitrix\Main\Web\Json;
use Bitrix\Main\Config\Option;
use Yastore\Checkout\ProductIdResolver;
use Bitrix\Main\Context;
use Bitrix\Sale\Order;
use Bitrix\Sale\Basket;
use Bitrix\Sale\Delivery\Services\Manager as DeliveryManager;
use Bitrix\Sale\PaySystem\Manager as PaySystemManager;
use Bitrix\Sale\Configuration;
use Bitrix\Catalog\StoreProductTable;
use Bitrix\Catalog\StoreTable;
use Bitrix\Catalog\ProductTable;
use Bitrix\Sale\Internals\OrderPropsValueTable;
use Bitrix\Sale\Registry;

class OrdersHandler extends BaseHandler
{
    public function handle($orderId = null)
    {
        $action = $this->request->get('action');
        
        switch ($action) {
            case 'placed':
                $this->handleMarkOrderPlaced($orderId);
                break;
            case 'cancel':
                $this->handleCancelOrder($orderId);
                break;
            case 'delivered':
                $this->handleMarkOrderDelivered($orderId);
                break;
            default:
                $this->handleCreateOrder($orderId);
                break;
        }
    }
    
    private function handleCreateOrder($orderId = null)
    {
        try {
            if (!Loader::includeModule('sale') || !Loader::includeModule('catalog')) {
                $this->sendError('Required modules not available', 500);
                return;
            }

            $input = file_get_contents('php://input');
            $requestData = Json::decode($input);

            if (empty($requestData['order_id']) || empty($requestData['warehouse_id']) || 
                empty($requestData['items']) || empty($requestData['customer']) || 
                empty($requestData['delivery'])) {
                $this->sendError('Invalid request format: required fields are missing', 400);
                return;
            }

            $externalOrderId = $requestData['order_id'];
            // Для старых версий/ретраев: если kit_order_id не передан, считаем kit_order_id = order_id
            $kitOrderId = !empty($requestData['kit_order_id']) ? $requestData['kit_order_id'] : $externalOrderId;
            $warehouseId = $requestData['warehouse_id'];
            $items = $requestData['items'];
            $customer = $requestData['customer'];
            $delivery = $requestData['delivery'];
            $paymentMethod = $requestData['payment_method'] ?? null;

            // Проверка дубликата по order_id (XML_ID)
            $existingByXmlId = Order::getList([
                'filter' => ['XML_ID' => $externalOrderId],
                'select' => ['ID', 'XML_ID', 'CANCELED'],
                'limit' => 1
            ])->fetch();

            if ($existingByXmlId) {
                $this->sendErrorWithData(
                    'Order already exists',
                    409,
                    self::ERROR_CONFLICT,
                    ['order_id' => (string)$existingByXmlId['XML_ID']]
                );
                return;
            }

            // Проверка по kit_order_id: если уже есть заказ с таким kit_order_id и он не отменён — возвращаем 409 с order_id существующего заказа
            $existingByKitOrderId = OrderPropsValueTable::getList([
                'filter' => [
                    'VALUE' => $kitOrderId,
                    '=ENTITY_TYPE' => Registry::ENTITY_ORDER,
                    '=PROPERTY.CODE' => 'YANDEX_ORDER_ID'
                ],
                'select' => ['ORDER_ID'],
                'limit' => 1
            ])->fetch();

            if (!empty($existingByKitOrderId['ORDER_ID'])) {
                $previousOrder = Order::getList([
                    'filter' => ['ID' => (int)$existingByKitOrderId['ORDER_ID']],
                    'select' => ['ID', 'XML_ID', 'CANCELED'],
                    'limit' => 1
                ])->fetch();

                if ($previousOrder && $previousOrder['CANCELED'] !== 'Y') {
                    $this->sendErrorWithData(
                        'Order already exists',
                        409,
                        self::ERROR_CONFLICT,
                        ['order_id' => (string)$previousOrder['XML_ID']]
                    );
                    return;
                }
                // Если заказ отменён — создаём новый (продолжаем выполнение)
            }

            $conflicts = $this->checkInventoryConflicts($items, $warehouseId);
            if (!empty($conflicts)) {
                $this->sendErrorWithData(
                    'Prices have changed or items are out of stock',
                    409,
                    self::ERROR_INVENTORY_CONFLICT,
                    ['actual_inventory' => $conflicts]
                );
                return;
            }

            // Ищем существующего пользователя: сначала по email, потом по PERSONAL_PHONE
            $userId = $this->findUser($customer);
            
            // Если пользователь не найден, создаем нового
            if (!$userId) {
                $createResult = $this->createUser($customer, $externalOrderId);
                if (!$createResult['success']) {
                    $this->sendError($createResult['error'], 500);
                    return;
                }
                $userId = $createResult['userId'];
            }
            
            // Создаем заказ
            $siteId = Context::getCurrent()->getSite();
            $order = Order::create($siteId, $userId);
            $order->setField('XML_ID', $externalOrderId);
            $order->setField('STORE_ID', $warehouseId);
            
            // Получаем тип плательщика из контекста (по SITE_ID)
            $personTypeId = $this->getPersonTypeIdBySite($siteId);
            if ($personTypeId) {
                $order->setPersonTypeId($personTypeId);
            }

            // Добавляем свойства покупателя и доставки
            $this->setOrderProperties($order, $customer, $delivery, $externalOrderId, $paymentMethod, $kitOrderId);

            // Создаем корзину
            $basket = Basket::create($siteId);
            
            foreach ($items as $item) {
                $productId = ProductIdResolver::resolveToInternalId($item['id']);
                if ($productId === null) {
                    $this->sendError('Product not found: ' . $item['id'], 404, self::ERROR_PRODUCT_NOT_FOUND);
                    return;
                }
                $quantity = isset($item['quantity']) && (string) $item['quantity'] !== '' ? intval($item['quantity']) : 1;
                if ($quantity < 1) {
                    $quantity = 1;
                }
                $price = floatval($item['final_price']);

                // Получаем информацию о товаре
                $product = \Bitrix\Iblock\ElementTable::getById($productId)->fetch();
                if (!$product) {
                    $this->sendError('Product not found: ' . $item['id'], 404, self::ERROR_PRODUCT_NOT_FOUND);
                    return;
                }

                // Добавляем товар в корзину
                $basketItem = $basket->createItem('catalog', $productId);
                $fields = [
                    'QUANTITY' => $quantity,
                    'CURRENCY' => 'RUB',
                    'LID' => $siteId,
                    'CUSTOM_PRICE' => 'Y',
                    'PRICE' => $price,
                    'BASE_PRICE' => floatval($item['price']),
                    'NAME' => $product['NAME']
                ];
                
                // Совместимость с Bitrix 18.5: CatalogProvider может отсутствовать
                if (class_exists('\\Bitrix\\Catalog\\Product\\CatalogProvider')) {
                    $fields['PRODUCT_PROVIDER_CLASS'] = '\\Bitrix\\Catalog\\Product\\CatalogProvider';
                }
                
                $basketItem->setFields($fields);
            }

            $order->setBasket($basket);
            
            $isStoreControl = $this->isStoreControlEnabled();
            
            $deliveryId = Option::get('yastore.checkout', 'YANDEX_KIT_DELIVERY_ID', 0);
            if ($deliveryId) {
                // Получаем стоимость доставки из запроса (может быть price или cost)
                $deliveryPrice = 0;
                if (isset($delivery['price']) && is_numeric($delivery['price'])) {
                    $deliveryPrice = floatval($delivery['price']);
                } elseif (isset($delivery['cost']) && is_numeric($delivery['cost'])) {
                    $deliveryPrice = floatval($delivery['cost']);
                }
                
                $shipmentCollection = $order->getShipmentCollection(); 
                $shipment = $shipmentCollection->createItem(); 
                $shipment->setFields([ 
                    'DELIVERY_ID' => $deliveryId, 
                    'DELIVERY_NAME' => 'Яндекс KIT', 
                    'CUSTOM_PRICE_DELIVERY' => 'Y', 
                    'BASE_PRICE_DELIVERY' => $deliveryPrice, 
                    'PRICE_DELIVERY' => $deliveryPrice,
                    'CURRENCY' => 'RUB', 
                ]); 
                
                // Указываем склад только если складской учёт включен
                if ($isStoreControl) {
                    $shipment->setStoreId($warehouseId);
                }
                
                $shipmentItemCollection = $shipment->getShipmentItemCollection(); 
                foreach ($basket as $basketItem) { 
                    $shipmentItem = $shipmentItemCollection->createItem($basketItem); 
                    
                    // Если складской учёт включен - задаём склад через ShipmentItemStore
                    // Совместимость с Bitrix 18.5: метод может отсутствовать
                    if ($isStoreControl && method_exists($shipmentItem, 'getShipmentItemStoreCollection')) {
                        $shipmentItemStoreCollection = $shipmentItem->getShipmentItemStoreCollection();
                        $storeItem = $shipmentItemStoreCollection->createItem($basketItem);
                        $storeItem->setField('STORE_ID', (int)$warehouseId);
                        $storeItem->setField('QUANTITY', (float)$basketItem->getQuantity());
                    }
                    
                    // Устанавливаем количество позиции отгрузки
                    $shipmentItem->setQuantity($basketItem->getQuantity()); 
                }
            }

            // Устанавливаем служебную платежную систему
            $paySystemId = Option::get('yastore.checkout', 'YANDEX_KIT_PAY_SYSTEM_ID', 0);
            $paySystemId = (int)$paySystemId;
            
            if ($paySystemId > 0) {
                $paymentCollection = $order->getPaymentCollection();
                
                // Получаем информацию о платежной системе (без фильтра по ACTIVE, так как она может быть неактивна)
                $paySystem = \Bitrix\Sale\PaySystem\Manager::getById($paySystemId);
                
                $paySystemName = 'Яндекс KIT';
                if ($paySystem !== false && isset($paySystem['NAME'])) {
                    $paySystemName = $paySystem['NAME'];
                }
                
                // Пробуем получить сервис платежной системы (даже если она неактивна)
                $paySystemService = \Bitrix\Sale\PaySystem\Manager::getObjectById($paySystemId);
                
                // Создаем платеж
                if ($paySystemService) {
                    // Создаем через сервис (правильный способ)
                    $payment = $paymentCollection->createItem($paySystemService);
                } else {
                    // Создаем без сервиса и используем setFieldsNoDemand для обхода валидации
                    $payment = $paymentCollection->createItem();
                    
                    // Совместимость с Bitrix 18.5: метод setFieldsNoDemand может отсутствовать
                    if (method_exists($payment, 'setFieldsNoDemand')) {
                        $payment->setFieldsNoDemand([
                            'PAY_SYSTEM_ID' => $paySystemId,
                            'PAY_SYSTEM_NAME' => $paySystemName
                        ]);
                    } else {
                        // Fallback для старых версий
                        $payment->setFields([
                            'PAY_SYSTEM_ID' => $paySystemId,
                            'PAY_SYSTEM_NAME' => $paySystemName
                        ]);
                    }
                }
                
                // Устанавливаем сумму и валюту после всех расчетов заказа
                $orderPrice = $order->getPrice();
                $payment->setField('SUM', $orderPrice);
                $payment->setField('CURRENCY', $order->getCurrency() ?: 'RUB');
                $payment->setPaid('N');
            } else {
                error_log('YANDEX_KIT_PAY_SYSTEM_ID not set or is 0. Value: ' . var_export($paySystemId, true));
            }
            
            // Сохраняем заказ
            $result = $order->save();
            
            if (!$result->isSuccess()) {
                $errors = $result->getErrorMessages();
                $this->sendError('Failed to create order: ' . implode(', ', $errors), 500);
                return;
            }
            
            // Проверяем warnings и логируем их (не показываем в ответе)
            $warnings = $result->getWarnings();
            if (!empty($warnings)) {
                $warningMessages = [];
                foreach ($warnings as $warning) {
                    $warningMessages[] = $warning->getMessage();
                }
                error_log('Order created with warnings: ' . implode(', ', $warningMessages));
            }
            
            // Создаем или обновляем профиль покупателя
            $this->saveBuyerProfile($order, $customer, $delivery);
            
            // Записываем в историю заказа о создании через API
            if (class_exists('\Bitrix\Sale\OrderHistory')) {
                \Bitrix\Sale\OrderHistory::addAction(
                    'ORDER',
                    $order->getId(),
                    'ORDER_COMMENTED',
                    $order->getId(),
                    $order,
                    ['COMMENTS' => 'Заказ создан через Яндекс KIT API. Внешний ID: ' . $externalOrderId]
                );
            }
            
            // Проверяем и корректируем сумму платежа после сохранения
            if ($paySystemId > 0) {
                $paymentCollection = $order->getPaymentCollection();
                $paymentsSum = 0;
                foreach ($paymentCollection as $payment) {
                    $paymentSystemId = (int)$payment->getField('PAY_SYSTEM_ID');
                    // Учитываем только платежи с PAY_SYSTEM_ID > 0 (не системные)
                    if ($paymentSystemId > 0) {
                        $paymentsSum += $payment->getSum();
                    }
                }
                
                $orderPrice = $order->getPrice();
                if (abs($paymentsSum - $orderPrice) > 0.01) {
                    // Корректируем сумму платежа, если не совпадает
                    foreach ($paymentCollection as $payment) {
                        if ((int)$payment->getField('PAY_SYSTEM_ID') === $paySystemId) {
                            $payment->setField('SUM', $orderPrice);
                            $payment->save();
                            break;
                        }
                    }
                }
            }

            http_response_code(201);
            echo Json::encode([
                'order_id' => $externalOrderId,
                'internal_order_id' => $order->getId(),
                'status' => 'created'
            ]);

        } catch (\Exception $e) {
            $this->sendError('Failed to create order: ' . $e->getMessage(), 500);
        }
    }
    
    private function handleMarkOrderPlaced($orderId = null)
    {
        try {
            // Подключаем необходимые модули
            if (!Loader::includeModule('sale')) {
                $this->sendError('Required modules not available', 500);
                return;
            }

            // Валидация orderId
            if (empty($orderId)) {
                $this->sendError('Order ID is required', 400);
                return;
            }

            // Ищем заказ по XML_ID
            $orderData = Order::getList([
                'filter' => ['XML_ID' => $orderId],
                'select' => ['ID', 'STATUS_ID'],
                'limit' => 1
            ])->fetch();

            if (!$orderData) {
                $this->sendError('Order not found', 404);
                return;
            }

            // Проверяем, не отменен ли заказ
            if ($orderData['STATUS_ID'] === 'C') {
                $this->sendError('Cannot mark as placed - order is already cancelled', 409);
                return;
            }

            // Загружаем заказ
            $order = Order::load($orderData['ID']);
            if (!$order) {
                $this->sendError('Failed to load order', 500);
                return;
            }

            // Получаем метод оплаты: сначала из запроса, затем из свойств заказа
            $paymentMethod = $this->request->get('payment_method');
            if (empty($paymentMethod)) {
                $paymentMethod = $this->getOrderPropertyValue($order, 'PAYMENT_METHOD');
            }
            
            // Если payment_method передан в запросе, обновляем свойство заказа
            if ($this->request->get('payment_method') !== null) {
                $propertyCollection = $order->getPropertyCollection();
                $paymentMethodProperty = null;
                
                // Ищем свойство PAYMENT_METHOD
                foreach ($propertyCollection as $property) {
                    if ($property->getField('CODE') === 'PAYMENT_METHOD') {
                        $paymentMethodProperty = $property;
                        break;
                    }
                }
                
                if ($paymentMethodProperty) {
                    $paymentMethodProperty->setValue($paymentMethod);
                }
            }
            
            // Помечаем заказ как оплаченный только если это не оплата при доставке
            if ($paymentMethod !== 'on_delivery') {
                $paymentCollection = $order->getPaymentCollection();
                $hasPayment = false;
                
                foreach ($paymentCollection as $payment) {
                    $paymentSystemId = (int)$payment->getField('PAY_SYSTEM_ID');
                    // Пропускаем системные платежи (PAY_SYSTEM_ID = 0 или пустой)
                    if ($paymentSystemId <= 0) {
                        continue;
                    }
                    $hasPayment = true;
                    if (!$payment->isPaid()) {
                        $payment->setPaid('Y');
                    }
                }

                // Если платежей нет, создаем платеж
                if (!$hasPayment) {
                    $paySystemId = Option::get('yastore.checkout', 'YANDEX_KIT_PAY_SYSTEM_ID', 0);
                    if ($paySystemId) {
                        $payment = $paymentCollection->createItem();
                        $payment->setFields([
                            'PAY_SYSTEM_ID' => $paySystemId,
                            'PAY_SYSTEM_NAME' => 'Яндекс KIT',
                            'SUM' => $order->getPrice(),
                            'CURRENCY' => 'RUB'
                        ]);
                        $payment->setPaid('Y');
                    }
                }
            }

            // Устанавливаем статус заказа из настроек модуля
            $statusOnPlaced = Option::get('yastore.checkout', 'STATUS_ON_PLACED', 'P');
            if (!empty($statusOnPlaced)) {
                $order->setField('STATUS_ID', $statusOnPlaced);
            }

            // Сохраняем изменения
            $result = $order->save();

            if ($result->isSuccess()) {
                // Определяем, был ли установлен флаг оплаты
                $isPaid = ($paymentMethod !== 'on_delivery');
                
                $this->sendResponse([
                    'order_id' => $orderId,
                    'internal_order_id' => $orderData['ID'],
                    'status' => 'placed',
                    'paid' => $isPaid
                ]);
            } else {
                $errors = $result->getErrorMessages();
                $this->sendError('Failed to update order: ' . implode(', ', $errors), 500);
            }

        } catch (\Exception $e) {
            $this->sendError('Failed to mark order as placed: ' . $e->getMessage(), 500);
        }
    }
    
    private function handleCancelOrder($orderId = null)
    {
        try {
            // Подключаем необходимые модули
            if (!Loader::includeModule('sale')) {
                $this->sendError('Required modules not available', 500);
                return;
            }

            // Валидация orderId
            if (empty($orderId)) {
                $this->sendError('Order ID is required', 400);
                return;
            }

            // Ищем заказ по XML_ID (order_id)
            $orderData = Order::getList([
                'filter' => ['XML_ID' => $orderId],
                'select' => ['ID', 'STATUS_ID', 'CANCELED'],
                'limit' => 1
            ])->fetch();

            if (!$orderData) {
                $this->sendError('Order not found', 404);
                return;
            }

            // Проверяем, не отменен ли уже заказ
            if ($orderData['CANCELED'] === 'Y') {
                // Заказ уже отменен, возвращаем успешный ответ
                $this->sendResponse([
                    'order_id' => $orderId,
                    'internal_order_id' => $orderData['ID'],
                    'status' => 'cancelled',
                    'message' => 'Order is already cancelled'
                ]);
                return;
            }

            // Загружаем заказ
            $order = Order::load($orderData['ID']);
            if (!$order) {
                $this->sendError('Failed to load order', 500);
                return;
            }

            // Устанавливаем признак отмены
            $order->setField('CANCELED', 'Y');
            $order->setField('DATE_CANCELED', new \Bitrix\Main\Type\DateTime());
            
            // Устанавливаем статус заказа из настроек модуля
            $statusOnCancel = Option::get('yastore.checkout', 'STATUS_ON_CANCEL', 'C');
            if (!empty($statusOnCancel)) {
                $order->setField('STATUS_ID', $statusOnCancel);
            }

            // Сохраняем изменения
            $result = $order->save();

            if ($result->isSuccess()) {
                // Записываем в историю заказа об отмене через API
                if (class_exists('\Bitrix\Sale\OrderHistory')) {
                    \Bitrix\Sale\OrderHistory::addAction(
                        'ORDER',
                        $order->getId(),
                        'ORDER_COMMENTED',
                        $order->getId(),
                        $order,
                        ['COMMENTS' => 'Заказ отменен через Яндекс KIT API. Внешний ID: ' . $orderId]
                    );
                }
                
                $this->sendResponse([
                    'order_id' => $orderId,
                    'internal_order_id' => $orderData['ID'],
                    'status' => 'cancelled'
                ]);
            } else {
                $errors = $result->getErrorMessages();
                $this->sendError('Failed to cancel order: ' . implode(', ', $errors), 500);
            }

        } catch (\Exception $e) {
            $this->sendError('Failed to cancel order: ' . $e->getMessage(), 500);
        }
    }
    
    private function handleMarkOrderDelivered($orderId = null)
    {
        try {
            // Подключаем необходимые модули
            if (!Loader::includeModule('sale')) {
                $this->sendError('Required modules not available', 500);
                return;
            }

            // Валидация orderId
            if (empty($orderId)) {
                $this->sendError('Order ID is required', 400);
                return;
            }

            // Получаем данные из тела запроса
            $input = file_get_contents('php://input');
            $requestData = Json::decode($input);

            // Валидация запроса
            if (empty($requestData['purchased_items'])) {
                $this->sendError('Invalid request format: purchased_items is required', 400);
                return;
            }

            // Ищем заказ по XML_ID
            $orderData = Order::getList([
                'filter' => ['XML_ID' => $orderId],
                'select' => ['ID', 'STATUS_ID', 'CANCELED'],
                'limit' => 1
            ])->fetch();

            if (!$orderData) {
                $this->sendError('Order not found', 404);
                return;
            }

            // Проверяем, не отменен ли заказ
            if ($orderData['CANCELED'] === 'Y') {
                $this->sendError('Cannot mark as delivered - order is cancelled', 409);
                return;
            }

            // Загружаем заказ
            $order = Order::load($orderData['ID']);
            if (!$order) {
                $this->sendError('Failed to load order', 500);
                return;
            }

            // Проверяем, не отгружен ли уже заказ
            $shipmentCollection = $order->getShipmentCollection();
            foreach ($shipmentCollection as $shipment) {
                if (!$shipment->isSystem() && $shipment->getField('DEDUCTED') === 'Y') {
                    //$this->sendError('Order already delivered', 409);
                    $this->sendResponse([
                        'order_id' => $orderId,
                        'internal_order_id' => $orderData['ID'],
                        'status' => 'delivered',
                        'message' => 'Order already delivered'
                    ]);
                    return;
                }
            }

            // Создаем карту товаров из запроса (внутренний ID => quantity)
            $purchasedItemsMap = [];
            foreach ($requestData['purchased_items'] as $purchasedItem) {
                $internalId = ProductIdResolver::resolveToInternalId($purchasedItem['id']);
                if ($internalId !== null) {
                    $q = isset($purchasedItem['quantity']) && (string) $purchasedItem['quantity'] !== '' ? intval($purchasedItem['quantity']) : 1;
                    $purchasedItemsMap[$internalId] = $q > 0 ? $q : 1;
                }
            }

            // При запросе delivered товар уже отгружен - проверка остатков не требуется

            // Обновляем корзину заказа - приводим к товарам из payload
            // Резервирование снимается автоматически при изменении корзины
            $basket = $order->getBasket();
            
            // Проходим по существующим товарам
            foreach ($basket as $basketItem) {
                $productId = $basketItem->getProductId();
                $currentQuantity = $basketItem->getQuantity();
                $newQuantity = $purchasedItemsMap[$productId] ?? 0;
                
                if ($newQuantity == 0) {
                    // Товар отсутствует в новом списке - удаляем
                    $basketItem->delete();
                } elseif ($newQuantity != $currentQuantity) {
                    // Количество изменилось - обновляем
                    $basketItem->setField('QUANTITY', $newQuantity);
                }
                
                // Удаляем из карты, чтобы знать какие товары нужно добавить
                unset($purchasedItemsMap[$productId]);
            }

            // Добавляем новые товары, которых не было в заказе
            foreach ($purchasedItemsMap as $productId => $quantity) {
                if ($quantity > 0) {
                    // Проверяем, не существует ли уже товар в корзине
                    $existingItem = null;
                    foreach ($basket as $item) {
                        if ($item->getProductId() == $productId) {
                            $existingItem = $item;
                            break;
                        }
                    }
                    
                    // Если товар уже есть - обновляем количество
                    if ($existingItem) {
                        $existingItem->setField('QUANTITY', $quantity);
                        continue;
                    }
                    
                    // Получаем информацию о товаре
                    $product = \Bitrix\Iblock\ElementTable::getById($productId)->fetch();
                    if (!$product) {
                        continue; // Товар не найден
                    }
                    
                    // Получаем цену товара
                    \Bitrix\Main\Loader::includeModule('catalog');
                    $optimalPrice = \CCatalogProduct::GetOptimalPrice(
                        $productId,
                        1, // Используем 1 для получения цены
                        [],
                        'N',
                        [],
                        \Bitrix\Main\Context::getCurrent()->getSite()
                    );
                    
                    $price = 0;
                    if ($optimalPrice && isset($optimalPrice['RESULT_PRICE'])) {
                        $price = floatval($optimalPrice['RESULT_PRICE']['DISCOUNT_PRICE']);
                    }
                    
                    // Создаем товар в корзине
                    $basketItem = $basket->createItem('catalog', $productId);
                    $fields = [
                        'QUANTITY' => $quantity,
                        'CURRENCY' => 'RUB',
                        'LID' => \Bitrix\Main\Context::getCurrent()->getSite(),
                        'CUSTOM_PRICE' => 'Y',
                        'PRICE' => $price,
                        'BASE_PRICE' => $price,
                        'NAME' => $product['NAME']
                    ];
                    
                    // Совместимость с Bitrix 18.5: CatalogProvider может отсутствовать
                    if (class_exists('\\Bitrix\\Catalog\\Product\\CatalogProvider')) {
                        $fields['PRODUCT_PROVIDER_CLASS'] = '\\Bitrix\\Catalog\\Product\\CatalogProvider';
                    }
                    
                    $setFieldsResult = $basketItem->setFields($fields);
                    
                    // Если не удалось добавить товар - удаляем его из корзины
                    if (!$setFieldsResult->isSuccess()) {
                        $basketItem->delete();
                    }
                }
            }

            // Получаем отгрузки и склад заказа (для складского учёта при списании)
            $shipmentCollection = $order->getShipmentCollection();
            $warehouseId = (int)$order->getField('STORE_ID');
            if ($warehouseId <= 0) {
                $warehouseId = (int)$this->getOrderPropertyValue($order, 'STORE_ID');
            }
            $isStoreControl = $this->isStoreControlEnabled();

            // Отмечаем отгрузки как доставленные
            foreach ($shipmentCollection as $shipment) {
                if ($shipment->isSystem()) {
                    continue;
                }

                if ($isStoreControl && $warehouseId > 0) {
                    $shipment->setStoreId($warehouseId);
                }

                // Устанавливаем признак отгрузки
                $shipment->setField('DEDUCTED', 'Y');
                
                // Устанавливаем дату отгрузки
                if (!empty($requestData['delivered_at'])) {
                    try {
                        $deliveredDate = new \Bitrix\Main\Type\DateTime($requestData['delivered_at']);
                        $shipment->setField('DATE_DEDUCTED', $deliveredDate);
                    } catch (\Exception $e) {
                        // Если не удалось распарсить дату, используем текущую
                        $shipment->setField('DATE_DEDUCTED', new \Bitrix\Main\Type\DateTime());
                    }
                } else {
                    $shipment->setField('DATE_DEDUCTED', new \Bitrix\Main\Type\DateTime());
                }
                
                // Устанавливаем признак доставки
                $shipment->setField('STATUS_ID', 'DF'); // Доставлен

                // Синхронизируем отгрузку с обновленной корзиной
                $shipmentItemCollection = $shipment->getShipmentItemCollection();
                
                // Удаляем все текущие позиции из отгрузки
                foreach ($shipmentItemCollection as $shipmentItem) {
                    if ($shipmentItem->getBasketCode() !== null) {
                        $shipmentItem->delete();
                    }
                }
                
                // Добавляем актуальные позиции из корзины (только не удаленные)
                foreach ($basket as $basketItem) {
                    // Пропускаем товары без ID (новые несохраненные) или с нулевым количеством
                    if ($basketItem->getField('ID') && $basketItem->getQuantity() > 0) {
                        $shipmentItem = $shipmentItemCollection->createItem($basketItem);
                        if ($shipmentItem) {
                            $shipmentItem->setQuantity($basketItem->getQuantity());
                            // При включённом складском учёте задаём склад для списания (как при создании заказа)
                            if ($isStoreControl && $warehouseId > 0 && method_exists($shipmentItem, 'getShipmentItemStoreCollection')) {
                                $shipmentItemStoreCollection = $shipmentItem->getShipmentItemStoreCollection();
                                $storeItem = $shipmentItemStoreCollection->createItem($basketItem);
                                $storeItem->setField('STORE_ID', $warehouseId);
                                $storeItem->setField('QUANTITY', (float)$basketItem->getQuantity());
                            }
                        }
                    }
                }
            }

            // Устанавливаем статус заказа из настроек модуля
            $statusOnDelivered = Option::get('yastore.checkout', 'STATUS_ON_DELIVERED', 'F');
            if (!empty($statusOnDelivered)) {
                $order->setField('STATUS_ID', $statusOnDelivered);
            }

            // Всегда помечаем заказ как оплаченный при доставке
            $paymentCollection = $order->getPaymentCollection();
            $hasPayment = false;
            
            foreach ($paymentCollection as $payment) {
                $paymentSystemId = (int)$payment->getField('PAY_SYSTEM_ID');
                // Пропускаем системные платежи (PAY_SYSTEM_ID = 0 или пустой)
                if ($paymentSystemId <= 0) {
                    continue;
                }
                $hasPayment = true;
                if (!$payment->isPaid()) {
                    $payment->setPaid('Y');
                }
            }

            // Если платежей нет, создаем платеж
            if (!$hasPayment) {
                $paySystemId = Option::get('yastore.checkout', 'YANDEX_KIT_PAY_SYSTEM_ID', 0);
                if ($paySystemId) {
                    $payment = $paymentCollection->createItem();
                    $payment->setFields([
                        'PAY_SYSTEM_ID' => $paySystemId,
                        'PAY_SYSTEM_NAME' => 'Яндекс KIT',
                        'SUM' => $order->getPrice(),
                        'CURRENCY' => 'RUB'
                    ]);
                    $payment->setPaid('Y');
                }
            }

            // Комментарий к заказу больше не используется, данные в свойствах заказа

            // Сохраняем изменения
            $result = $order->save();

            if ($result->isSuccess()) {
                // Записываем в историю заказа об изменении статуса на "доставлен" через API
                if (class_exists('\Bitrix\Sale\OrderHistory')) {
                    \Bitrix\Sale\OrderHistory::addAction(
                        'ORDER',
                        $order->getId(),
                        'ORDER_COMMENTED',
                        $order->getId(),
                        $order,
                        ['COMMENTS' => 'Заказ отмечен как доставленный через Яндекс KIT API. Внешний ID: ' . $orderId]
                    );
                }
                
                $this->sendResponse([
                    'order_id' => $orderId,
                    'internal_order_id' => $orderData['ID'],
                    'status' => 'delivered'
                ]);
            } else {
                $errors = $result->getErrorMessages();
                $this->sendError('Failed to mark order as delivered: ' . implode(', ', $errors), 500);
            }

        } catch (\Exception $e) {
            $this->sendError('Failed to mark order as delivered: ' . $e->getMessage(), 500);
        }
    }



    /**
     * Проверить конфликты наличия и цен товаров.
     * Количественный учёт (quantity_trace) — проверять ли наличие по количеству.
     * Складской учёт (store_control) — откуда брать остаток: общий (ProductTable) или по складу (StoreProductTable).
     */
    private function checkInventoryConflicts($items, $warehouseId)
    {
        $conflicts = [];
        $quantityTraceEnabled = Option::get('catalog', 'default_quantity_trace', 'N') === 'Y';
        $isStoreControl = Configuration::useStoreControl();

        // Складской учёт выключен — остаток берём общий (ProductTable.QUANTITY)
        if (!$isStoreControl) {
            foreach ($items as $item) {
                $productId = ProductIdResolver::resolveToInternalId($item['id']);
                if ($productId === null) {
                    continue;
                }
                $requestedQuantity = isset($item['quantity']) && (string) $item['quantity'] !== '' ? intval($item['quantity']) : 1;
                if ($requestedQuantity < 1) {
                    $requestedQuantity = 1;
                }
                $requestedRegularPrice = floatval($item['price']);
                $requestedFinalPrice = floatval($item['final_price']);

                // Проверяем общее количество товара (без вычета резервов, как в checkBasket)
                $product = ProductTable::getList([
                    'filter' => ['ID' => $productId],
                    'select' => ['ID', 'QUANTITY']
                ])->fetch();

                $totalQuantity = $product ? (float)$product['QUANTITY'] : 0;
                // Не вычитаем резервы - показываем общее количество
                $availableQuantity = $totalQuantity;

                // Получаем актуальные цены через GetOptimalPrice
                \Bitrix\Main\Loader::includeModule('catalog');
                $optimalPrice = \CCatalogProduct::GetOptimalPrice(
                    $productId,
                    1,
                    [],
                    'N',
                    [],
                    \Bitrix\Main\Context::getCurrent()->getSite()
                );

                $currentRegularPrice = 0;
                $currentFinalPrice = 0;

                if ($optimalPrice && isset($optimalPrice['RESULT_PRICE'])) {
                    $currentRegularPrice = floatval($optimalPrice['RESULT_PRICE']['BASE_PRICE']);
                    $currentFinalPrice = floatval($optimalPrice['RESULT_PRICE']['DISCOUNT_PRICE']);
                }

                // Проверяем конфликты: по количеству — только при включённом количественном учёте
                $hasConflict = false;
                $sellWithoutStockCheck = Option::get('yastore.checkout', 'SELL_WITHOUT_STOCK_CHECK', 'N') === 'Y';
                if ($quantityTraceEnabled && !$sellWithoutStockCheck && $availableQuantity < $requestedQuantity) {
                    $hasConflict = true;
                }
                
                // Сравниваем цены (ceil для округления перед сравнением)
                if (ceil($currentRegularPrice) !== ceil($requestedRegularPrice)) {
                    $hasConflict = true;
                }
                
                if (ceil($currentFinalPrice) !== ceil($requestedFinalPrice)) {
                    $hasConflict = true;
                }

                if ($hasConflict) {
                    // Проверяем наличие складов и остатков по складам
                    $hasWarehouses = $this->hasWarehouses();
                    $hasStock = $this->hasWarehouseStock($productId);
                    
                    // Если общий остаток > 0, но складов нет или остатков по складам нет - используем виртуальный склад
                    if ($availableQuantity > 0 && (!$hasWarehouses || !$hasStock)) {
                        $virtualWarehouse = $this->getVirtualWarehouse();
                        $warehouseIdForResponse = $virtualWarehouse['id'];
                    } else {
                        // Получаем первый активный склад
                        $firstWarehouse = StoreTable::getList([
                            'filter' => ['ACTIVE' => 'Y'],
                            'select' => ['ID', 'XML_ID'],
                            'order' => ['SORT' => 'ASC', 'ID' => 'ASC'],
                            'limit' => 1
                        ])->fetch();
                        
                        $warehouseIdForResponse = '1'; // По умолчанию виртуальный склад
                        if ($firstWarehouse) {
                            $warehouseIdForResponse = (string)$firstWarehouse['ID'];
                        }
                    }
                    
                    $conflicts[] = [
                        'id' => ProductIdResolver::getExternalId($productId),
                        'warehouses' => [
                            [
                                'id' => $warehouseIdForResponse,
                                'available_quantity' => $availableQuantity,
                                'regular_price' => $currentRegularPrice,
                                'final_price' => $currentFinalPrice
                            ]
                        ]
                    ];
                }
            }

            return $conflicts ? ['items' => $conflicts] : [];
        }

        // Складской учёт включен - работаем со складами
        // Находим склад по XML_ID или ID
        $store = \Bitrix\Catalog\StoreTable::getList([
            'filter' => [
                //'LOGIC' => 'OR',
                //['XML_ID' => $warehouseId],
                'ID' => $warehouseId
            ],
            'select' => ['ID', 'XML_ID'],
            'limit' => 1
        ])->fetch();

        if (!$store) {
            // Если склад не найден - проверяем каждый товар на наличие общего остатка
            foreach ($items as $item) {
                $productId = ProductIdResolver::resolveToInternalId($item['id']);
                if ($productId === null) {
                    continue;
                }
                // Получаем общий остаток товара
                $product = ProductTable::getList([
                    'filter' => ['ID' => $productId],
                    'select' => ['ID', 'QUANTITY', 'QUANTITY_RESERVED']
                ])->fetch();

                $totalQuantity = $product ? (float)$product['QUANTITY'] : 0;
                // Не вычитаем резервы - показываем общее количество (как в checkBasket)
                $availableQuantity = $totalQuantity;
                
                // Проверяем наличие складов и остатков по складам
                $hasWarehouses = $this->hasWarehouses();
                $hasStock = $this->hasWarehouseStock($productId);
                
                // Если общий остаток > 0, но складов нет или остатков по складам нет - используем виртуальный склад
                if ($availableQuantity > 0 && (!$hasWarehouses || !$hasStock)) {
                    $virtualWarehouse = $this->getVirtualWarehouse();
                    $conflicts[] = [
                        'id' => ProductIdResolver::getExternalId($productId),
                        'warehouses' => [
                            [
                                'id' => $virtualWarehouse['id'],
                                'available_quantity' => $availableQuantity,
                                'regular_price' => 0,
                                'final_price' => 0
                            ]
                        ]
                    ];
                } else {
                    $conflicts[] = [
                        'id' => ProductIdResolver::getExternalId($productId),
                        'warehouses' => []
                    ];
                }
            }
            return ['items' => $conflicts];
        }

        $storeId = $store['ID'];
        $storeXmlId = (string) $store['ID'];

        foreach ($items as $item) {
            $productId = ProductIdResolver::resolveToInternalId($item['id']);
            if ($productId === null) {
                continue;
            }
            $requestedQuantity = isset($item['quantity']) && (string) $item['quantity'] !== '' ? intval($item['quantity']) : 1;
            if ($requestedQuantity < 1) {
                $requestedQuantity = 1;
            }
            $requestedRegularPrice = floatval($item['price']);
            $requestedFinalPrice = floatval($item['final_price']);

            // Получаем общий остаток товара
            $product = ProductTable::getList([
                'filter' => ['ID' => $productId],
                'select' => ['ID', 'QUANTITY', 'QUANTITY_RESERVED']
            ])->fetch();

            $totalQuantity = $product ? (float)$product['QUANTITY'] : 0;
            // Не вычитаем резервы - показываем общее количество (как в checkBasket)
            $totalAvailableQuantity = $totalQuantity;

            // Проверяем наличие складов в системе и остатков по складам (как в checkBasket)
            $hasWarehouses = $this->hasWarehouses();
            $hasStock = $this->hasWarehouseStock($productId);
            
            // Если общий остаток > 0, но складов нет или остатков по складам нет - используем виртуальный склад
            if ($totalAvailableQuantity > 0 && (!$hasWarehouses || !$hasStock)) {
                $availableQuantity = $totalAvailableQuantity;
                $virtualWarehouse = $this->getVirtualWarehouse();
                $storeXmlId = $virtualWarehouse['id'];
            } else {
                // Проверяем наличие на складе (используем внутренний ID склада)
                $storeProduct = StoreProductTable::getList([
                    'filter' => [
                        'PRODUCT_ID' => $productId,
                        'STORE_ID' => $storeId
                    ],
                    'select' => ['AMOUNT']
                ])->fetch();

                $amount = $storeProduct ? (int)$storeProduct['AMOUNT'] : 0;
                // Не вычитаем резервы - показываем общее количество
                $availableQuantity = $amount;
            }

            // Получаем актуальные цены через GetOptimalPrice (аналогично checkBasket)
            \Bitrix\Main\Loader::includeModule('catalog');
            $optimalPrice = \CCatalogProduct::GetOptimalPrice(
                $productId,
                1,
                [],
                'N',
                [],
                \Bitrix\Main\Context::getCurrent()->getSite()
            );

            $currentRegularPrice = 0;
            $currentFinalPrice = 0;

            if ($optimalPrice && isset($optimalPrice['RESULT_PRICE'])) {
                $currentRegularPrice = floatval($optimalPrice['RESULT_PRICE']['BASE_PRICE']);
                $currentFinalPrice = floatval($optimalPrice['RESULT_PRICE']['DISCOUNT_PRICE']);
            }

            // Проверяем конфликты: по количеству — только при включённом количественном учёте
            $hasConflict = false;
            $sellWithoutStockCheck = Option::get('yastore.checkout', 'SELL_WITHOUT_STOCK_CHECK', 'N') === 'Y';
            if ($quantityTraceEnabled && !$sellWithoutStockCheck && $availableQuantity < $requestedQuantity) {
                $hasConflict = true;
            }
            
            // Сравниваем цены (ceil для округления перед сравнением)
            if (ceil($currentRegularPrice) !== ceil($requestedRegularPrice)) {
                $hasConflict = true;
            }
            
            if (ceil($currentFinalPrice) !== ceil($requestedFinalPrice)) {
                $hasConflict = true;
            }

            if ($hasConflict) {
                $conflicts[] = [
                    'id' => ProductIdResolver::getExternalId($productId),
                    'warehouses' => [
                        [
                            'id' => $storeXmlId,
                            'available_quantity' => $availableQuantity,
                            'regular_price' => $currentRegularPrice,
                            'final_price' => $currentFinalPrice
                        ]
                    ]
                ];
            }
        }

        return $conflicts ? ['items' => $conflicts] : [];
    }

    /**
     * Установить свойства заказа (покупатель и доставка)
     */
    private function setOrderProperties($order, $customer, $delivery, $externalOrderId = null, $paymentMethod = null, $kitOrderId = null)
    {
        $propertyCollection = $order->getPropertyCollection();
        $properties = $propertyCollection->getArray();
        
        $address = $delivery['address'] ?? [];
        $isPickup = ($delivery['type'] === 'pickup_point');

        // Маппинг данных на свойства заказа
        $propertyMapping = [
            // Внешний ID заказа
            'EXTERNAL_ORDER_ID' => $externalOrderId ?? '',
            'ORDER_ID' => $externalOrderId ?? '',
            'YANDEX_ORDER_ID' => $kitOrderId ?? '',
            
            // Данные покупателя
            'FIO' => $customer['full_name'] ?? '',
            'NAME' => $customer['full_name'] ?? '',
            'PHONE' => $customer['phone'] ?? '',
            'EMAIL' => $customer['email'] ?? '',
            
            // Данные доставки
            'DELIVERY_TYPE' => $delivery['service_display_name'] ?? '',
            'DELIVERY_SERVICE' => strtoupper($delivery['service'] ?? ''),
            
            // Тип оплаты
            'PAYMENT_METHOD' => $paymentMethod ?? '',
            
            // Адрес доставки / ПВЗ
            'ADDRESS' => $address['address'] ?? '',
            'CITY' => $address['city'] ?? '',
            'STREET' => $address['street'] ?? '',
            'BUILDING' => $address['building'] ?? '',
            'APARTMENT' => $address['apartment'] ?? '',
            'ENTRANCE' => $address['entrance'] ?? '',
            'FLOOR' => $address['floor'] ?? '',
            'INTERCOM' => $address['intercom'] ?? '',
            'PICKUP_POINT_ID' => $address['pickup_point_id'] ?? '',
            'ZIP' => $address['zip'] ?? '',
        ];

        // Устанавливаем значения свойств через CODE
        foreach ($propertyMapping as $code => $value) {
            if (empty($value)) {
                continue; // Пропускаем пустые значения
            }
            
            try {
                // Совместимость с Bitrix 18.5: метод getItemByOrderPropertyCode может отсутствовать
                if (method_exists($propertyCollection, 'getItemByOrderPropertyCode')) {
                    // Новая версия - используем метод по коду
                    $propItem = $propertyCollection->getItemByOrderPropertyCode($code);
                    if ($propItem) {
                        $propItem->setValue($value);
                    }
                } else {
                    // Старая версия - ищем через массив свойств
                    $properties = $propertyCollection->getArray();
                    if (isset($properties['properties'])) {
                        foreach ($properties['properties'] as $property) {
                            if (isset($property['CODE']) && $property['CODE'] === $code) {
                                $propItem = $propertyCollection->getItemByOrderPropertyId($property['ID']);
                                if ($propItem) {
                                    $propItem->setValue($value);
                                }
                                break;
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                // Игнорируем ошибки для несуществующих свойств
                continue;
            }
        }
    }

    /**
     * Получить значение свойства заказа по коду
     * 
     * @param Order $order Заказ
     * @param string $code Код свойства
     * @return string|null Значение свойства или null
     */
    private function getOrderPropertyValue($order, $code)
    {
        try {
            $propertyCollection = $order->getPropertyCollection();
            
            // Совместимость с Bitrix 18.5: метод getItemByOrderPropertyCode может отсутствовать
            if (method_exists($propertyCollection, 'getItemByOrderPropertyCode')) {
                // Новая версия - используем метод по коду
                $propItem = $propertyCollection->getItemByOrderPropertyCode($code);
                if ($propItem) {
                    $value = $propItem->getValue();
                    return !empty($value) ? $value : null;
                }
            } else {
                // Старая версия - ищем через массив свойств
                foreach ($propertyCollection as $property) {
                    $propertyCode = $property->getField('CODE');
                    if ($propertyCode === $code) {
                        $value = $property->getValue();
                        return !empty($value) ? $value : null;
                    }
                }
            }
        } catch (\Exception $e) {
            // Игнорируем ошибки
        }
        
        return null;
    }

    /**
     * Зарезервировать товары на складе
     */
    private function reserveProducts($orderId, $items, $warehouseId)
    {
        // Получаем заказ
        $order = Order::load($orderId);
        if (!$order) {
            return;
        }

        // Получаем корзину заказа
        $basket = $order->getBasket();
        if (!$basket) {
            return;
        }

        // Резервируем каждый товар
        foreach ($basket as $basketItem) {
            $productId = $basketItem->getProductId();
            $quantity = $basketItem->getQuantity();

            // Используем \CCatalogStoreBarCode для резервирования
            // Или просто помечаем в системе, что товары зарезервированы
            // В Bitrix это происходит автоматически при определенных статусах заказа
        }

        // Сохраняем информацию о складе в свойствах заказа
        $propertyCollection = $order->getPropertyCollection();
        $properties = $propertyCollection->getArray();

        foreach ($properties['properties'] as $property) {
            if ($property['CODE'] === 'STORE_ID' || strpos(strtolower($property['CODE']), 'warehouse') !== false) {
                $prop = $propertyCollection->getItemByOrderPropertyId($property['ID']);
                if ($prop) {
                    $prop->setValue($warehouseId);
                    break;
                }
            }
        }

        $order->save();
    }

    /**
     * Ищет пользователя по данным покупателя
     * Поиск выполняется в следующем порядке:
     * 1. По email
     * 2. По телефону через UserPhoneAuthTable
     * 3. По PERSONAL_PHONE (резервный вариант)
     * 
     * @param array $customer Данные покупателя
     * @return int|false ID пользователя или false, если не найден
     */
    private function findUser($customer)
    {
        // Подключаем модуль main для работы с пользователями
        if (!Loader::includeModule('main')) {
            return false;
        }

        // 1. Ищем по email
        if (isset($customer['email']) && !empty($customer['email'])) {
            // Совместимость с Bitrix 18.5: параметры должны быть переменными, а не литералами
            $by = 'ID';
            $order = 'ASC';
            $arFilter = ['=EMAIL' => $customer['email']];
            $arSelect = ['FIELDS' => ['ID']];
            $res = \CUser::GetList($by, $order, $arFilter, $arSelect);

            if ($user = $res->Fetch()) {
                return intval($user['ID']);
            }
        }

        // 2. Ищем по телефону через UserPhoneAuthTable
        if (isset($customer['phone']) && !empty($customer['phone'])) {
            // Нормализуем телефон через Bitrix API
            $phone = \Bitrix\Main\UserPhoneAuthTable::normalizePhoneNumber($customer['phone']);
            
            if (!empty($phone)) {
                // Ищем через UserPhoneAuthTable
                $phoneAuthTable = \Bitrix\Main\UserPhoneAuthTable::getList([
                    'filter' => ['PHONE_NUMBER' => $phone],
                    'select' => ['USER_ID'],
                    'limit' => 1
                ]);
                
                if ($item = $phoneAuthTable->fetch()) {
                    return intval($item['USER_ID']);
                }
            }
        }

        // 3. Ищем по PERSONAL_PHONE (резервный вариант)
        if (isset($customer['phone']) && !empty($customer['phone'])) {
            // Нормализуем телефон через Bitrix API
            $phone = \Bitrix\Main\UserPhoneAuthTable::normalizePhoneNumber($customer['phone']);
            
            if (!empty($phone)) {
                // Ищем по нормализованному телефону
                // Совместимость с Bitrix 18.5: параметры должны быть переменными, а не литералами
                $by = 'ID';
                $order = 'ASC';
                $arFilter = ['PERSONAL_PHONE' => $phone];
                $arSelect = ['FIELDS' => ['ID']];
                $res = \CUser::GetList($by, $order, $arFilter, $arSelect);

                if ($user = $res->Fetch()) {
                    return intval($user['ID']);
                }
                
                // Также пробуем найти без + в начале (на случай, если в БД хранится без +)
                $phoneWithoutPlus = ltrim($phone, '+');
                if ($phoneWithoutPlus !== $phone) {
                    // Совместимость с Bitrix 18.5: параметры должны быть переменными, а не литералами
                    $by = 'ID';
                    $order = 'ASC';
                    $arFilter = ['PERSONAL_PHONE' => $phoneWithoutPlus];
                    $arSelect = ['FIELDS' => ['ID']];
                    $res = \CUser::GetList($by, $order, $arFilter, $arSelect);

                    if ($user = $res->Fetch()) {
                        return intval($user['ID']);
                    }
                }
            }
        }

        return false;
    }


    /**
     * Получает тип плательщика для сайта из контекста
     * 
     * @param string $siteId ID сайта
     * @return int|null ID типа плательщика или null, если не найден
     */
    private function getPersonTypeIdBySite($siteId)
    {
        if (empty($siteId)) {
            return null;
        }

        if (!Loader::includeModule('sale')) {
            return null;
        }

        // Получаем первый активный тип плательщика для сайта, отсортированный по SORT
        $personType = \Bitrix\Sale\PersonType::getList([
            'filter' => [
                '=ACTIVE' => 'Y',
                '=PERSON_TYPE_SITE.SITE_ID' => $siteId,
                '=ENTITY_REGISTRY_TYPE' => \Bitrix\Sale\Registry::REGISTRY_TYPE_ORDER,
            ],
            'order' => [
                'SORT' => 'ASC',
                'ID' => 'ASC'
            ],
            'limit' => 1,
            'select' => ['ID']
        ])->fetch();

        return $personType ? intval($personType['ID']) : null;
    }

    /**
     * Создает нового пользователя в Битрикс
     * 
     * @param array $customer Данные покупателя
     * @param string $externalOrderId Внешний ID заказа
     * @return array Массив с результатом: ['success' => bool, 'userId' => int|null, 'error' => string|null]
     */
    private function createUser($customer, $externalOrderId)
    {
        global $USER;
        
        // Подключаем модуль main для работы с пользователями
        if (!Loader::includeModule('main')) {
            return ['success' => false, 'userId' => null, 'error' => 'Main module not available'];
        }

        $cUser = new \CUser();
        
        // Генерируем уникальный логин на основе внешнего ID заказа
        $login = 'yastore_' . $externalOrderId . '_' . time();
        
        // Генерируем случайный пароль
        $password = $this->generatePassword();
        
        // Извлекаем имя и фамилию из полного имени
        $fullName = isset($customer['full_name']) ? $customer['full_name'] : '';
        $nameParts = explode(' ', $fullName, 2);
        $firstName = isset($nameParts[0]) ? $nameParts[0] : 'Покупатель';
        $lastName = isset($nameParts[1]) ? $nameParts[1] : '';
        
        // Подготавливаем данные для создания пользователя
        $arFields = [
            'LOGIN' => $login,
            'EMAIL' => isset($customer['email']) && !empty($customer['email']) ? $customer['email'] : $login,
            'NAME' => $firstName,
            'LAST_NAME' => $lastName,
            'PASSWORD' => $password,
            'CONFIRM_PASSWORD' => $password,
            'ACTIVE' => 'Y',
            'PERSONAL_PHONE' => isset($customer['phone']) && !empty($customer['phone']) ? \Bitrix\Main\UserPhoneAuthTable::normalizePhoneNumber($customer['phone']) : '',
            'PHONE_NUMBER' => isset($customer['phone']) && !empty($customer['phone']) ? \Bitrix\Main\UserPhoneAuthTable::normalizePhoneNumber($customer['phone']) : '',
            'XML_ID' => 'yastore_' . $externalOrderId, // Связываем с внешним заказом
        ];

        // Создаем пользователя
        $userId = $cUser->Add($arFields);
        
        if (!$userId) {
            // Получаем текст ошибки
            $error = $cUser->LAST_ERROR;
            return ['success' => false, 'userId' => null, 'error' => $error ?: 'Failed to create user'];
        }

        return ['success' => true, 'userId' => intval($userId), 'error' => null];
    }

    /**
     * Генерирует случайный пароль
     * 
     * @param int $length Длина пароля
     * @return string Сгенерированный пароль
     */
    private function generatePassword($length = 12)
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
        $password = '';
        $charsLength = strlen($chars);
        
        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, $charsLength - 1)];
        }
        
        return $password;
    }

    /**
     * Создает или обновляет профиль покупателя
     * 
     * @param Order $order Заказ
     * @param array $customer Данные покупателя
     * @param array $delivery Данные доставки
     * @return void
     */
    private function saveBuyerProfile($order, $customer, $delivery)
    {
        try {
            if (!Loader::includeModule('sale')) {
                error_log('[saveBuyerProfile] Module sale not loaded');
                return;
            }

            $userId = $order->getUserId();
            $personTypeId = $order->getPersonTypeId();

            error_log('[saveBuyerProfile] Start: userId=' . $userId . ', personTypeId=' . $personTypeId);

            if (!$userId || !$personTypeId) {
                error_log('[saveBuyerProfile] Missing userId or personTypeId');
                return;
            }

            // Ищем существующий профиль покупателя
            $existingProfileId = $this->findBuyerProfile($userId, $personTypeId);
            error_log('[saveBuyerProfile] Existing profile ID: ' . $existingProfileId);
            
            // Формируем имя профиля
            $profileName = $this->generateProfileName($customer, $delivery);
            error_log('[saveBuyerProfile] Profile name: ' . $profileName);
            
            // Получаем свойства заказа в формате [PROPERTY_ID => VALUE]
            $orderProps = $this->getOrderPropertiesForProfile($order, $personTypeId);
            error_log('[saveBuyerProfile] Order properties count: ' . count($orderProps));
            error_log('[saveBuyerProfile] Order properties: ' . json_encode($orderProps, JSON_UNESCAPED_UNICODE));
            
            if (empty($orderProps)) {
                error_log('[saveBuyerProfile] No properties to save (empty orderProps)');
                return; // Нет свойств для сохранения
            }

            // Сохраняем профиль
            $errors = [];
            $profileId = \CSaleOrderUserProps::DoSaveUserProfile(
                $userId,
                $existingProfileId,
                $profileName,
                $personTypeId,
                $orderProps,
                $errors
            );

            error_log('[saveBuyerProfile] DoSaveUserProfile result: ' . ($profileId !== false ? $profileId : 'false'));

            if ($profileId === false && !empty($errors)) {
                // Логируем ошибки, но не прерываем выполнение
                error_log('[saveBuyerProfile] Failed to save buyer profile: ' . json_encode($errors, JSON_UNESCAPED_UNICODE));
            } elseif ($profileId !== false) {
                error_log('[saveBuyerProfile] Profile saved successfully with ID: ' . $profileId);
            }

        } catch (\Exception $e) {
            // Логируем ошибку, но не прерываем выполнение
            error_log('[saveBuyerProfile] Exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        }
    }

    /**
     * Ищет существующий профиль покупателя
     * 
     * @param int $userId ID пользователя
     * @param int $personTypeId ID типа плательщика
     * @return int ID профиля или 0, если не найден
     */
    private function findBuyerProfile($userId, $personTypeId)
    {
        try {
            if (!Loader::includeModule('sale')) {
                return 0;
            }

            // Ищем профиль через API Битрикс
            $profile = \CSaleOrderUserProps::GetList(
                ['DATE_UPDATE' => 'DESC'],
                [
                    'USER_ID' => $userId,
                    'PERSON_TYPE_ID' => $personTypeId
                ],
                false,
                false,
                ['ID']
            )->Fetch();

            return $profile ? (int)$profile['ID'] : 0;

        } catch (\Exception $e) {
            error_log('Error finding buyer profile: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Генерирует имя профиля на основе данных покупателя и доставки
     * 
     * @param array $customer Данные покупателя
     * @param array $delivery Данные доставки
     * @return string Имя профиля
     */
    private function generateProfileName($customer, $delivery)
    {
        $nameParts = [];
        
        // Добавляем имя покупателя
        if (!empty($customer['full_name'])) {
            $nameParts[] = $customer['full_name'];
        }
        
        // Добавляем адрес или ПВЗ
        $address = $delivery['address'] ?? [];
        if (!empty($address['address'])) {
            $nameParts[] = $address['address'];
        } elseif (!empty($address['pickup_point_id'])) {
            $nameParts[] = 'ПВЗ ' . $address['pickup_point_id'];
        }
        
        // Если ничего не найдено, используем дату
        if (empty($nameParts)) {
            $nameParts[] = date('d.m.Y H:i');
        }
        
        return implode(', ', $nameParts);
    }

    /**
     * Получает свойства заказа в формате для сохранения профиля [PROPERTY_ID => VALUE]
     * 
     * @param Order $order Заказ
     * @param int $personTypeId ID типа плательщика
     * @return array Массив [PROPERTY_ID => VALUE]
     */
    private function getOrderPropertiesForProfile($order, $personTypeId)
    {
        $result = [];
        
        try {
            $propertyCollection = $order->getPropertyCollection();
            $totalProperties = 0;
            $propertiesWithValues = 0;
            $propertiesWithUserProps = 0;
            
            // Получаем все свойства заказа
            foreach ($propertyCollection as $property) {
                $totalProperties++;
                $propertyId = $property->getPropertyId();
                $value = $property->getValue();
                $propertyCode = $property->getField('CODE');
                
                // Пропускаем пустые значения
                if (empty($value) && $value !== '0') {
                    continue;
                }
                
                $propertiesWithValues++;
                
                // Проверяем, что свойство должно сохраняться в профиле (USER_PROPS = Y)
                $propertyData = \Bitrix\Sale\Internals\OrderPropsTable::getList([
                    'filter' => [
                        'ID' => $propertyId,
                        'PERSON_TYPE_ID' => $personTypeId,
                        'ACTIVE' => 'Y',
                        'USER_PROPS' => 'Y'
                    ],
                    'select' => ['ID', 'CODE', 'NAME', 'USER_PROPS'],
                    'limit' => 1
                ])->fetch();
                
                if ($propertyData) {
                    $propertiesWithUserProps++;
                    $result[$propertyId] = $value;
                    error_log("[getOrderPropertiesForProfile] Property added: ID={$propertyId}, CODE={$propertyCode}, VALUE=" . (is_array($value) ? json_encode($value) : $value));
                } else {
                    // Проверяем, почему свойство не подходит
                    $checkProperty = \Bitrix\Sale\Internals\OrderPropsTable::getList([
                        'filter' => [
                            'ID' => $propertyId,
                            'PERSON_TYPE_ID' => $personTypeId
                        ],
                        'select' => ['ID', 'CODE', 'NAME', 'ACTIVE', 'USER_PROPS'],
                        'limit' => 1
                    ])->fetch();
                    
                    if ($checkProperty) {
                        error_log("[getOrderPropertiesForProfile] Property skipped: ID={$propertyId}, CODE={$propertyCode}, ACTIVE={$checkProperty['ACTIVE']}, USER_PROPS={$checkProperty['USER_PROPS']}");
                    } else {
                        error_log("[getOrderPropertiesForProfile] Property not found: ID={$propertyId}, CODE={$propertyCode}");
                    }
                }
            }
            
            error_log("[getOrderPropertiesForProfile] Summary: total={$totalProperties}, with_values={$propertiesWithValues}, with_user_props={$propertiesWithUserProps}, result_count=" . count($result));
            
        } catch (\Exception $e) {
            error_log('[getOrderPropertiesForProfile] Exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        }
        
        return $result;
    }
}
