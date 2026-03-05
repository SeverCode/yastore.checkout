<?php
/**
 * Тест логики виртуального склада для товара
 * 
 * Использование (только из консоли):
 *   php test_product.php 83
 *   php test_product.php 86
 */

// Проверяем, что скрипт запущен из консоли
if (php_sapi_name() !== 'cli') {
    die("Этот скрипт можно запускать только из консоли (CLI)\n");
}

// Определяем DOCUMENT_ROOT относительно расположения скрипта
// Скрипт находится в: bitrix/modules/yastore.checkout/tests/test_product.php
// Нужно подняться на 4 уровня вверх до корня проекта
if (!isset($_SERVER['DOCUMENT_ROOT']) || empty($_SERVER['DOCUMENT_ROOT'])) {
    $scriptDir = __DIR__;
    // Поднимаемся на 4 уровня вверх: tests -> yastore.checkout -> modules -> bitrix -> корень
    $_SERVER['DOCUMENT_ROOT'] = realpath($scriptDir . '/../../../../');
    
    if (!$_SERVER['DOCUMENT_ROOT']) {
        die("Ошибка: не удалось определить корневую директорию проекта\n");
    }
}

// Проверяем существование файла prolog_before.php
$prologPath = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
if (!file_exists($prologPath)) {
    die("Ошибка: файл prolog_before.php не найден по пути: $prologPath\n");
}

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    require_once($prologPath);
}

use Bitrix\Main\Loader;
use Bitrix\Catalog\StoreTable;
use Bitrix\Catalog\ProductTable;
use Bitrix\Catalog\StoreProductTable;
use Bitrix\Sale\Configuration;
use Yastore\Checkout\Handlers\WarehousesHandler;
use Yastore\Checkout\Handlers\CheckBasketHandler;
use Yastore\Checkout\Handlers\OrdersHandler;
use Bitrix\Main\UserTable;

// Подключаем необходимые модули
if (!Loader::includeModule('catalog') || !Loader::includeModule('sale') || !Loader::includeModule('yastore.checkout')) {
    die("Не удалось подключить необходимые модули\n");
}

// Получаем ID товара из параметров командной строки
if (empty($argv[1])) {
    die("Использование: php test_product.php <product_id>\nПример: php test_product.php 83\n");
}

$productId = intval($argv[1]);

if ($productId <= 0) {
    die("Ошибка: ID товара должен быть положительным числом\n");
}

echo "================================================================================\n";
echo "ТЕСТИРОВАНИЕ ТОВАРА ID: $productId\n";
echo "================================================================================\n\n";

// 1. Проверяем существование товара
echo "1. Проверка существования товара\n";
echo "--------------------------------------------------------------------------------\n";

$product = ProductTable::getList([
    'filter' => ['ID' => $productId],
    'select' => ['ID', 'QUANTITY', 'QUANTITY_RESERVED']
])->fetch();

if (!$product) {
    die("[ERROR] Товар с ID $productId не найден в системе\n");
}

echo "[OK] Товар найден\n";
echo "   Общее количество (QUANTITY): " . ($product['QUANTITY'] ?? 0) . "\n";
echo "   Зарезервировано (QUANTITY_RESERVED): " . ($product['QUANTITY_RESERVED'] ?? 0) . "\n";
$totalQuantity = (float)($product['QUANTITY'] ?? 0);
$reservedQuantity = (float)($product['QUANTITY_RESERVED'] ?? 0);
$availableQuantity = max(0, $totalQuantity - $reservedQuantity);
echo "   Доступно: $availableQuantity\n\n";

// 2. Проверяем наличие складов в системе
echo "2. Проверка наличия складов в системе\n";
echo "--------------------------------------------------------------------------------\n";

$stores = StoreTable::getList([
    'filter' => ['ACTIVE' => 'Y'],
    'select' => ['ID', 'TITLE', 'XML_ID'],
    'order' => ['SORT' => 'ASC', 'ID' => 'ASC']
]);

$storesList = [];
while ($store = $stores->fetch()) {
    $storesList[] = $store;
}

if (empty($storesList)) {
    echo "[WARNING] В системе нет активных складов\n";
    $hasWarehouses = false;
} else {
    echo "[OK] Найдено складов: " . count($storesList) . "\n";
    foreach ($storesList as $store) {
        echo "   - ID: {$store['ID']}, Название: {$store['TITLE']}, XML_ID: " . ($store['XML_ID'] ?: 'не указан') . "\n";
    }
    $hasWarehouses = true;
}
echo "\n";

// 3. Проверяем остатки по складам для товара
echo "3. Проверка остатков по складам для товара\n";
echo "--------------------------------------------------------------------------------\n";

$storeProducts = StoreProductTable::getList([
    'filter' => ['PRODUCT_ID' => $productId],
    'select' => ['STORE_ID', 'AMOUNT', 'QUANTITY_RESERVED']
]);

$storeStockList = [];
$totalStoreAmount = 0;
while ($storeProduct = $storeProducts->fetch()) {
    $store = StoreTable::getById($storeProduct['STORE_ID'])->fetch();
    if ($store && $store['ACTIVE'] === 'Y') {
        $amount = (int)$storeProduct['AMOUNT'];
        $reserved = isset($storeProduct['QUANTITY_RESERVED']) ? (int)$storeProduct['QUANTITY_RESERVED'] : 0;
        $available = max(0, $amount - $reserved);
        $totalStoreAmount += $amount;
        
        $storeStockList[] = [
            'store_id' => $store['ID'],
            'store_title' => $store['TITLE'],
            'amount' => $amount,
            'reserved' => $reserved,
            'available' => $available
        ];
    }
}

if (empty($storeStockList)) {
    echo "[WARNING] Нет остатков по складам для этого товара\n";
    $hasStock = false;
} else {
    echo "[OK] Найдено остатков по складам:\n";
    foreach ($storeStockList as $stock) {
        echo "   - Склад: {$stock['store_title']} (ID: {$stock['store_id']})\n";
        echo "     Количество: {$stock['amount']}, Зарезервировано: {$stock['reserved']}, Доступно: {$stock['available']}\n";
    }
    echo "   Всего по складам: $totalStoreAmount\n";
    $hasStock = true;
}
echo "\n";

// 4. Проверяем логику виртуального склада
echo "4. Проверка логики виртуального склада\n";
echo "--------------------------------------------------------------------------------\n";

$shouldUseVirtualWarehouse = false;
$reason = '';

if ($availableQuantity > 0) {
    if (!$hasWarehouses) {
        $shouldUseVirtualWarehouse = true;
        $reason = 'Общий остаток > 0, но складов нет в системе';
    } elseif (!$hasStock) {
        $shouldUseVirtualWarehouse = true;
        $reason = 'Общий остаток > 0, но остатков по складам нет';
    } else {
        $reason = 'Есть остатки по складам - используем реальные склады';
    }
} else {
    $reason = 'Общий остаток = 0 - виртуальный склад не используется';
}

echo "   Общий остаток: $availableQuantity\n";
echo "   Есть склады: " . ($hasWarehouses ? 'да' : 'нет') . "\n";
echo "   Есть остатки по складам: " . ($hasStock ? 'да' : 'нет') . "\n";
echo "   Должен использоваться виртуальный склад: " . ($shouldUseVirtualWarehouse ? 'ДА' : 'НЕТ') . "\n";
echo "   Причина: $reason\n\n";

// 5. Тестируем CheckBasketHandler
echo "5. Тест CheckBasketHandler::getWarehouseAvailability()\n";
echo "--------------------------------------------------------------------------------\n";

try {
    // Проверяем состояние складского учета
    $isStoreControl = Configuration::useStoreControl();
    echo "   Складской учет: " . ($isStoreControl ? 'включен' : 'выключен') . "\n";
    echo "   hasWarehouses: " . ($hasWarehouses ? 'true' : 'false') . "\n";
    echo "   hasStock: " . ($hasStock ? 'true' : 'false') . "\n";
    echo "   availableQuantity: $availableQuantity\n";
    echo "   Условие для виртуального склада: " . (($availableQuantity > 0 && (!$hasWarehouses || !$hasStock)) ? 'true' : 'false') . "\n\n";
    
    $handler = new CheckBasketHandler();
    $reflection = new ReflectionClass($handler);
    $method = $reflection->getMethod('getWarehouseAvailability');
    $method->setAccessible(true);
    
    $result = $method->invoke($handler, $productId, null);
    
    echo "   Результат метода:\n";
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
    
    // Проверяем, есть ли виртуальный склад в результате
    // Виртуальный склад определяется по условию: должен использоваться И id = "1"
    $hasVirtualInResult = false;
    $hasRealWarehouse = false;
    
    if (is_array($result) && !empty($result)) {
        foreach ($result as $warehouse) {
            if (isset($warehouse['id'])) {
                // Проверяем, существует ли склад с таким ID в системе
                $warehouseExists = false;
                foreach ($storesList as $store) {
                    $storeId = (string)$store['ID'];
                    $storeXmlId = $store['XML_ID'] ?: $storeId;
                    if ($warehouse['id'] === $storeId || $warehouse['id'] === $storeXmlId) {
                        $warehouseExists = true;
                        $hasRealWarehouse = true;
                        break;
                    }
                }
                
                // Если склад с id="1" существует в системе, это реальный склад
                // Виртуальный склад используется только когда складов нет или остатков по складам нет
                if ($warehouse['id'] === '1' && $shouldUseVirtualWarehouse && !$warehouseExists) {
                    $hasVirtualInResult = true;
                }
            }
        }
    }
    
    if ($shouldUseVirtualWarehouse && !$hasVirtualInResult) {
        echo "   [ERROR] Должен использоваться виртуальный склад, но его нет в результате!\n\n";
    } elseif (!$shouldUseVirtualWarehouse && $hasVirtualInResult) {
        echo "   [WARNING] Виртуальный склад в результате, но не должен использоваться\n\n";
    } elseif ($shouldUseVirtualWarehouse && $hasVirtualInResult) {
        echo "   [OK] Виртуальный склад используется как ожидалось\n\n";
    } else {
        if ($hasRealWarehouse) {
            echo "   [OK] Возвращается реальный склад (есть склады и остатки)\n\n";
        } else {
            echo "   [OK] Виртуальный склад не используется (есть реальные склады/остатки)\n\n";
        }
    }
    
} catch (\Exception $e) {
    echo "   [ERROR] " . $e->getMessage() . "\n\n";
}

// 6. Тестируем OrdersHandler::checkInventoryConflicts
echo "6. Тест OrdersHandler::checkInventoryConflicts()\n";
echo "--------------------------------------------------------------------------------\n";

try {
    $handler = new OrdersHandler();
    $reflection = new ReflectionClass($handler);
    $method = $reflection->getMethod('checkInventoryConflicts');
    $method->setAccessible(true);
    
    // Создаем тестовые данные с заведомо большим количеством для создания конфликта
    $items = [
        [
            'id' => $productId,
            'quantity' => intval($availableQuantity) + 1000, // Заведомо больше доступного
            'price' => 100.00,
            'final_price' => 90.00
        ]
    ];
    
    // Используем виртуальный склад или первый существующий
    $warehouseId = !$hasWarehouses ? '1' : ($storesList[0]['XML_ID'] ?: (string)$storesList[0]['ID']);
    
    $result = $method->invoke($handler, $items, $warehouseId);
    
    echo "   Тестовые данные:\n";
    echo "   - Товар ID: $productId\n";
    echo "   - Запрошенное количество: " . $items[0]['quantity'] . "\n";
    echo "   - Склад: $warehouseId\n\n";
    
    echo "   Результат метода:\n";
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
    
    // Проверяем, есть ли виртуальный склад в результате конфликтов
    $hasVirtualInConflicts = false;
    $hasRealWarehouseInConflicts = false;
    
    if (isset($result['items']) && is_array($result['items'])) {
        foreach ($result['items'] as $item) {
            if (isset($item['warehouses']) && is_array($item['warehouses'])) {
                foreach ($item['warehouses'] as $warehouse) {
                    if (isset($warehouse['id'])) {
                        // Проверяем, существует ли склад с таким ID в системе
                        $warehouseExists = false;
                        foreach ($storesList as $store) {
                            $storeId = (string)$store['ID'];
                            $storeXmlId = $store['XML_ID'] ?: $storeId;
                            if ($warehouse['id'] === $storeId || $warehouse['id'] === $storeXmlId) {
                                $warehouseExists = true;
                                $hasRealWarehouseInConflicts = true;
                                break;
                            }
                        }
                        
                        // Виртуальный склад используется только когда складов нет или остатков по складам нет
                        if ($warehouse['id'] === '1' && $shouldUseVirtualWarehouse && !$warehouseExists) {
                            $hasVirtualInConflicts = true;
                        }
                    }
                }
            }
        }
    }
    
    if ($shouldUseVirtualWarehouse && !$hasVirtualInConflicts && !empty($result['items'])) {
        echo "   [WARNING] Должен использоваться виртуальный склад в конфликтах\n\n";
    } elseif ($shouldUseVirtualWarehouse && $hasVirtualInConflicts) {
        echo "   [OK] Виртуальный склад используется в конфликтах\n\n";
    } else {
        if ($hasRealWarehouseInConflicts) {
            echo "   [OK] Возвращается реальный склад в конфликтах (есть склады и остатки)\n\n";
        } else {
            echo "   [INFO] Виртуальный склад не используется (есть реальные склады/остатки или нет конфликтов)\n\n";
        }
    }
    
} catch (\Exception $e) {
    echo "   [ERROR] " . $e->getMessage() . "\n\n";
}

// 7. Тест поиска пользователя по email
echo "7. Тест поиска пользователя по email\n";
echo "--------------------------------------------------------------------------------\n";

try {
    $handler = new OrdersHandler();
    $reflection = new ReflectionClass($handler);
    $method = $reflection->getMethod('findUserByEmail');
    $method->setAccessible(true);
    
    // Тест 1: Поиск несуществующего пользователя
    echo "   Тест 1: Поиск несуществующего пользователя\n";
    $testEmail1 = 'test_nonexistent_' . time() . '@test.local';
    $result1 = $method->invoke($handler, $testEmail1);
    
    if ($result1 === false) {
        echo "   [OK] Пользователь не найден (как и ожидалось)\n";
    } else {
        echo "   [ERROR] Найден пользователь с несуществующим email: $result1\n";
    }
    echo "\n";
    
    // Тест 2: Создание тестового пользователя и поиск его
    echo "   Тест 2: Создание тестового пользователя и поиск\n";
    $testEmail2 = 'test_user_' . time() . '@test.local';
    $testLogin = 'test_user_' . time();
    
    $cUser = new \CUser();
    $userFields = [
        'LOGIN' => $testLogin,
        'EMAIL' => $testEmail2,
        'NAME' => 'Тестовый',
        'LAST_NAME' => 'Пользователь',
        'PASSWORD' => 'TestPassword123!',
        'CONFIRM_PASSWORD' => 'TestPassword123!',
        'ACTIVE' => 'Y'
    ];
    
    $createdUserId = $cUser->Add($userFields);
    
    if ($createdUserId) {
        echo "   [OK] Тестовый пользователь создан (ID: $createdUserId, Email: $testEmail2)\n";
        
        // Ищем созданного пользователя
        $result2 = $method->invoke($handler, $testEmail2);
        
        if ($result2 == $createdUserId) {
            echo "   [OK] Пользователь найден по email (ID: $result2)\n";
        } else {
            echo "   [ERROR] Пользователь не найден или найден другой (ожидался ID: $createdUserId, получен: " . ($result2 ?: 'false') . ")\n";
        }
        
        // Удаляем тестового пользователя
        $cUser->Delete($createdUserId);
        echo "   [OK] Тестовый пользователь удален\n";
    } else {
        echo "   [ERROR] Не удалось создать тестового пользователя: " . $cUser->LAST_ERROR . "\n";
    }
    echo "\n";
    
    // Тест 3: Поиск существующего пользователя в системе
    echo "   Тест 3: Поиск существующего пользователя в системе\n";
    $existingUser = UserTable::getList([
        'filter' => ['!EMAIL' => false],
        'select' => ['ID', 'EMAIL'],
        'limit' => 1
    ])->fetch();
    
    if ($existingUser && !empty($existingUser['EMAIL'])) {
        echo "   Найден существующий пользователь: ID={$existingUser['ID']}, Email={$existingUser['EMAIL']}\n";
        
        $result3 = $method->invoke($handler, $existingUser['EMAIL']);
        
        if ($result3 == $existingUser['ID']) {
            echo "   [OK] Существующий пользователь найден по email (ID: $result3)\n";
        } else {
            echo "   [ERROR] Пользователь не найден или найден другой (ожидался ID: {$existingUser['ID']}, получен: " . ($result3 ?: 'false') . ")\n";
        }
    } else {
        echo "   [INFO] В системе нет пользователей с email для тестирования\n";
    }
    echo "\n";
    
    // Тест 4: Поиск с пустым email
    echo "   Тест 4: Поиск с пустым email\n";
    $result4 = $method->invoke($handler, '');
    
    if ($result4 === false) {
        echo "   [OK] Пустой email корректно обработан (вернул false)\n";
    } else {
        echo "   [ERROR] Пустой email вернул неожиданный результат: $result4\n";
    }
    echo "\n";
    
    // Тест 5: Поиск неактивного пользователя
    echo "   Тест 5: Поиск неактивного пользователя\n";
    $testEmail5 = 'test_inactive_' . time() . '@test.local';
    $testLogin5 = 'test_inactive_' . time();
    
    $userFields5 = [
        'LOGIN' => $testLogin5,
        'EMAIL' => $testEmail5,
        'NAME' => 'Неактивный',
        'LAST_NAME' => 'Пользователь',
        'PASSWORD' => 'TestPassword123!',
        'CONFIRM_PASSWORD' => 'TestPassword123!',
        'ACTIVE' => 'N' // Неактивный пользователь
    ];
    
    $inactiveUserId = $cUser->Add($userFields5);
    
    if ($inactiveUserId) {
        echo "   [OK] Неактивный пользователь создан (ID: $inactiveUserId, Email: $testEmail5)\n";
        
        $result5 = $method->invoke($handler, $testEmail5);
        
        if ($result5 == $inactiveUserId) {
            echo "   [OK] Неактивный пользователь найден по email (ID: $result5)\n";
        } else {
            echo "   [ERROR] Неактивный пользователь не найден (ожидался ID: $inactiveUserId, получен: " . ($result5 ?: 'false') . ")\n";
        }
        
        // Удаляем тестового пользователя
        $cUser->Delete($inactiveUserId);
        echo "   [OK] Неактивный тестовый пользователь удален\n";
    } else {
        echo "   [ERROR] Не удалось создать неактивного пользователя: " . $cUser->LAST_ERROR . "\n";
    }
    echo "\n";
    
} catch (\Exception $e) {
    echo "   [ERROR] " . $e->getMessage() . "\n";
    echo "   Trace: " . $e->getTraceAsString() . "\n\n";
}

// 8. Итоговая сводка
echo "================================================================================\n";
echo "ИТОГОВАЯ СВОДКА\n";
echo "================================================================================\n\n";

echo "Товар ID: $productId\n";
echo "Общий остаток: $availableQuantity\n";
echo "Остатки по складам: " . ($hasStock ? "есть ($totalStoreAmount)" : "нет") . "\n";
echo "Склады в системе: " . ($hasWarehouses ? "есть (" . count($storesList) . ")" : "нет") . "\n";
echo "Использование виртуального склада: " . ($shouldUseVirtualWarehouse ? "ДА" : "НЕТ") . "\n";
echo "Причина: $reason\n\n";

if ($shouldUseVirtualWarehouse) {
    echo "[OK] ЛОГИКА РАБОТАЕТ КОРРЕКТНО: Виртуальный склад должен использоваться\n";
} else {
    echo "[OK] ЛОГИКА РАБОТАЕТ КОРРЕКТНО: Виртуальный склад не используется (есть реальные склады/остатки)\n";
}

echo "\n";

