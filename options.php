<?php
use \Bitrix\Main\Localization\Loc;
use \Bitrix\Main\Config\Option;

Loc::loadMessages(__FILE__);

$request = \Bitrix\Main\HttpApplication::getInstance()->getContext()->getRequest();
$module_id = 'yastore.checkout';

$arStatuses = array();
if (\Bitrix\Main\Loader::includeModule('sale')) {
    $statusResult = \Bitrix\Sale\Internals\StatusLangTable::getList(array(
        'filter' => array('LID' => LANGUAGE_ID),
        'select' => array('STATUS_ID', 'NAME')
    ));
    while ($status = $statusResult->fetch()) {
        $arStatuses[$status['STATUS_ID']] = '[' . $status['STATUS_ID'] . '] ' . $status['NAME'];
    }
}

// Получаем список инфоблоков торговых предложений
$arSkuIBlocks = array();
if (\Bitrix\Main\Loader::includeModule('catalog') && \Bitrix\Main\Loader::includeModule('iblock')) {
    $catalogIterator = \Bitrix\Catalog\CatalogIblockTable::getList(array(
        'select' => array('IBLOCK_ID', 'PRODUCT_IBLOCK_ID'),
        'filter' => array('>PRODUCT_IBLOCK_ID' => 0) // Инфоблоки торговых предложений
    ));
    while ($catalog = $catalogIterator->fetch()) {
        $iblockId = (int)$catalog['IBLOCK_ID'];
        $iblock = \CIBlock::GetByID($iblockId)->Fetch();
        if ($iblock) {
            $arSkuIBlocks[$iblockId] = '[' . $iblock['IBLOCK_TYPE_ID'] . '] ' . $iblock['NAME'];
        }
    }
}

$aTabs = array(
    array(
        'DIV' => 'edit1',
        'TAB' => 'Доступ к Яндекс KIT',
        'OPTIONS' => array(
            array('JWT_TOKEN', 'Токен доступа', '', array('text', 100)),
            array('YANDEX_KIT_CREDENTIALS', 'Токен API Яндекс KIT', '', array('text', 100)),
        )
    ),
    array(
        'DIV' => 'edit2',
        'TAB' => 'Статусы заказов',
        'OPTIONS' => array(
            array('STATUS_ON_PLACED', 'Статус при оплате заказа (placed)', 'P', array('select', $arStatuses)),
            array('STATUS_ON_CANCEL', 'Статус при отмене заказа (cancel)', 'C', array('select', $arStatuses)),
            array('STATUS_ON_DELIVERED', 'Статус при доставке заказа (delivered)', 'F', array('select', $arStatuses)),
        )
    ),
    array(
        'DIV' => 'edit3',
        'TAB' => 'Автоматизация',
        'OPTIONS' => array(
            array('AUTO_CANCEL_ON_STATUS_CHANGE', 'Отменять заказ в Яндекс KIT при смене статуса', 'N', array('checkbox')),
            array('AUTO_CANCEL_STATUS', 'Статус для автоматической отмены', 'C', array('select', $arStatuses)),
            array('AUTO_COMPLETE_ON_STATUS_CHANGE', 'Завершать заказ в Яндекс KIT при смене статуса', 'N', array('checkbox')),
            array('AUTO_COMPLETE_STATUS', 'Статус для автоматического завершения', 'F', array('select', $arStatuses)),
        )
    ),
        array(
        'DIV' => 'edit4',
        'TAB' => 'Торговые предложения',
        'OPTIONS' => array(
            array('USE_SKU', 'Использовать торговые предложения', 'N', array('checkbox')),
            array('SKU_IBLOCK_ID', 'Инфоблок торговых предложений', '', array('select', $arSkuIBlocks)),
            array('SKU_PROPERTIES', 'Свойства торговых предложений', '', array('multiselect', array())),
            array('SKU_COLOR_PROPERTY', 'Свойство «Цвет»', '', array('sku_color_select')),
        )
    ),
);

// Генерация токена (обрабатываем отдельно, до сохранения других полей)
$tokenGenerated = false;
if ($request->isPost() && check_bitrix_sessid() && $request->getPost('generate_token')) {
    // Генерируем токен: 64 символа в hex формате
    $newToken = bin2hex(random_bytes(32)); // 32 байта = 64 hex символа
    Option::set($module_id, 'JWT_TOKEN', $newToken);
    LocalRedirect($APPLICATION->GetCurPage() . '?mid=' . urlencode($module_id) . '&lang=' . LANGUAGE_ID . '&token_generated=Y');
    $tokenGenerated = true;
}

// Получение свойств инфоблока торговых предложений (AJAX запрос)
if ($request->isPost() && check_bitrix_sessid() && $request->getPost('get_sku_properties')) {
    header('Content-Type: application/json');
    
    $iblockId = (int)$request->getPost('iblock_id');
    
    if ($iblockId <= 0) {
        echo json_encode([
            'success' => false,
            'error' => 'Не указан ID инфоблока'
        ]);
        exit;
    }
    
    $properties = array();
    
    if (\Bitrix\Main\Loader::includeModule('iblock')) {
        $propertyIterator = \CIBlockProperty::GetList(
            array('SORT' => 'ASC', 'ID' => 'ASC'),
            array('IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y')
        );
        
        while ($property = $propertyIterator->Fetch()) {
            // Исключаем служебные свойства
            if (stripos($property['CODE'], 'PHOTO') !== false) {
                continue;
            }
            if (strpos($property['CODE'], 'CML2_') === 0) {
                continue;
            }
            if ($property['PROPERTY_TYPE'] == 'F') { // Файл
                continue;
            }
            
            $properties[$property['ID']] = $property['NAME'] . ' [' . $property['CODE'] . ']';
        }
    }
    
    echo json_encode([
        'success' => true,
        'properties' => $properties
    ]);
    exit;
}

// Получение списка значений свойства (для свойства «Цвет» — автозаполнение соответствий)
if ($request->isPost() && check_bitrix_sessid() && $request->getPost('get_sku_property_values')) {
    header('Content-Type: application/json');
    
    $propertyId = (int)$request->getPost('property_id');
    $iblockId = (int)$request->getPost('iblock_id');
    
    if ($propertyId <= 0 || $iblockId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Не указан ID свойства или инфоблока', 'values' => []]);
        exit;
    }
    
    $values = array();
    
    if (\Bitrix\Main\Loader::includeModule('iblock')) {
        $property = \CIBlockProperty::GetByID($propertyId)->Fetch();
        if (!$property || (int)$property['IBLOCK_ID'] !== $iblockId) {
            echo json_encode(['success' => true, 'values' => []]);
            exit;
        }
        
        if ($property['PROPERTY_TYPE'] === 'L') {
            $enumRes = \CIBlockPropertyEnum::GetList(
                array('SORT' => 'ASC', 'VALUE' => 'ASC'),
                array('PROPERTY_ID' => $propertyId)
            );
            while ($enum = $enumRes->GetNext()) {
                $v = trim((string)$enum['VALUE']);
                if ($v !== '') {
                    $values[] = $v;
                }
            }
        } elseif ($property['PROPERTY_TYPE'] === 'S' || $property['PROPERTY_TYPE'] === 'N') {
            $propCode = $property['CODE'];
            $res = \CIBlockElement::GetList(
                array('ID' => 'ASC'),
                array('IBLOCK_ID' => $iblockId, '!PROPERTY_' . $propCode => false),
                array('PROPERTY_' . $propCode),
                array('nTopCount' => 1000),
                array('ID', 'PROPERTY_' . $propCode)
            );
            $seen = array();
            while ($el = $res->GetNext()) {
                $pv = $el['PROPERTY_' . $propCode . '_VALUE'] ?? $el['PROPERTY_' . $propCode] ?? '';
                if (is_array($pv)) {
                    foreach ($pv as $one) {
                        $one = trim((string)$one);
                        if ($one !== '' && !isset($seen[$one])) {
                            $seen[$one] = true;
                            $values[] = $one;
                        }
                    }
                } else {
                    $pv = trim((string)$pv);
                    if ($pv !== '' && !isset($seen[$pv])) {
                        $seen[$pv] = true;
                        $values[] = $pv;
                    }
                }
            }
            sort($values);
        }
    }
    
    echo json_encode(['success' => true, 'values' => array_values($values)]);
    exit;
}

// Проверка подключения к API (AJAX запрос)
if ($request->isPost() && check_bitrix_sessid() && $request->getPost('test_connection')) {
    header('Content-Type: application/json');
    
    // Используем дефолтный адрес API, если не указан иной в опциях
    $apiUrl = Option::get($module_id, 'YANDEX_KIT_API_URL', 'https://integration.yastore.yandex.net/');
    
    // Получаем токен в формате STORE_ID#TOKEN
    $credentials = $request->getPost('YANDEX_KIT_CREDENTIALS') ?: Option::get($module_id, 'YANDEX_KIT_CREDENTIALS', '');
    
    if (empty($credentials)) {
        echo json_encode([
            'success' => false,
            'error' => 'Заполните поле: Токен АПИ Яндекс KIT (STORE_ID#TOKEN)'
        ]);
        exit;
    }
    
    // Разбиваем на STORE_ID и TOKEN
    $parts = explode('#', $credentials, 2);
    if (count($parts) !== 2 || empty($parts[0]) || empty($parts[1])) {
        echo json_encode([
            'success' => false,
            'error' => 'Неверный формат токена. Используйте формат: STORE_ID#TOKEN'
        ]);
        exit;
    }
    
    $storeId = trim($parts[0]);
    $apiToken = trim($parts[1]);
    
    // Убираем завершающий слэш, если есть
    $apiUrl = rtrim($apiUrl, '/');
    $url = "{$apiUrl}/api/public/v1/store";
    
    // Выполняем curl запрос
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'authorization: Bearer ' . $apiToken,
            'yandex-kit-store-id: ' . $storeId,
            'Content-Type: application/json'
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        echo json_encode([
            'success' => false,
            'error' => 'Ошибка подключения'
        ]);
        exit;
    }
    
    if ($httpCode >= 200 && $httpCode < 300) {
        $data = json_decode($response, true);
        if (isset($data['store_slug'])) {
            $storeSlug = $data['store_slug'];
            $result = [
                'success' => true,
                'message' => 'Успешно',
                'store_slug' => $storeSlug
            ];

            // Self-test: при успешном получении slug проверяем метод warehouses с токеном из админки
            $jwtToken = Option::get($module_id, 'JWT_TOKEN', '');
            if (!empty($jwtToken)) {
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'] ?? '';
                $selfTestUrl = $scheme . '://' . $host . '/yastore.checkout/?method=warehouses';
                $chSelf = curl_init();
                curl_setopt_array($chSelf, [
                    CURLOPT_URL => $selfTestUrl,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => [
                        'Authorization: Bearer ' . $jwtToken,
                        'Content-Type: application/json'
                    ],
                    CURLOPT_TIMEOUT => 15,
                    CURLOPT_CONNECTTIMEOUT => 5,
                ]);
                $selfResponse = curl_exec($chSelf);
                $selfHttpCode = curl_getinfo($chSelf, CURLINFO_HTTP_CODE);
                $selfError = curl_error($chSelf);
                curl_close($chSelf);

                if ($selfError) {
                    $result['selftest_ok'] = false;
                    $result['selftest_error'] = 'Ошибка запроса: ' . $selfError;
                } elseif ($selfHttpCode === 200) {
                    $selfData = json_decode($selfResponse, true);
                    if (is_array($selfData) && array_key_exists('warehouses', $selfData)) {
                        $result['selftest_ok'] = true;
                    } else {
                        $result['selftest_ok'] = false;
                        $result['selftest_error'] = 'Неверный формат ответа warehouses';
                    }
                } else {
                    $result['selftest_ok'] = false;
                    $errBody = is_string($selfResponse) ? $selfResponse : '';
                    $result['selftest_error'] = 'HTTP ' . $selfHttpCode . ($errBody ? ': ' . mb_substr($errBody, 0, 200) : '');
                }
                $result['selftest_debug'] = [
                    'curl' => "curl -X GET '" . $selfTestUrl . "' -H 'Authorization: Bearer " . $jwtToken . "' -H 'Content-Type: application/json'",
                    'http_code' => $selfHttpCode,
                    'response' => is_string($selfResponse) ? $selfResponse : ''
                ];
            } else {
                $result['selftest_ok'] = false;
                $result['selftest_error'] = 'Токен доступа не настроен';
            }

            echo json_encode($result);
        } else {
            echo json_encode([
                'success' => false,
                'error' => 'Неверный формат ответа от API'
            ]);
        }
    } else {
        $errorData = json_decode($response, true);
        $errorMessage = isset($errorData['error']) ? $errorData['error'] : 'Ошибка подключения';
        echo json_encode([
            'success' => false,
            'error' => $errorMessage
        ]);
    }
    exit;
}

if ($request->isPost() && check_bitrix_sessid() && !$tokenGenerated) {
    foreach ($aTabs as $aTab) {
        foreach ($aTab['OPTIONS'] as $arOption) {
            if (!is_array($arOption))
                continue;

            $optionName = $arOption[0];
            // Для checkbox: если не отмечен, значение не приходит в POST, сохраняем 'N'
            if ($arOption[3][0] == 'checkbox') {
                $value = $request->getPost($optionName) == 'Y' ? 'Y' : 'N';
            } elseif ($arOption[3][0] == 'multiselect') {
                // Для multiselect получаем массив значений
                $value = $request->getPost($optionName);
                if (is_array($value)) {
                    $value = serialize($value);
                } else {
                    $value = '';
                }
            } else {
                $value = $request->getPost($optionName);
            }
            
            // Специальная обработка для YANDEX_KIT_CREDENTIALS - разбиваем и сохраняем в два поля
            if ($optionName == 'YANDEX_KIT_CREDENTIALS') {
                if (!empty($value)) {
                    $parts = explode('#', $value, 2);
                    if (count($parts) === 2) {
                        // Сохраняем в новое поле
                        Option::set($module_id, 'YANDEX_KIT_CREDENTIALS', (string) $value);
                        // Также сохраняем в старые поля для обратной совместимости
                        Option::set($module_id, 'YANDEX_KIT_STORE_ID', trim($parts[0]));
                        Option::set($module_id, 'YANDEX_KIT_API_TOKEN', trim($parts[1]));
                    } else {
                        Option::set($module_id, 'YANDEX_KIT_CREDENTIALS', '');
                    }
                } else {
                    Option::set($module_id, 'YANDEX_KIT_CREDENTIALS', '');
                }
            } else {
                Option::set($module_id, $optionName, (string) $value);
            }
        }
    }
    // Сохранение соответствия значение цвета → HEX только при явном выборе маппинга (не пустая карта)
    $colorMapJson = $request->getPost('SKU_COLOR_MAP');
    $colorMapDecoded = is_string($colorMapJson) ? json_decode($colorMapJson, true) : null;
    if (is_array($colorMapDecoded) && !empty($colorMapDecoded)) {
        Option::set($module_id, 'SKU_COLOR_MAP', $colorMapJson);
    } else {
        Option::set($module_id, 'SKU_COLOR_MAP', '');
    }
}

// Получаем сохраненные свойства SKU для JavaScript
$savedSkuProperties = array();
$skuPropertiesValue = Option::get($module_id, 'SKU_PROPERTIES', '');
if (!empty($skuPropertiesValue)) {
    $unserialized = @unserialize($skuPropertiesValue);
    $savedSkuProperties = is_array($unserialized) ? $unserialized : array();
}

// Получаем сохраненные токены для автоматической проверки подключения
$savedJwtToken = Option::get($module_id, 'JWT_TOKEN', '');
$savedCredentials = Option::get($module_id, 'YANDEX_KIT_CREDENTIALS', '');
$autoCheckConnection = false;
if (!empty($savedJwtToken) && !empty($savedCredentials)) {
    // Проверяем формат credentials (должен содержать #)
    $parts = explode('#', $savedCredentials, 2);
    if (count($parts) === 2 && !empty(trim($parts[0])) && !empty(trim($parts[1]))) {
        $autoCheckConnection = true;
    }
}

$tabControl = new CAdminTabControl('tabControl', $aTabs);
?>

<form method='POST' id='yastore_checkout_options_form'
    action='<? echo $APPLICATION->GetCurPage() ?>?mid=<?= htmlspecialcharsbx($module_id) ?>&lang=<?= LANGUAGE_ID ?>'
    onsubmit='buildColorMapJson(); return true;'>
    <?= bitrix_sessid_post(); ?>

    <? $tabControl->Begin(); ?>

    <? foreach ($aTabs as $aTab): ?>
        <? $tabControl->BeginNextTab(); ?>
        <? foreach ($aTab['OPTIONS'] as $arOption):
            if (!is_array($arOption))
                continue;

            // Специальная обработка для YANDEX_KIT_CREDENTIALS - объединяем значения из старых полей если новое пустое
            if ($arOption[0] == 'YANDEX_KIT_CREDENTIALS') {
                $val = Option::get($module_id, 'YANDEX_KIT_CREDENTIALS', '');
                // Если новое поле пустое, но есть старые значения - объединяем их
                if (empty($val)) {
                    $storeId = Option::get($module_id, 'YANDEX_KIT_STORE_ID', '');
                    $apiToken = Option::get($module_id, 'YANDEX_KIT_API_TOKEN', '');
                    if (!empty($storeId) && !empty($apiToken)) {
                        $val = $storeId . '#' . $apiToken;
                    }
                }
            } elseif ($arOption[0] == 'SKU_PROPERTIES') {
                // Для multiselect десериализуем значение
                $val = Option::get($module_id, 'SKU_PROPERTIES', '');
                if (!empty($val)) {
                    $unserialized = @unserialize($val);
                    $val = is_array($unserialized) ? $unserialized : array();
                } else {
                    $val = array();
                }
            } elseif ($arOption[0] == 'SKU_COLOR_PROPERTY') {
                $val = Option::get($module_id, 'SKU_COLOR_PROPERTY', '');
            } else {
                $val = Option::get($module_id, $arOption[0], $arOption[2]);
            }
            ?>
            <tr <? if ($arOption[0] == 'AUTO_CANCEL_STATUS'): ?>id='auto_cancel_status_row' style='display: <?= (Option::get($module_id, 'AUTO_CANCEL_ON_STATUS_CHANGE', 'N') == 'Y' ? '' : 'none') ?>;'<? endif; ?>
                <? if ($arOption[0] == 'AUTO_COMPLETE_STATUS'): ?>id='auto_complete_status_row' style='display: <?= (Option::get($module_id, 'AUTO_COMPLETE_ON_STATUS_CHANGE', 'N') == 'Y' ? '' : 'none') ?>;'<? endif; ?>
                <? if ($arOption[0] == 'SKU_IBLOCK_ID'): ?>id='sku_iblock_row' style='display: <?= (Option::get($module_id, 'USE_SKU', 'N') == 'Y' ? '' : 'none') ?>;'<? endif; ?>
                <? if ($arOption[0] == 'SKU_PROPERTIES'): ?>id='sku_properties_row' style='display: <?= (Option::get($module_id, 'USE_SKU', 'N') == 'Y' && !empty(Option::get($module_id, 'SKU_IBLOCK_ID', '')) ? '' : 'none') ?>;'<? endif; ?>
                <? if ($arOption[0] == 'SKU_COLOR_PROPERTY'): ?>id='sku_color_property_row' style='display: <?= (Option::get($module_id, 'USE_SKU', 'N') == 'Y' && !empty(Option::get($module_id, 'SKU_IBLOCK_ID', '')) ? '' : 'none') ?>;'<? endif; ?>>
                <td width='40%' style='white-space: nowrap;'>
                    <?= htmlspecialcharsbx($arOption[1]); ?>
                    <? if ($arOption[0] == 'USE_SKU'): ?>
                        <span style='position: relative; display: inline-block; margin-left: 5px;'>
                            <span id='use_sku_hint_icon' style='cursor: pointer; display: inline-block; width: 16px; height: 16px; line-height: 16px; text-align: center; background-color: #0066cc; color: #ffffff; border-radius: 50%; font-size: 11px; font-weight: bold; vertical-align: middle;' 
                                  onclick='toggleSkuHint(event)' onmouseenter='showSkuHint()' onmouseleave='hideSkuHintOnLeave(event)'>?</span>
                            <div id='use_sku_hint' style='display: none; text-align: left; position: absolute; left: 50%; transform: translateX(-50%); top: 22px; background-color: #fff3cd; border: 1px solid #ffc107; padding: 8px 12px; border-radius: 4px; box-shadow: 0 2px 8px rgba(0,0,0,0.15); z-index: 1000; width: 300px; white-space: normal;'
                                 onmouseenter='showSkuHint()' onmouseleave='hideSkuHintOnLeave(event)'>
                                Используется для вывода в оформлении заказа вариаций товаров (цвет, размер и т.д).
                            </div>
                        </span>
                    <? endif; ?>:
                </td>
                <td width='60%'>
                    <? if ($arOption[3][0] == 'checkbox'): ?>
                        <input type='checkbox' name='<?= htmlspecialcharsbx($arOption[0]) ?>' value='Y' <?= ($val == 'Y' ? 'checked' : '') ?>
                            <? if ($arOption[0] == 'AUTO_CANCEL_ON_STATUS_CHANGE'): ?>
                                id='auto_cancel_checkbox' onchange='toggleAutoCancelStatus()'
                            <? endif; ?>
                            <? if ($arOption[0] == 'AUTO_COMPLETE_ON_STATUS_CHANGE'): ?>
                                id='auto_complete_checkbox' onchange='toggleAutoCompleteStatus()'
                            <? endif; ?>
                            <? if ($arOption[0] == 'USE_SKU'): ?>
                                id='use_sku_checkbox' onchange='toggleSkuSettings()'
                            <? endif; ?>>
                    <? endif; ?>
                    <? if ($arOption[3][0] == 'text'): ?>
                        <input type='text' name='<?= htmlspecialcharsbx($arOption[0]) ?>' value='<?= htmlspecialcharsbx($val) ?>'
                            size='<?= htmlspecialcharsbx($arOption[3][1]) ?>' id='<?= htmlspecialcharsbx($arOption[0]) ?>'
                            <? if ($arOption[0] == 'YANDEX_KIT_CREDENTIALS'): ?>
                                onchange='checkConnectionButtonState()' onkeyup='checkConnectionButtonState()'
                            <? endif; ?>>
                    <? endif; ?>
                    <? if ($arOption[3][0] == 'select'): ?>
                        <select name='<?= htmlspecialcharsbx($arOption[0]) ?>'
                            <? if ($arOption[0] == 'AUTO_CANCEL_STATUS'): ?>
                                id='auto_cancel_status_select'
                            <? endif; ?>
                            <? if ($arOption[0] == 'AUTO_COMPLETE_STATUS'): ?>
                                id='auto_complete_status_select'
                            <? endif; ?>
                            <? if ($arOption[0] == 'SKU_IBLOCK_ID'): ?>
                                id='sku_iblock_select' onchange='loadSkuProperties()'
                            <? endif; ?>>
                            <option value=''>-- Выберите --</option>
                            <? foreach ($arOption[3][1] as $optValue => $optName): ?>
                                <option value='<?= htmlspecialcharsbx($optValue) ?>' <?= ($val == $optValue ? 'selected' : '') ?>>
                                    <?= htmlspecialcharsbx($optName) ?>
                                </option>
                            <? endforeach; ?>
                        </select>
                    <? endif; ?>
                    <? if ($arOption[3][0] == 'multiselect'): ?>
                        <select name='<?= htmlspecialcharsbx($arOption[0]) ?>[]' id='sku_properties_select' multiple size='10' style='width: 300px;'>
                            <!-- Опции будут загружены через JavaScript -->
                        </select>
                        <div id='sku_properties_loading' style='display: none; color: #666;'>Загрузка свойств...</div>
                    <? endif; ?>
                    <? if ($arOption[3][0] == 'sku_color_select'): ?>
                         <select name='<?= htmlspecialcharsbx($arOption[0]) ?>' id='sku_color_property_select' onchange='toggleColorMapRow(); loadColorPropertyValues(this.value);' style='width: 300px;'>
                             <option value="">— Не выбрано —</option>
                         </select>
                     <? endif; ?>
                </td>
            </tr>
            <? if (isset($arOption[0]) && $arOption[0] == 'SKU_COLOR_PROPERTY'): ?>
            <?php
            $savedColorMap = Option::get($module_id, 'SKU_COLOR_MAP', '');
            $colorMapDecoded = array();
            if ($savedColorMap !== '') {
                $colorMapDecoded = @json_decode($savedColorMap, true);
                if (!is_array($colorMapDecoded)) {
                    $colorMapDecoded = array();
                }
            }
            if (empty($colorMapDecoded)) {
                $defaultColorsPath = __DIR__ . '/data/default_colors.json';
                if (is_file($defaultColorsPath)) {
                    $defaultJson = @file_get_contents($defaultColorsPath);
                    if ($defaultJson !== false) {
                        $defaultList = @json_decode($defaultJson, true);
                        if (is_array($defaultList)) {
                            foreach ($defaultList as $item) {
                                if (!is_array($item)) continue;
                                $hex = isset($item['hex']) ? trim((string)$item['hex']) : '';
                                if ($hex === '') $hex = '#000000';
                                elseif (strpos($hex, '#') !== 0) $hex = '#' . $hex;
                                if (isset($item['ru']) && (string)$item['ru'] !== '') {
                                    $ru = (string)$item['ru'];
                                    $colorMapDecoded[$ru] = $hex;
                                    $colorMapDecoded[mb_strtolower($ru, 'UTF-8')] = $hex;
                                }
                                if (isset($item['en']) && (string)$item['en'] !== '') {
                                    $en = (string)$item['en'];
                                    $colorMapDecoded[$en] = $hex;
                                    $colorMapDecoded[mb_strtolower($en, 'UTF-8')] = $hex;
                                }
                            }
                        }
                    }
                }
            }
            ?>
            <tr id='sku_color_map_row' style='display: <?= Option::get($module_id, 'SKU_COLOR_PROPERTY', '') ? '' : 'none' ?>;'>
                <td colspan='2'>
                    <div style='margin-top: 8px;'>
                        <strong>Соответствие значение → HEX</strong>
                        <input type='hidden' name='SKU_COLOR_MAP' id='sku_color_map_input' value=''>
                        <table id='sku_color_map_table' style='margin-top: 8px; border-collapse: collapse;'>
                            <thead>
                                <tr>
                                    <th style='text-align: left; padding: 4px 8px 4px 0;'>Значение</th>
                                    <th style='text-align: left; padding: 4px 8px;'>Цвет (HEX)</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id='sku_color_map_tbody'>
                                <!-- Строки заполняются автоматически при выборе свойства «Цвет» -->
                            </tbody>
                        </table>
                        <input type='button' value='Добавить' class='adm-btn' style='margin-top: 6px;' onclick='addColorMapRow()'>
                    </div>
                </td>
            </tr>
            <? endif; ?>
            <? // Кнопка генерации токена сразу после поля JWT_TOKEN ?>
            <? if ($arOption[0] == 'JWT_TOKEN'): ?>
            <tr>
                <td width='40%'></td>
                <td width='60%'>
                    <input type='button' value='Сгенерировать токен' class='adm-btn' 
                        onclick="if(confirm('Сгенерировать новый токен? Текущий токен будет заменен.')) {
                            var form = document.createElement('form');
                            form.method = 'POST';
                            form.action = '<? echo $APPLICATION->GetCurPage() ?>?mid=<?= htmlspecialcharsbx($module_id) ?>&lang=<?= LANGUAGE_ID ?>';
                            var sessid = document.createElement('input');
                            sessid.type = 'hidden';
                            sessid.name = 'sessid';
                            sessid.value = '<?= bitrix_sessid() ?>';
                            form.appendChild(sessid);
                            var tokenBtn = document.createElement('input');
                            tokenBtn.type = 'hidden';
                            tokenBtn.name = 'generate_token';
                            tokenBtn.value = '1';
                            form.appendChild(tokenBtn);
                            document.body.appendChild(form);
                            form.submit();
                        }">
                    <? if ($request->get('token_generated') == 'Y'): ?>
                        <span style='color: green; margin-left: 10px;'>Токен успешно сгенерирован</span>
                    <? endif; ?>
                </td>
            </tr>
            <? endif; ?>
            <? // Кнопка проверки подключения после поля YANDEX_KIT_CREDENTIALS ?>
            <? if ($arOption[0] == 'YANDEX_KIT_CREDENTIALS'): ?>
            <tr>
                <td width='40%'></td>
                <td width='60%'>
                    <input type='button' id='test_connection_btn' value='Проверить' class='adm-btn' 
                        onclick='testConnection()' disabled>
                    <span id='connection_result' style='margin-left: 10px; display: inline-block; vertical-align: top;'></span>
                </td>
            </tr>
            <? endif; ?>
        <? endforeach; ?>
    <? endforeach; ?>

    <? $tabControl->Buttons(); ?>
    <input type='submit' name='Update' value='Сохранить' class='adm-btn-save'>
    <? $tabControl->End(); ?>
</form>

<script>
    function toggleAutoCancelStatus() {
        var checkbox = document.getElementById('auto_cancel_checkbox');
        var statusRow = document.getElementById('auto_cancel_status_row');
        if (checkbox && statusRow) {
            statusRow.style.display = checkbox.checked ? '' : 'none';
        }
    }
    
    function toggleAutoCompleteStatus() {
        var checkbox = document.getElementById('auto_complete_checkbox');
        var statusRow = document.getElementById('auto_complete_status_row');
        if (checkbox && statusRow) {
            statusRow.style.display = checkbox.checked ? '' : 'none';
        }
    }
    
    function showSkuHint() {
        var hint = document.getElementById('use_sku_hint');
        if (hint) {
            hint.style.display = 'block';
        }
    }
    
    function hideSkuHintOnLeave(event) {
        // Небольшая задержка, чтобы можно было перейти на подсказку
        setTimeout(function() {
            var hint = document.getElementById('use_sku_hint');
            var icon = document.getElementById('use_sku_hint_icon');
            if (hint && icon) {
                var relatedTarget = event.relatedTarget;
                // Проверяем, не перешли ли мы на подсказку или иконку
                if (relatedTarget && (hint.contains(relatedTarget) || icon.contains(relatedTarget))) {
                    return;
                }
                hint.style.display = 'none';
            }
        }, 100);
    }
    
    function toggleSkuHint(event) {
        if (event) {
            event.stopPropagation();
        }
        var hint = document.getElementById('use_sku_hint');
        if (hint) {
            hint.style.display = hint.style.display === 'block' ? 'none' : 'block';
        }
    }
    
    // Закрываем подсказку при клике вне её
    document.addEventListener('click', function(event) {
        var hint = document.getElementById('use_sku_hint');
        var icon = document.getElementById('use_sku_hint_icon');
        if (hint && icon && !hint.contains(event.target) && !icon.contains(event.target)) {
            hint.style.display = 'none';
        }
    });
    
    var savedSkuColorProperty = '<?= CUtil::JSEscape(Option::get($module_id, 'SKU_COLOR_PROPERTY', '')) ?>';
    var initialColorMap = <?= json_encode(isset($colorMapDecoded) ? $colorMapDecoded : array()) ?>;
    
    function toggleSkuSettings() {
        var checkbox = document.getElementById('use_sku_checkbox');
        var iblockRow = document.getElementById('sku_iblock_row');
        var propertiesRow = document.getElementById('sku_properties_row');
        var colorPropertyRow = document.getElementById('sku_color_property_row');
        
        if (checkbox && iblockRow) {
            var isVisible = checkbox.checked;
            iblockRow.style.display = isVisible ? '' : 'none';
            
            if (!isVisible) {
                if (propertiesRow) propertiesRow.style.display = 'none';
                if (colorPropertyRow) colorPropertyRow.style.display = 'none';
                document.getElementById('sku_color_map_row').style.display = 'none';
                var iblockSelect = document.getElementById('sku_iblock_select');
                if (iblockSelect) iblockSelect.value = '';
            } else {
                var iblockSelect = document.getElementById('sku_iblock_select');
                if (propertiesRow && iblockSelect && iblockSelect.value) {
                    propertiesRow.style.display = '';
                    if (colorPropertyRow) colorPropertyRow.style.display = '';
                    loadSkuProperties();
                    toggleColorMapRow();
                } else {
                    if (colorPropertyRow) colorPropertyRow.style.display = 'none';
                    document.getElementById('sku_color_map_row').style.display = 'none';
                }
            }
        }
    }
    
    function toggleColorMapRow() {
        var select = document.getElementById('sku_color_property_select');
        var row = document.getElementById('sku_color_map_row');
        if (select && row) {
            row.style.display = select.value ? '' : 'none';
        }
    }
    
    function loadColorPropertyValues(propertyId) {
        var tbody = document.getElementById('sku_color_map_tbody');
        var iblockSelect = document.getElementById('sku_iblock_select');
        if (!tbody) return;
        if (!propertyId) {
            tbody.innerHTML = '';
            return;
        }
        var iblockId = iblockSelect ? iblockSelect.value : '';
        if (!iblockId) {
            tbody.innerHTML = '';
            return;
        }
        var formData = new FormData();
        formData.append('get_sku_property_values', 'Y');
        formData.append('property_id', propertyId);
        formData.append('iblock_id', iblockId);
        formData.append('sessid', '<?= bitrix_sessid() ?>');
        fetch('<?= $APPLICATION->GetCurPage() ?>?mid=<?= urlencode($module_id) ?>&lang=<?= LANGUAGE_ID ?>', {
            method: 'POST',
            body: formData
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            tbody.innerHTML = '';
            var values = (data.success && data.values) ? data.values : [];
            var map = typeof initialColorMap === 'object' && initialColorMap !== null ? initialColorMap : {};
            function getHexForValue(m, v) {
                if (!v) return '#000000';
                if (m[v]) return m[v].indexOf('#') === 0 ? m[v] : '#' + m[v];
                var lower = (v + '').toLowerCase();
                for (var k in m) if (Object.prototype.hasOwnProperty.call(m, k) && (k + '').toLowerCase() === lower)
                    return m[k].indexOf('#') === 0 ? m[k] : '#' + m[k];
                return '#000000';
            }
            for (var i = 0; i < values.length; i++) {
                var val = values[i];
                var hex = getHexForValue(map, val);
                if (hex.indexOf('#') !== 0) hex = '#' + hex;
                var tr = document.createElement('tr');
                tr.className = 'sku-color-map-row';
                tr.innerHTML = "<td style='padding: 4px 8px 4px 0;'><input type='text' class='sku-color-map-value' readonly style='width: 200px; background: #f5f5f5;'></td>" +
                    "<td style='padding: 4px 8px;'><input type='color' class='sku-color-map-hex' style='width: 60px; height: 28px; padding: 0; border: 1px solid #ccc;'></td>" +
                    "<td><input type='button' value='Удалить' class='adm-btn' onclick='removeColorMapRow(this)'></td>";
                tr.querySelector('.sku-color-map-value').value = val;
                tr.querySelector('.sku-color-map-hex').value = hex;
                tbody.appendChild(tr);
            }
        })
        .catch(function() {
            tbody.innerHTML = '';
        });
    }
    
    function addColorMapRow() {
        var tbody = document.getElementById('sku_color_map_tbody');
        if (!tbody) return;
        var tr = document.createElement('tr');
        tr.className = 'sku-color-map-row';
        tr.innerHTML = "<td style='padding: 4px 8px 4px 0;'><input type='text' class='sku-color-map-value' value='' style='width: 200px;'></td>" +
            "<td style='padding: 4px 8px;'><input type='color' class='sku-color-map-hex' value='#000000' style='width: 60px; height: 28px; padding: 0; border: 1px solid #ccc;'></td>" +
            "<td><input type='button' value='Удалить' class='adm-btn' onclick='removeColorMapRow(this)'></td>";
        tbody.appendChild(tr);
    }
    
    function removeColorMapRow(btn) {
        var row = btn && btn.closest ? btn.closest('tr') : null;
        if (row) row.remove();
    }
    
    function buildColorMapJson() {
        var tbody = document.getElementById('sku_color_map_tbody');
        var input = document.getElementById('sku_color_map_input');
        if (!tbody || !input) return;
        var obj = {};
        var rows = tbody.querySelectorAll('tr.sku-color-map-row');
        for (var i = 0; i < rows.length; i++) {
            var valInp = rows[i].querySelector('.sku-color-map-value');
            var hexInp = rows[i].querySelector('.sku-color-map-hex');
            if (valInp && hexInp) {
                var v = (valInp.value || '').trim();
                if (v) obj[v] = hexInp.value || '#000000';
            }
        }
        input.value = JSON.stringify(obj);
    }
    
    function loadSkuProperties() {
        var iblockSelect = document.getElementById('sku_iblock_select');
        var propertiesSelect = document.getElementById('sku_properties_select');
        var loadingDiv = document.getElementById('sku_properties_loading');
        var propertiesRow = document.getElementById('sku_properties_row');
        
        if (!iblockSelect || !propertiesSelect) {
            return;
        }
        
        var iblockId = iblockSelect.value;
        
        if (!iblockId) {
            if (propertiesRow) propertiesRow.style.display = 'none';
            propertiesSelect.innerHTML = '';
            var colorSelect = document.getElementById('sku_color_property_select');
            if (colorSelect) { colorSelect.innerHTML = '<option value="">— Не выбрано —</option>'; toggleColorMapRow(); }
            return;
        }
        
        // Показываем список свойств
        if (propertiesRow) {
            propertiesRow.style.display = '';
        }
        
        // Показываем индикатор загрузки
        if (loadingDiv) {
            loadingDiv.style.display = '';
        }
        propertiesSelect.innerHTML = '';
        propertiesSelect.disabled = true;
        
        // Отправляем AJAX запрос
        var formData = new FormData();
        formData.append('get_sku_properties', 'Y');
        formData.append('iblock_id', iblockId);
        formData.append('sessid', '<?= bitrix_sessid() ?>');
        
        fetch('<?= $APPLICATION->GetCurPage() ?>?mid=<?= urlencode($module_id) ?>&lang=<?= LANGUAGE_ID ?>', {
            method: 'POST',
            body: formData
        })
        .then(function(response) {
            return response.json();
        })
        .then(function(data) {
            if (loadingDiv) {
                loadingDiv.style.display = 'none';
            }
            
            if (data.success && data.properties) {
                propertiesSelect.innerHTML = '';
                
                var savedValues = <?= json_encode(isset($savedSkuProperties) ? $savedSkuProperties : array()) ?>;
                
                for (var propId in data.properties) {
                    var option = document.createElement('option');
                    option.value = propId;
                    option.textContent = data.properties[propId];
                    if (savedValues.indexOf(propId.toString()) !== -1 || savedValues.indexOf(parseInt(propId)) !== -1) {
                        option.selected = true;
                    }
                    propertiesSelect.appendChild(option);
                }
                
                // Заполняем селект «Свойство Цвет»
                var colorSelect = document.getElementById('sku_color_property_select');
                if (colorSelect) {
                    var firstOpt = colorSelect.querySelector('option[value=""]');
                    colorSelect.innerHTML = '';
                    if (firstOpt) colorSelect.appendChild(firstOpt);
                    else {
                        var emptyOpt = document.createElement('option');
                        emptyOpt.value = '';
                        emptyOpt.textContent = '— Не выбрано —';
                        colorSelect.appendChild(emptyOpt);
                    }
                    for (var propId in data.properties) {
                        var opt = document.createElement('option');
                        opt.value = propId;
                        opt.textContent = data.properties[propId];
                        if (String(propId) === String(savedSkuColorProperty)) opt.selected = true;
                        colorSelect.appendChild(opt);
                    }
                    toggleColorMapRow();
                    if (colorSelect.value) loadColorPropertyValues(colorSelect.value);
                }
            } else {
                propertiesSelect.innerHTML = '<option value="">Свойства не найдены</option>';
                var colorSelect = document.getElementById('sku_color_property_select');
                if (colorSelect) {
                    colorSelect.innerHTML = '<option value="">— Не выбрано —</option>';
                    toggleColorMapRow();
                }
            }
            
            propertiesSelect.disabled = false;
        })
        .catch(function(error) {
            if (loadingDiv) {
                loadingDiv.style.display = 'none';
            }
            propertiesSelect.innerHTML = '<option value="">Ошибка загрузки свойств</option>';
            propertiesSelect.disabled = false;
            console.error('Error loading SKU properties:', error);
        });
    }
    
    // Инициализация при загрузке страницы
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            toggleAutoCancelStatus();
            toggleAutoCompleteStatus();
            toggleSkuSettings();
            // Загружаем свойства, если инфоблок уже выбран
            var iblockSelect = document.getElementById('sku_iblock_select');
            if (iblockSelect && iblockSelect.value) {
                loadSkuProperties();
            }
        });
    } else {
        toggleAutoCancelStatus();
        toggleAutoCompleteStatus();
        toggleSkuSettings();
        // Загружаем свойства, если инфоблок уже выбран
        var iblockSelect = document.getElementById('sku_iblock_select');
        if (iblockSelect && iblockSelect.value) {
            loadSkuProperties();
        }
    }
    
    // Проверка состояния кнопки "Проверить"
    function checkConnectionButtonState() {
        var credentials = document.getElementById('YANDEX_KIT_CREDENTIALS');
        var btn = document.getElementById('test_connection_btn');
        
        if (credentials && btn) {
            var credentialsValue = credentials.value.trim();
            // Проверяем, что есть символ # и обе части не пустые
            var parts = credentialsValue.split('#');
            var isValid = parts.length === 2 && parts[0].trim() && parts[1].trim();
            
            btn.disabled = !isValid;
        }
    }
    
    // Проверка подключения к API
    function testConnection() {
        var btn = document.getElementById('test_connection_btn');
        var resultSpan = document.getElementById('connection_result');
        
        if (!btn || !resultSpan) {
            return;
        }
        
        var credentials = document.getElementById('YANDEX_KIT_CREDENTIALS').value.trim();
        
        if (!credentials) {
            resultSpan.innerHTML = '<span style="color: red;">Заполните поле токена</span>';
            return;
        }
        
        var parts = credentials.split('#');
        if (parts.length !== 2 || !parts[0].trim() || !parts[1].trim()) {
            resultSpan.innerHTML = '<span style="color: red;">Неверный формат. Используйте: STORE_ID#TOKEN</span>';
            return;
        }
        
        // Показываем индикатор загрузки
        btn.disabled = true;
        btn.value = 'Проверка...';
        resultSpan.innerHTML = '<span style="color: blue; font-size: 11px; font-family: monospace;">Проверка подключения...</span>';
        
        // Создаем форму для отправки данных
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = '<? echo $APPLICATION->GetCurPage() ?>?mid=<?= htmlspecialcharsbx($module_id) ?>&lang=<?= LANGUAGE_ID ?>';
        
        var sessid = document.createElement('input');
        sessid.type = 'hidden';
        sessid.name = 'sessid';
        sessid.value = '<?= bitrix_sessid() ?>';
        form.appendChild(sessid);
        
        var testBtn = document.createElement('input');
        testBtn.type = 'hidden';
        testBtn.name = 'test_connection';
        testBtn.value = '1';
        form.appendChild(testBtn);
        
        var credentialsInput = document.createElement('input');
        credentialsInput.type = 'hidden';
        credentialsInput.name = 'YANDEX_KIT_CREDENTIALS';
        credentialsInput.value = credentials;
        form.appendChild(credentialsInput);
        
        // Отправляем запрос через fetch для получения JSON ответа
        var formData = new FormData();
        formData.append('sessid', '<?= bitrix_sessid() ?>');
        formData.append('test_connection', '1');
        formData.append('YANDEX_KIT_CREDENTIALS', credentials);
        
        fetch('<? echo $APPLICATION->GetCurPage() ?>?mid=<?= htmlspecialcharsbx($module_id) ?>&lang=<?= LANGUAGE_ID ?>', {
            method: 'POST',
            body: formData
        })
        .then(function(response) {
            return response.json();
        })
        .then(function(data) {
            btn.disabled = false;
            btn.value = 'Проверить';
            
            var labelStyle = 'font-size: 11px; font-family: monospace;';
            if (data.success) {
                var line1 = '<span style="' + labelStyle + '">Проверка подключения к Яндекс Кит:</span> <span style="' + labelStyle + ' color: green;">' + data.message;
                if (data.store_slug) {
                    line1 += '. Идентификатор сайта: ' + data.store_slug;
                }
                line1 += '</span>';
                var line2 = '';
                if (data.selftest_ok === true) {
                    line2 = '<span style="' + labelStyle + '">Проверка доступности API сайта:</span> <span style="' + labelStyle + ' color: green;">Успешно.</span>';
                } else if (data.selftest_ok === false && data.selftest_error) {
                    line2 = '<span style="' + labelStyle + '">Проверка доступности API сайта:</span> <span style="' + labelStyle + ' color: red;" title="' + (data.selftest_error || '').replace(/"/g, '&quot;') + '">' + (data.selftest_error || 'ошибка') + '</span>';
                }
                var debugBlock = '';
                if (data.selftest_debug) {
                    debugBlock = '<span style="display: block; margin-top: 4px;"><span id="selftest_debug_btn" style="cursor: pointer; font-size: 11px; font-family: monospace; color: #666; border: 1px solid #999; border-radius: 2px; padding: 0 3px; line-height: 1.2;" title="Показать curl и ответ">?</span><pre id="selftest_debug_pre" style="display: none; margin-top: 6px; padding: 8px; background: #f5f5f5; border: 1px solid #ccc; font-size: 11px; font-family: monospace; white-space: pre-wrap; max-width: 560px; max-height: 200px; overflow: auto;"></pre></span>';
                }
                resultSpan.innerHTML = '<span style="display: block;">' + line1 + '</span>' + (line2 ? '<span style="display: block; margin-top: 4px;">' + line2 + '</span>' : '') + debugBlock;
                if (data.selftest_debug) {
                    var debugBtn = document.getElementById('selftest_debug_btn');
                    var debugPre = document.getElementById('selftest_debug_pre');
                    if (debugBtn && debugPre) {
                        debugBtn.onclick = function() {
                            if (debugPre.style.display === 'none') {
                                if (debugPre.textContent === '') {
                                    debugPre.textContent = data.selftest_debug.curl + '\n\n--- Ответ (HTTP ' + data.selftest_debug.http_code + ') ---\n\n' + (data.selftest_debug.response || '');
                                }
                                debugPre.style.display = 'block';
                            } else {
                                debugPre.style.display = 'none';
                            }
                        };
                    }
                }
            } else {
                resultSpan.innerHTML = '<span style="' + labelStyle + '">Проверка подключения к Яндекс Кит:</span> <span style="' + labelStyle + ' color: red;">' + (data.error || 'Ошибка подключения') + '</span>';
            }
        })
        .catch(function(error) {
            btn.disabled = false;
            btn.value = 'Проверить';
            resultSpan.innerHTML = '<span style="font-size: 11px; font-family: monospace; color: red;">Ошибка: ' + error.message + '</span>';
        });
    }
    
    // Инициализация состояния кнопки при загрузке страницы
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            checkConnectionButtonState();
            // Автоматическая проверка подключения, если токены заполнены
            <? if ($autoCheckConnection): ?>
            setTimeout(function() {
                testConnection();
            }, 500);
            <? endif; ?>
        });
    } else {
        checkConnectionButtonState();
        // Автоматическая проверка подключения, если токены заполнены
        <? if ($autoCheckConnection): ?>
        setTimeout(function() {
            testConnection();
        }, 500);
        <? endif; ?>
    }
</script>