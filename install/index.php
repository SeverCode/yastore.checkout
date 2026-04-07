<?php
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Config\Option;

class yastore_checkout extends CModule
{
    var $MODULE_ID = "yastore.checkout";
    var $MODULE_NAME = "Экспресс-чекаут Яндекс KIT";
    var $MODULE_VERSION = "0.0.25";
    var $MODULE_VERSION_DATE = "2026-04-07";
    var $MODULE_DESCRIPTION = "Экспресс-чекаут Яндекс KIT";
    var $PARTNER_NAME = "Яндекс";
    var $PARTNER_URI = "https://kit.yandex.ru";

    function DoInstall()
    {
        CopyDirFiles(
            $_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/yastore.checkout/js/yastore.checkout/",
            $_SERVER["DOCUMENT_ROOT"] . "/bitrix/js/yastore.checkout/",
            true,
            true
        );
        CopyDirFiles(
            $_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/yastore.checkout/css/yastore.checkout/",
            $_SERVER["DOCUMENT_ROOT"] . "/bitrix/css/yastore.checkout/",
            true,
            true
        );

        ModuleManager::registerModule($this->MODULE_ID);

        RegisterModuleDependences("main", "OnBeforeProlog", $this->MODULE_ID, "\\Yastore\\Checkout\\Handlers", "appendYandexCheckoutJs");
        $this->registerSaleMailGateHandlers();
        RegisterModuleDependences("sale", "OnSaleOrderSaved", $this->MODULE_ID, "\\Yastore\\Checkout\\Handlers", "onSaleOrderSaved");
        RegisterModuleDependences("sale", "OnSaleStatusOrderChange", $this->MODULE_ID, "\\Yastore\\Checkout\\Handlers", "onSaleStatusOrderChange");

        $user = new CUser;
        $uniqString = md5(time() . uniqid());

        $arFields = array(
            "NAME" => "YastoreCheckoutUser",
            "LOGIN" => "yastore_checkout_user_" . substr($uniqString, 0, 5),
            "EMAIL" => "yastore_" . substr($uniqString, 0, 5) . "@yandex.ru",
            "PASSWORD" => $uniqString,
            "CONFIRM_PASSWORD" => $uniqString,
            "ACTIVE" => "Y",
            "GROUP_ID" => array(2),
        );

        $newUserId = $user->Add($arFields);

        if (intval($newUserId) > 0) {
            Option::set($this->MODULE_ID, "YASTORE_USER_ID", $newUserId);
        }

        // Создаем служебную платежную систему
        $this->createServicePaymentSystem();
        
        // Обновляем ID платежной системы
        $this->updatePaymentSystemId();
        
        // Создаем служебную службу доставки
        $this->createServiceDeliveryService();
        
        // Создаем свойства заказа
        $this->createOrderProperties();
        
        // Устанавливаем статусы по умолчанию
        Option::set($this->MODULE_ID, 'STATUS_ON_PLACED', 'P');
        Option::set($this->MODULE_ID, 'STATUS_ON_CANCEL', 'C');
        Option::set($this->MODULE_ID, 'STATUS_ON_DELIVERED', 'F');
        
        // Копируем API файл в корень сайта
        $this->copyApiFile();
    }
    
    function DoUpdate()
    {
        // Обновляем значение YANDEX_KIT_PAY_SYSTEM_ID при обновлении модуля
        $this->updatePaymentSystemId();
        $this->registerSaleMailGateHandlers();
    }

    /**
     * Обработчик отключения писем Sale — на событиях, из которых вызывается Notify (sort=1).
     */
    private function registerSaleMailGateHandlers()
    {
        foreach ($this->getSaleMailGateEventNames() as $eventName) {
            UnRegisterModuleDependences("sale", $eventName, $this->MODULE_ID, "\\Yastore\\Checkout\\Handlers", "onSaleOrderSavedMailGate");
            RegisterModuleDependences("sale", $eventName, $this->MODULE_ID, "\\Yastore\\Checkout\\Handlers", "onSaleOrderSavedMailGate", 1);
        }
    }

    private function unregisterSaleMailGateHandlers()
    {
        foreach ($this->getSaleMailGateEventNames() as $eventName) {
            UnRegisterModuleDependences("sale", $eventName, $this->MODULE_ID, "\\Yastore\\Checkout\\Handlers", "onSaleOrderSavedMailGate");
        }
    }

    private function getSaleMailGateEventNames()
    {
        return [
            "OnSaleOrderSaved",
            "OnSaleOrderCanceled",
            "OnSaleOrderPaid",
            "OnSaleStatusOrderChange",
            "OnShipmentAllowDelivery",
        ];
    }
    
    private function updatePaymentSystemId()
    {
        try {
            if (!\Bitrix\Main\Loader::includeModule('sale')) {
                return;
            }
            
            $db = \Bitrix\Main\Application::getConnection();
            
            // Ищем существующую платежную систему в b_sale_pay_system_action по ACTION_FILE
            // Берем самую новую (с максимальным PAY_SYSTEM_ID), на случай если есть несколько
            $actionFile = '/bitrix/php_interface/include/sale_payment/yandex_kit';
            $existingPaySystemAction = $db->query("
                SELECT PAY_SYSTEM_ID 
                FROM b_sale_pay_system_action 
                WHERE ACTION_FILE = '" . $db->getSqlHelper()->forSql($actionFile) . "'
                ORDER BY PAY_SYSTEM_ID DESC
                LIMIT 1
            ")->fetch();
            
            if ($existingPaySystemAction) {
                $paySystemId = $existingPaySystemAction['PAY_SYSTEM_ID'];
                // Всегда обновляем значение, чтобы использовать самую новую платежную систему
                Option::set($this->MODULE_ID, 'YANDEX_KIT_PAY_SYSTEM_ID', $paySystemId);
            }
        } catch (Exception $e) {
            // Игнорируем ошибки
        }
    }

    function DoUninstall()
    {
        // Удаляем служебные системы при деинсталляции
        //$this->removeServicePaymentSystem();
        //$this->removeServiceDeliveryService();
        
        // Удаляем обработчик платежной системы
        //$this->removePaymentHandler();
        
        // Удаляем API файл из корня сайта
        $this->removeApiFile();
        
        UnRegisterModuleDependences("main", "OnBeforeProlog", $this->MODULE_ID, "\\Yastore\\Checkout\\Handlers", "appendYandexCheckoutJs");
        $this->unregisterSaleMailGateHandlers();
        UnRegisterModuleDependences("sale", "OnSaleOrderSaved", $this->MODULE_ID, "\\Yastore\\Checkout\\Handlers", "onSaleOrderSaved");
        UnRegisterModuleDependences("sale", "OnSaleStatusOrderChange", $this->MODULE_ID, "\\Yastore\\Checkout\\Handlers", "onSaleStatusOrderChange");
        ModuleManager::unRegisterModule($this->MODULE_ID);
    }

    private function createServicePaymentSystem()
    {
        try {
            // Подключаем модуль sale
            if (!\Bitrix\Main\Loader::includeModule('sale')) {
                return;
            }

            // Копируем обработчик платежной системы в нужное место
            $this->copyPaymentHandler();
            
            $db = \Bitrix\Main\Application::getConnection();

            // Ищем существующую платежную систему в b_sale_pay_system_action по ACTION_FILE
            // Берем самую новую (с максимальным PAY_SYSTEM_ID), на случай если есть несколько
            $actionFile = '/bitrix/php_interface/include/sale_payment/yandex_kit';
            $existingPaySystemAction = $db->query("
                SELECT PAY_SYSTEM_ID 
                FROM b_sale_pay_system_action 
                WHERE ACTION_FILE = '" . $db->getSqlHelper()->forSql($actionFile) . "'
                ORDER BY PAY_SYSTEM_ID DESC
                LIMIT 1
            ")->fetch();

            $paySystemId = null;

            if ($existingPaySystemAction) {
                // Запись найдена, используем существующий ID
                $paySystemId = $existingPaySystemAction['PAY_SYSTEM_ID'];
            } else {
                // Запись не найдена, создаем новую платежную систему
                // Получаем первый тип плательщика
                $personType = $db->query("SELECT ID FROM b_sale_person_type LIMIT 1")->fetch();
                $personTypeId = $personType ? $personType['ID'] : 1;
                
                $fields = [
                    'NAME' => 'Яндекс KIT',
                    'PSA_NAME' => 'Яндекс KIT',
                    //'PERSON_TYPE_ID' => $personTypeId,
                    'ACTIVE' => 'N', // Неактивна для пользователей
                    'SORT' => 1000,
                    'DESCRIPTION' => 'Служебная платежная система для Яндекс KIT API',
                    'ACTION_FILE' => $actionFile,
                    'NEW_WINDOW' => 'N',
                    'XML_ID' => 'YANDEX_KIT_PAYMENT',
                    'ENTITY_REGISTRY_TYPE' => \Bitrix\Sale\Registry::REGISTRY_TYPE_ORDER,
                    'HAVE_PREPAY' => 'N',
                    'HAVE_RESULT' => 'N',
                    'HAVE_ACTION' => 'N',
                    'HAVE_PAYMENT' => 'N',
                    'HAVE_RESULT_RECEIVE' => 'Y',
                ];
                
                $result = \Bitrix\Sale\PaySystem\Manager::add($fields);
                
                if ($result->isSuccess()) {
                    $actionId = $result->getId();
                    
                    // Обновляем PAY_SYSTEM_ID на сам ID
                    if ($actionId) {
                        \Bitrix\Sale\PaySystem\Manager::update($actionId, [
                            'PAY_SYSTEM_ID' => $actionId,
                            'PARAMS' => serialize(['BX_PAY_SYSTEM_ID' => $actionId])
                        ]);
                        
                        // Получаем PAY_SYSTEM_ID из созданной записи
                        $createdAction = $db->query("
                            SELECT PAY_SYSTEM_ID 
                            FROM b_sale_pay_system_action 
                            WHERE ID = " . intval($actionId) . "
                            LIMIT 1
                        ")->fetch();
                        
                        if ($createdAction && $createdAction['PAY_SYSTEM_ID']) {
                            $paySystemId = $createdAction['PAY_SYSTEM_ID'];
                        } else {
                            // Если PAY_SYSTEM_ID не установлен, используем ID записи
                            $paySystemId = $actionId;
                        }
                    }
                }
            }

            // Всегда записываем найденный или созданный ID в настройки
            if ($paySystemId) {
                Option::set($this->MODULE_ID, 'YANDEX_KIT_PAY_SYSTEM_ID', $paySystemId);
            }
        } catch (Exception $e) {
            // Игнорируем ошибки
        }
    }

    private function copyPaymentHandler()
    {
        try {
            $sourceDir = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/' . $this->MODULE_ID . '/install/templates/yandex_kit/';
            $targetDir = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/php_interface/include/sale_payment/yandex_kit/';
            
            // Создаем целевую директорию если не существует
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0755, true);
            }
            
            // Копируем файлы обработчика
            $files = [
                'handler.php',
                '.description.php',
                'template.php',
                'lang/ru/handler.php'
            ];
            
            foreach ($files as $file) {
                $sourceFile = $sourceDir . $file;
                $targetFile = $targetDir . $file;
                
                if (file_exists($sourceFile)) {
                    // Создаем поддиректории если нужно
                    $targetDirPath = dirname($targetFile);
                    if (!is_dir($targetDirPath)) {
                        mkdir($targetDirPath, 0755, true);
                    }
                    
                    copy($sourceFile, $targetFile);
                }
            }
        } catch (Exception $e) {
            // Игнорируем ошибки
        }
    }

    private function createServiceDeliveryService()
    {
        try {
            // Подключаем модуль sale
            if (!\Bitrix\Main\Loader::includeModule('sale')) {
                return;
            }

            // Подключаем класс доставки вручную (модуль еще не зарегистрирован)
            require_once($_SERVER["DOCUMENT_ROOT"] . '/bitrix/modules/' . $this->MODULE_ID . '/lib/delivery/yandexkitdelivery.php');

            // Проверяем, не существует ли уже служебная служба доставки (через SQL для скорости)
            $db = \Bitrix\Main\Application::getConnection();
            $existingDelivery = $db->query("SELECT ID FROM b_sale_delivery_srv WHERE NAME = 'Яндекс KIT' LIMIT 1")->fetch();

            if ($existingDelivery) {
                Option::set($this->MODULE_ID, 'YANDEX_KIT_DELIVERY_ID', $existingDelivery['ID']);
                return;
            }

            // Создаем служебную службу доставки через Manager
            $fields = [
                'NAME' => 'Яндекс KIT',
                'ACTIVE' => 'N', // Неактивна для пользователей
                'SORT' => 1000,
                'DESCRIPTION' => 'Служебная служба доставки для Яндекс KIT API',
                'CODE' => 'YANDEX_KIT_DELIVERY',
                'CLASS_NAME' => '\\Yastore\\Checkout\\Delivery\\YandexKitDelivery',
            ];
            
            $result = \Bitrix\Sale\Delivery\Services\Manager::add($fields);
            if ($result->isSuccess()) {
                $deliveryId = $result->getId();
                Option::set($this->MODULE_ID, 'YANDEX_KIT_DELIVERY_ID', $deliveryId);
            }
        } catch (Exception $e) {
            // Игнорируем ошибки
        }
    }

    private function removeServicePaymentSystem()
    {
        try {
            // Подключаем модуль sale если он доступен
            if (!\Bitrix\Main\Loader::includeModule('sale')) {
                return;
            }

            $paySystemId = Option::get($this->MODULE_ID, 'YANDEX_KIT_PAY_SYSTEM_ID', '');
            if ($paySystemId) {
                $db = \Bitrix\Main\Application::getConnection();
                
                // Удаляем записи из b_sale_pay_system_action
                $db->query("DELETE FROM b_sale_pay_system_action WHERE PAY_SYSTEM_ID = " . intval($paySystemId));
                
                // Удаляем платежную систему
                $result = \Bitrix\Sale\PaySystem\Manager::delete($paySystemId);
                if ($result->isSuccess()) {
                    Option::delete($this->MODULE_ID, ['name' => 'YANDEX_KIT_PAY_SYSTEM_ID']);
                }
            }
        } catch (Exception $e) {
            // Игнорируем ошибки
        }
    }

    private function removeServiceDeliveryService()
    {
        try {
            // Подключаем модуль sale если он доступен
            if (!\Bitrix\Main\Loader::includeModule('sale')) {
                return;
            }

            $deliveryId = Option::get($this->MODULE_ID, 'YANDEX_KIT_DELIVERY_ID', '');
            if ($deliveryId) {
                $result = \Bitrix\Sale\Delivery\Services\Manager::delete($deliveryId);
                if ($result->isSuccess()) {
                    Option::delete($this->MODULE_ID, ['name' => 'YANDEX_KIT_DELIVERY_ID']);
                }
            }
        } catch (Exception $e) {
            // Игнорируем ошибки
        }
    }

    private function removePaymentHandler()
    {
        try {
            $targetDir = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/php_interface/include/sale_payment/yandex_kit/';
            
            if (is_dir($targetDir)) {
                $this->removeDirectory($targetDir);
            }
        } catch (Exception $e) {
            // Игнорируем ошибки
        }
    }

    private function removeDirectory($dir)
    {
        if (is_dir($dir)) {
            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ($object != "." && $object != "..") {
                    if (is_dir($dir . "/" . $object)) {
                        $this->removeDirectory($dir . "/" . $object);
                    } else {
                        unlink($dir . "/" . $object);
                    }
                }
            }
            rmdir($dir);
        }
    }

    private function createOrderProperties()
    {
        try {
            // Подключаем модуль sale
            if (!\Bitrix\Main\Loader::includeModule('sale')) {
                return;
            }

            // Получаем все типы плательщиков
            $personTypes = \Bitrix\Sale\PersonType::getList([
                'filter' => ['ACTIVE' => 'Y'],
                'select' => ['ID', 'NAME']
            ])->fetchAll();

            if (empty($personTypes)) {
                return;
            }

            // Определяем свойства которые нужно создать
            $propertiesToCreate = [
                // Внешний ID заказа
                [
                    'CODE' => 'EXTERNAL_ORDER_ID',
                    'NAME' => 'Внешний ID заказа (Яндекс)',
                    'TYPE' => 'STRING',
                    'SORT' => 100,
                ],
                [
                    'CODE' => 'YANDEX_ORDER_ID',
                    'NAME' => 'Внешний ID Заказа (Яндекс ID)',
                    'TYPE' => 'STRING',
                    'SORT' => 110,
                ],
                [
                    'CODE' => 'YANDEX_ORDER_NUM',
                    'NAME' => 'Заказ № (Яндекс)',
                    'TYPE' => 'STRING',
                    'SORT' => 90,
                ],
                // Данные доставки
                [
                    'CODE' => 'DELIVERY_TYPE',
                    'NAME' => 'Тип доставки',
                    'TYPE' => 'STRING',
                    'SORT' => 200,
                ],
                [
                    'CODE' => 'DELIVERY_SERVICE',
                    'NAME' => 'Служба доставки',
                    'TYPE' => 'STRING',
                    'SORT' => 210,
                ],
                // Тип оплаты
                [
                    'CODE' => 'PAYMENT_METHOD',
                    'NAME' => 'Тип оплаты',
                    'TYPE' => 'STRING',
                    'SORT' => 250,
                ],
                // Адрес доставки
                [
                    'CODE' => 'STREET',
                    'NAME' => 'Улица',
                    'TYPE' => 'STRING',
                    'SORT' => 310,
                ],
                [
                    'CODE' => 'BUILDING',
                    'NAME' => 'Дом',
                    'TYPE' => 'STRING',
                    'SORT' => 320,
                ],
                [
                    'CODE' => 'APARTMENT',
                    'NAME' => 'Квартира',
                    'TYPE' => 'STRING',
                    'SORT' => 330,
                ],
                [
                    'CODE' => 'ENTRANCE',
                    'NAME' => 'Подъезд',
                    'TYPE' => 'STRING',
                    'SORT' => 340,
                ],
                [
                    'CODE' => 'FLOOR',
                    'NAME' => 'Этаж',
                    'TYPE' => 'STRING',
                    'SORT' => 350,
                ],
                [
                    'CODE' => 'INTERCOM',
                    'NAME' => 'Домофон',
                    'TYPE' => 'STRING',
                    'SORT' => 360,
                ],
                // Пункт выдачи
                [
                    'CODE' => 'PICKUP_POINT_ID',
                    'NAME' => 'ID пункта выдачи',
                    'TYPE' => 'STRING',
                    'SORT' => 400,
                ],
            ];

            // Для каждого типа плательщика создаем свойства
            foreach ($personTypes as $personType) {
                $personTypeId = $personType['ID'];

                // Получаем группу свойств для данного типа плательщика
                $propsGroup = \Bitrix\Sale\Internals\OrderPropsGroupTable::getList([
                    'filter' => ['PERSON_TYPE_ID' => $personTypeId],
                    'select' => ['ID'],
                    'limit' => 1
                ])->fetch();

                if (!$propsGroup) {
                    // Если группы нет, создаем её
                    $groupResult = \Bitrix\Sale\Internals\OrderPropsGroupTable::add([
                        'PERSON_TYPE_ID' => $personTypeId,
                        'NAME' => 'Свойства заказа',
                        'SORT' => 100,
                    ]);
                    
                    if ($groupResult->isSuccess()) {
                        $propsGroupId = $groupResult->getId();
                    } else {
                        continue; // Не удалось создать группу, пропускаем этот тип
                    }
                } else {
                    $propsGroupId = $propsGroup['ID'];
                }

                foreach ($propertiesToCreate as $propData) {
                    // Проверяем, не существует ли уже такое свойство
                    $existing = \Bitrix\Sale\Internals\OrderPropsTable::getList([
                        'filter' => [
                            'PERSON_TYPE_ID' => $personTypeId,
                            'CODE' => $propData['CODE']
                        ],
                        'select' => ['ID'],
                        'limit' => 1
                    ])->fetch();

                    if ($existing) {
                        continue; // Свойство уже существует
                    }

                    // Создаем свойство
                    $result = \Bitrix\Sale\Internals\OrderPropsTable::add([
                        'PERSON_TYPE_ID' => $personTypeId,
                        'PROPS_GROUP_ID' => $propsGroupId,
                        'NAME' => $propData['NAME'],
                        'CODE' => $propData['CODE'],
                        'TYPE' => $propData['TYPE'],
                        'REQUIRED' => 'N',
                        'USER_PROPS' => 'N',
                        'IS_LOCATION' => 'N',
                        'IS_EMAIL' => 'N',
                        'IS_PROFILE_NAME' => 'N',
                        'IS_PAYER' => 'N',
                        'IS_LOCATION4TAX' => 'N',
                        'IS_FILTERED' => 'N',
                        'IS_ZIP' => 'N',
                        'IS_PHONE' => 'N',
                        'IS_ADDRESS' => 'N',
                        'ACTIVE' => 'Y',
                        'UTIL' => 'Y',
                        'SORT' => $propData['SORT'],
                        'DEFAULT_VALUE' => '',
                        'DESCRIPTION' => '',
                        'SETTINGS' => 'a:0:{}',
                        'ENTITY_REGISTRY_TYPE' => 'ORDER',
                    ]);
                }
            }

        } catch (\Exception $e) {
            // Игнорируем ошибки
        }
    }

    private function copyApiFile()
    {
        try {
            $sourceFile = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/' . $this->MODULE_ID . '/api/index.php';
            $targetDir = $_SERVER['DOCUMENT_ROOT'] . '/yastore.checkout/';
            $targetFile = $targetDir . 'index.php';
            
            // Создаем целевую директорию если не существует
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0755, true);
            }
            
            // Копируем index.php
            if (file_exists($sourceFile)) {
                copy($sourceFile, $targetFile);
            }
            // Копируем .htaccess (передача заголовка Authorization в PHP)
            $sourceHtaccess = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/' . $this->MODULE_ID . '/api/.htaccess';
            $targetHtaccess = $targetDir . '.htaccess';
            if (file_exists($sourceHtaccess)) {
                copy($sourceHtaccess, $targetHtaccess);
            }
        } catch (Exception $e) {
            // Игнорируем ошибки
        }
    }

    private function removeApiFile()
    {
        try {
            $targetDir = $_SERVER['DOCUMENT_ROOT'] . '/yastore.checkout/';
            $targetFile = $targetDir . 'index.php';
            
            // Удаляем index.php
            if (file_exists($targetFile)) {
                unlink($targetFile);
            }
            // Удаляем .htaccess
            $targetHtaccess = $targetDir . '.htaccess';
            if (file_exists($targetHtaccess)) {
                unlink($targetHtaccess);
            }
            // Удаляем директорию если она пуста
            if (is_dir($targetDir) && count(scandir($targetDir)) == 2) {
                rmdir($targetDir);
            }
        } catch (Exception $e) {
            // Игнорируем ошибки
        }
    }
}
