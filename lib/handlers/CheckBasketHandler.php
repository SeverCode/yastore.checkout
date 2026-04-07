<?php
namespace Yastore\Checkout\Handlers;

use Bitrix\Main\Loader;
use Bitrix\Main\Web\Json;
use Bitrix\Main\Config\Option;
use Yastore\Checkout\ColorMapHelper;
use Yastore\Checkout\ProductIdResolver;
use Bitrix\Iblock\ElementTable;
use Bitrix\Catalog\ProductTable;
use Bitrix\Catalog\StoreProductTable;
use Bitrix\Catalog\StoreTable;
use Bitrix\Catalog\PriceTable;
use Bitrix\Catalog\Model\Price;
use Bitrix\Sale\Configuration;

class CheckBasketHandler extends BaseHandler
{
    public function handle($orderId = null)
    {
        try {
            if (!Loader::includeModule('catalog') || !Loader::includeModule('iblock')) {
                $this->sendError('Required modules not available', 500);
                return;
            }

            $input = file_get_contents('php://input');
            $requestData = Json::decode($input);

            if (empty($requestData['items']) || !is_array($requestData['items'])) {
                $this->sendError('Invalid request format: items array is required', 400);
                return;
            }

            $warehouseId = isset($requestData['warehouse_id']) ? $requestData['warehouse_id'] : null;

            $responseItems = [];
            $notFoundItems = [];
            $notFoundDebug = null;

            foreach ($requestData['items'] as $requestItem) {
                if (empty($requestItem['id'])) {
                    continue;
                }
                $requestedQuantity = isset($requestItem['quantity']) && (string) $requestItem['quantity'] !== '' ? intval($requestItem['quantity']) : 1;
                if ($requestedQuantity < 1) {
                    $requestedQuantity = 1;
                }

                $externalId = $requestItem['id'];

                $productId = ProductIdResolver::resolveToInternalId($externalId);
                if ($productId === null) {
                    $notFoundItems[] = $externalId;
                    if ($notFoundDebug === null) {
                        $notFoundDebug = ProductIdResolver::getLastDebug();
                    }
                    continue;
                }

                $element = ElementTable::getList([
                    'filter' => ['ID' => $productId, 'ACTIVE' => 'Y'],
                    'select' => ['ID', 'IBLOCK_ID', 'NAME', 'DETAIL_PICTURE', 'PREVIEW_PICTURE'],
                    'limit' => 1
                ])->fetch();

                if (!$element) {
                    $notFoundItems[] = $externalId;
                    continue;
                }

                $product = ProductTable::getList([
                    'filter' => ['ID' => $productId],
                    'select' => ['ID', 'WIDTH', 'HEIGHT', 'LENGTH', 'WEIGHT',]
                ])->fetch();

                $priceData = $this->getProductPrice($productId);
                $warehouses = $this->getWarehouseAvailability($productId, $warehouseId);
                $imageUrl = $this->getProductImage($element);
                $productUrl = $this->getProductUrl($element);
                $responseItem = [
                    'id' => ProductIdResolver::getExternalId($productId),
                    'name' => $element['NAME'],
                    'regular_price' => $priceData['regular_price'],
                    'final_price' => $priceData['final_price'],
                    'warehouses' => $warehouses
                ];

                if ($imageUrl) {
                    $responseItem['img'] = $imageUrl;
                }

                if ($productUrl) {
                    $responseItem['url'] = $productUrl;
                }

                if ($product && ($product['WIDTH'] || $product['HEIGHT'] || $product['LENGTH'] || $product['WEIGHT'])) {
                    $responseItem['dimensions'] = [
                        'width' => (int)$product['WIDTH'] ?: 0,
                        'height' => (int)$product['HEIGHT'] ?: 0,
                        'depth' => (int)$product['LENGTH'] ?: 0,
                        'weight' => (int)$product['WEIGHT'] ?: 0
                    ];
                }

                // Характеристики и вариации только при включённой опции и выбранном инфоблоке торговых предложений
                $useSku = Option::get('yastore.checkout', 'USE_SKU', 'N');
                $skuIblockId = Option::get('yastore.checkout', 'SKU_IBLOCK_ID', '');
                if ($useSku === 'Y' && $skuIblockId !== '') {
                    $colorMap = $this->getColorMap();
                    $forceColorAsText = false;
                    $variations = $this->getProductVariations($element['ID'], $element['IBLOCK_ID'], $warehouseId, $forceColorAsText, $colorMap, $forceColorAsText);
                    if (!empty($variations)) {
                        $responseItem['variations'] = $variations;
                    }
                    // forceColorAsText вычислен внутри getProductVariations по тем же офферам, что в ответе
                    $characteristics = $this->getProductCharacteristics($element['ID'], $element['IBLOCK_ID'], $colorMap, $forceColorAsText);
                    if (!empty($characteristics)) {
                        $responseItem['characteristics'] = $characteristics;
                    }
                }

                $responseItems[] = $responseItem;
            }

            if (!empty($notFoundItems)) {
                $msg = 'Products not found: ' . implode(', ', $notFoundItems);
                if ($this->request->get('debug') === '1' || $this->request->getHeader('X-Debug') === '1') {
                    $this->sendErrorWithData($msg, 404, self::ERROR_NOT_FOUND, [
                        'debug' => $notFoundDebug !== null ? $notFoundDebug : ProductIdResolver::getLastDebug(),
                    ]);
                } else {
                    $this->sendError($msg, 404);
                }
                return;
            }

            $this->sendResponse(['items' => $responseItems]);

        } catch (\Exception $e) {
            $this->sendError('Failed to check basket: ' . $e->getMessage(), 500);
        }
    }

    private function getProductPrice($productId)
    {
        \Bitrix\Main\Loader::includeModule('catalog');
        \Bitrix\Main\Loader::includeModule('sale');
        $optimalPrice = \CCatalogProduct::GetOptimalPrice(
            $productId,
            1,
            [],
            'N',
            [],
            \Bitrix\Main\Context::getCurrent()->getSite()
        );

        $regularPrice = 0;
        $finalPrice = 0;

        if ($optimalPrice && isset($optimalPrice['RESULT_PRICE'])) {
            $regularPrice = floatval($optimalPrice['RESULT_PRICE']['BASE_PRICE']);
            $finalPrice = floatval($optimalPrice['RESULT_PRICE']['DISCOUNT_PRICE']);
        }
        
        return [
            'regular_price' => $regularPrice,
            'final_price' => $finalPrice
        ];
    }

    private function getWarehouseAvailability($productId, $warehouseId = null)
    {
        $warehouses = [];
        $isStoreControl = $this->isStoreControlEnabled();

        // Опция «Продавать все активные товары» — не проверять остатки; available_quantity из настройки «Количество товара по умолчанию»
        $sellWithoutStockCheck = Option::get('yastore.checkout', 'SELL_WITHOUT_STOCK_CHECK', 'N') === 'Y';
        if ($sellWithoutStockCheck) {
            $availableQty = max(1, (int) Option::get('yastore.checkout', 'DEFAULT_PRODUCT_QUANTITY', '1'));
            if ($this->useGeneralStockOnly()) {
                return [[
                    'id' => $this->getGeneralWarehouseForApi()['id'],
                    'available_quantity' => $availableQty,
                ]];
            }
            $warehouseIdForResponse = null;
            if ($warehouseId !== null && $warehouseId !== '') {
                $requestedStore = StoreTable::getList([
                    'filter' => ['ID' => $warehouseId, 'ACTIVE' => 'Y'],
                    'select' => ['ID'],
                    'limit' => 1
                ])->fetch();
                if ($requestedStore) {
                    $warehouseIdForResponse = (string)$requestedStore['ID'];
                }
            }
            if ($warehouseIdForResponse === null) {
                $firstWarehouse = StoreTable::getList([
                    'filter' => ['ACTIVE' => 'Y'],
                    'select' => ['ID'],
                    'order' => ['SORT' => 'ASC', 'ID' => 'ASC'],
                    'limit' => 1
                ])->fetch();
                $warehouseIdForResponse = $firstWarehouse ? (string)$firstWarehouse['ID'] : $this->getVirtualWarehouse()['id'];
            }
            return [['id' => $warehouseIdForResponse, 'available_quantity' => $availableQty]];
        }

        // Только общий остаток из каталога, в API — виртуальный склад id=1
        if ($this->useGeneralStockOnly()) {
            $product = ProductTable::getList([
                'filter' => ['ID' => $productId],
                'select' => ['ID', 'QUANTITY', 'QUANTITY_RESERVED'],
            ])->fetch();
            $availableQuantity = $product ? (float) $product['QUANTITY'] : 0;

            return [[
                'id' => $this->getGeneralWarehouseForApi()['id'],
                'available_quantity' => (int) $availableQuantity,
            ]];
        }

        // Получаем общий остаток товара
        $product = ProductTable::getList([
            'filter' => ['ID' => $productId],
            'select' => ['ID', 'QUANTITY', 'QUANTITY_RESERVED']
        ])->fetch();

        $totalQuantity = $product ? (float)$product['QUANTITY'] : 0;
        // Не вычитаем резервы - показываем общее количество
        $availableQuantity = $totalQuantity;

        // Проверяем наличие складов в системе
        $hasWarehouses = $this->hasWarehouses();
        
        // Проверяем наличие остатков по складам
        $hasStock = $this->hasWarehouseStock($productId);
        
        // При выключенном складском учёте сначала смотрим остатки по складам; если есть — отдаём их.
        if (!$isStoreControl) {
            $filterStore = ['PRODUCT_ID' => $productId];
            if ($warehouseId !== null) {
                $store = \Bitrix\Catalog\StoreTable::getList([
                    'filter' => ['ID' => $warehouseId],
                    'select' => ['ID'],
                    'limit' => 1
                ])->fetch();
                if (!$store) {
                    return [];
                }
                $filterStore['STORE_ID'] = $store['ID'];
            }
            $storeProducts = StoreProductTable::getList([
                'filter' => $filterStore,
                'select' => ['STORE_ID', 'AMOUNT', 'QUANTITY_RESERVED'],
            ]);
            $byStore = [];
            while ($row = $storeProducts->fetch()) {
                $store = \Bitrix\Catalog\StoreTable::getById($row['STORE_ID'])->fetch();
                if ($store && $store['ACTIVE'] === 'Y') {
                    $byStore[] = ['id' => (string)$store['ID'], 'available_quantity' => (int)$row['AMOUNT']];
                }
            }
            if (!empty($byStore)) {
                return $byStore;
            }
            // Нет остатков по складам — один склад с общим остатком (ProductTable.QUANTITY)
            $firstWarehouse = StoreTable::getList([
                'filter' => ['ACTIVE' => 'Y'],
                'select' => ['ID'],
                'order' => ['SORT' => 'ASC', 'ID' => 'ASC'],
                'limit' => 1
            ])->fetch();

            if ($firstWarehouse) {
                $warehouseIdForResponse = (string)$firstWarehouse['ID'];
            } else {
                $virtualWarehouse = $this->getVirtualWarehouse();
                $warehouseIdForResponse = $virtualWarehouse['id'];
            }

            return [[
                'id' => $warehouseIdForResponse,
                'available_quantity' => $availableQuantity
            ]];
        }

        // Складской учёт включен - работаем со складами
        // Если общий остаток > 0, но складов нет или остатков по складам нет - используем виртуальный склад
        if ($availableQuantity > 0 && (!$hasWarehouses || !$hasStock)) {
            $virtualWarehouse = $this->getVirtualWarehouse();
            return [[
                'id' => $virtualWarehouse['id'],
                'available_quantity' => $availableQuantity
            ]];
        }

        // Формируем фильтр
        $filter = ['PRODUCT_ID' => $productId];
        
        // Если указан конкретный склад - фильтруем по нему
        if ($warehouseId !== null) {
            // Ищем склад по ID
            $store = \Bitrix\Catalog\StoreTable::getList([
                'filter' => ['ID' => $warehouseId],
                'select' => ['ID'],
                'limit' => 1
            ])->fetch();
            
            if ($store) {
                $filter['STORE_ID'] = $store['ID'];
            } else {
                // Если склад не найден - возвращаем пустой массив
                return [];
            }
        }

        $storeProducts = StoreProductTable::getList([
            'filter' => $filter,
            'select' => ['STORE_ID', 'AMOUNT', 'QUANTITY_RESERVED'],
            'runtime' => [
                new \Bitrix\Main\Entity\ReferenceField(
                    'STORE',
                    '\Bitrix\Catalog\StoreTable',
                    ['=this.STORE_ID' => 'ref.ID'],
                    ['join_type' => 'inner']
                )
            ]
        ]);

        while ($storeProduct = $storeProducts->fetch()) {
            $store = \Bitrix\Catalog\StoreTable::getById($storeProduct['STORE_ID'])->fetch();
            
            if ($store && $store['ACTIVE'] === 'Y') {
                $amount = (int)$storeProduct['AMOUNT'];
                // Не вычитаем резервы - показываем общее количество
                $available = $amount;
                $warehouses[] = [
                    'id' => (string)$store['ID'],
                    'available_quantity' => $available
                ];
            }
        }

        return $warehouses;
    }

    private function getProductImage($element)
    {
        $imageId = $element['DETAIL_PICTURE'] ?: $element['PREVIEW_PICTURE'];
        
        // Если у элемента нет изображения, проверяем родительский товар (для торговых предложений)
        if (!$imageId) {
            $productInfo = \CCatalogSku::GetProductInfo($element['ID']);
            if ($productInfo && !empty($productInfo['ID'])) {
                $res = \CIBlockElement::GetList(
                    [],
                    ['ID' => $productInfo['ID']],
                    false,
                    false,
                    ['ID', 'PREVIEW_PICTURE', 'DETAIL_PICTURE']
                );
                if ($parentData = $res->GetNext()) {
                    $imageId = $parentData['DETAIL_PICTURE'] ?: $parentData['PREVIEW_PICTURE'];
                }
            }
        }
        
        if ($imageId) {
            $image = \CFile::GetFileArray($imageId);
            if ($image) {
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'];
                return $protocol . '://' . $host . $image['SRC'];
            }
        }

        return null;
    }

    private function getProductUrl($element)
    {
        $el = \CIBlockElement::GetByID($element['ID'])->GetNext();
        
        if ($el && $el['DETAIL_PAGE_URL']) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'];
            return $protocol . '://' . $host . $el['DETAIL_PAGE_URL'];
        }

        return null;
    }

    /**
     * Получает все вариации торгового предложения и их свойства.
     * По тем же офферам собирает все значения цвета и передаёт наружу forceColorAsText (по ссылке).
     *
     * @param int $skuId ID текущего торгового предложения
     * @param int $iblockId ID инфоблока
     * @param int|null $warehouseId ID склада
     * @param bool $forceColorAsText не используется; результат записывается в $forceColorAsTextOut
     * @param array|null $colorMap карта значение цвета → HEX
     * @param bool|null $forceColorAsTextOut по ссылке: «хоть один цвет не в маппинге» → true
     * @return array Массив вариаций
     */
    private function getProductVariations($skuId, $iblockId, $warehouseId = null, $forceColorAsText = false, array $colorMap = null, &$forceColorAsTextOut = null)
    {
        try {
            $productInfo = \CCatalogSku::GetProductInfo($skuId, $iblockId);
            if (!$productInfo || empty($productInfo['ID'])) {
                return [];
            }

            $baseProductId = $productInfo['ID'];
            $baseProductIblockId = $productInfo['IBLOCK_ID'];
            $skuInfo = \CCatalogSKU::GetInfoByProductIBlock($baseProductIblockId);
            if (!$skuInfo || empty($skuInfo['IBLOCK_ID'])) {
                return [];
            }

            $offersIblockId = $skuInfo['IBLOCK_ID'];
            $productPropertyId = $skuInfo['SKU_PROPERTY_ID'] ?? null;
            $colorPropertyId = Option::get('yastore.checkout', 'SKU_COLOR_PROPERTY', '');
            if ($colorMap === null) {
                $colorMap = $this->getColorMap();
            }

            // Собираем все значения цвета: основной элемент + все вариации (те же офферы, что в ответе)
            $allColorValues = [];
            if ($colorPropertyId !== '') {
                $propRes = \CIBlockProperty::GetByID($colorPropertyId);
                $colorProp = $propRes ? $propRes->GetNext() : null;
                if ($colorProp && !empty($colorProp['CODE'])) {
                    $colorCode = $colorProp['CODE'];
                    $colorPropType = $colorProp['PROPERTY_TYPE'] ?? '';
                    if ((int)$colorProp['IBLOCK_ID'] === (int)$iblockId) {
                        $el = \CIBlockElement::GetByID($skuId)->GetNextElement();
                        if ($el) {
                            $props = $el->GetProperties();
                            if (isset($props[$colorCode]['VALUE'])) {
                                $text = $this->resolvePropertyValueToText($props[$colorCode]['VALUE'], $colorPropType);
                                if ($text !== null && $text !== '') {
                                    $allColorValues[] = $text;
                                }
                            }
                        }
                    }
                }
            }

            $variationProperties = $this->getVariationProperties($offersIblockId, $productPropertyId);
            
            // Получаем все вариации базового товара
            $filter = [
                'IBLOCK_ID' => $offersIblockId,
                'ACTIVE' => 'Y',
                'ACTIVE_DATE' => 'Y'
            ];
            
            // Если есть свойство связи с товаром, фильтруем по нему
            if ($productPropertyId) {
                $filter['PROPERTY_' . $productPropertyId] = $baseProductId;
            }
            
            // Первый проход: собираем данные вариаций и все значения цвета
            $variationRows = [];
            $res = \CIBlockElement::GetList(
                ['SORT' => 'ASC', 'ID' => 'ASC'],
                $filter,
                false,
                false,
                ['ID', 'NAME', 'IBLOCK_ID', 'DETAIL_PICTURE', 'PREVIEW_PICTURE']
            );
            
            while ($variation = $res->GetNextElement()) {
                $variationFields = $variation->GetFields();
                $variationId = $variationFields['ID'];
                
                // Пропускаем текущий товар, так как он уже есть на уровне выше
                if ((string)$variationId === (string)$skuId) {
                    continue;
                }
                
                // Получаем свойства вариации
                $variationProps = $variation->GetProperties();
                
                // Формируем массив свойств, которыми отличаются вариации
                $properties = [];
                foreach ($variationProperties as $propCode => $propInfo) {
                    if (isset($variationProps[$propCode])) {
                        $propValue = $variationProps[$propCode];
                        $value = null;
                        
                        // Получаем значение свойства
                        if (isset($propValue['VALUE'])) {
                            if (is_array($propValue['VALUE'])) {
                                $value = $propValue['VALUE'];
                            } else {
                                $value = $propValue['VALUE'];
                            }
                        }
                        
                        // Пропускаем пустые значения
                        if (empty($value) && $value !== '0' && $value !== 0) {
                            continue;
                        }
                        
                        // Для списков и справочников получаем текстовое значение
                        if ($propInfo['PROPERTY_TYPE'] === 'L' || $propInfo['PROPERTY_TYPE'] === 'E') {
                            if (is_array($value)) {
                                $textValues = [];
                                foreach ($value as $val) {
                                    if (empty($val) && $val !== '0' && $val !== 0) {
                                        continue;
                                    }
                                    if ($propInfo['PROPERTY_TYPE'] === 'L') {
                                        // Для списка получаем значение из вариантов
                                        $enumRes = \CIBlockPropertyEnum::GetList(
                                            [],
                                            ['ID' => $val]
                                        );
                                        if ($enum = $enumRes->GetNext()) {
                                            $textValues[] = $enum['VALUE'];
                                        }
                                    } else {
                                        // Для привязки к элементам получаем название
                                        $elRes = \CIBlockElement::GetByID($val);
                                        if ($el = $elRes->GetNext()) {
                                            $textValues[] = $el['NAME'];
                                        }
                                    }
                                }
                                $value = !empty($textValues) ? (count($textValues) === 1 ? $textValues[0] : $textValues) : $value;
                            } else {
                                if ($propInfo['PROPERTY_TYPE'] === 'L') {
                                    $enumRes = \CIBlockPropertyEnum::GetList(
                                        [],
                                        ['ID' => $value]
                                    );
                                    if ($enum = $enumRes->GetNext()) {
                                        $value = $enum['VALUE'];
                                    }
                                } else {
                                    $elRes = \CIBlockElement::GetByID($value);
                                    if ($el = $elRes->GetNext()) {
                                        $value = $el['NAME'];
                                    }
                                }
                            }
                        }
                        
                        $properties[$propCode] = [
                            'code' => $propCode,
                            'name' => $propInfo['NAME'],
                            'value' => $value,
                            'property_id' => $propInfo['ID']
                        ];
                        if ($colorPropertyId !== '' && (string)$propInfo['ID'] === (string)$colorPropertyId && !is_array($value)) {
                            $allColorValues[] = (string)$value;
                        }
                    }
                }
                $variationRows[] = ['fields' => $variationFields, 'properties' => $properties];
            }

            $forceColorAsText = ColorMapHelper::hasUnmappedColorValues($allColorValues, $colorMap);
            if ($forceColorAsTextOut !== null) {
                $forceColorAsTextOut = $forceColorAsText;
            }

            $variations = [];
            foreach ($variationRows as $row) {
                $variationFields = $row['fields'];
                $properties = $row['properties'];
                $variationId = $variationFields['ID'];

                $characteristics = [];
                foreach ($properties as $prop) {
                    if (is_array($prop['value'])) {
                        continue;
                    }
                    $propId = isset($prop['property_id']) ? (string)$prop['property_id'] : '';
                    if ($colorPropertyId !== '' && $propId === (string)$colorPropertyId) {
                        $valueText = (string)$prop['value'];
                        $hex = ColorMapHelper::getHexFromColorMapOrNull($colorMap, $valueText);
                        $useTextForColor = $forceColorAsText || ($hex === null);
                        if ($useTextForColor) {
                            $characteristics[] = [
                                'code' => $prop['code'],
                                'name' => $prop['name'],
                                'value' => $prop['value'],
                                'type' => 'text'
                            ];
                        } else {
                            $characteristics[] = [
                                'code' => $prop['code'],
                                'name' => $prop['name'],
                                'value' => $hex,
                                'value_text' => $valueText,
                                'type' => 'color'
                            ];
                        }
                    } else {
                        $characteristics[] = [
                            'code' => $prop['code'],
                            'name' => $prop['name'],
                            'value' => $prop['value'],
                            'type' => 'text'
                        ];
                    }
                }

                $variationPriceData = $this->getProductPrice($variationId);
                $variationWarehouses = $this->getWarehouseAvailability($variationId, $warehouseId);
                $variationImageUrl = $this->getProductImage($variationFields);
                $variationUrl = $this->getProductUrl($variationFields);

                $variationData = [
                    'id' => (string)$variationId,
                    'name' => $variationFields['NAME'],
                    'regular_price' => $variationPriceData['regular_price'],
                    'final_price' => $variationPriceData['final_price'],
                    'warehouses' => $variationWarehouses
                ];
                if ($variationImageUrl) {
                    $variationData['img'] = $variationImageUrl;
                }
                if ($variationUrl) {
                    $variationData['url'] = $variationUrl;
                }
                if (!empty($characteristics)) {
                    $variationData['characteristics'] = $characteristics;
                }
                $variations[] = $variationData;
            }
            
            return $variations;
            
        } catch (\Exception $e) {
            \Bitrix\Main\Application::getInstance()->getExceptionHandler()->writeToLog($e);
            return [];
        }
    }

    /**
     * Получает характеристики товара в формате массива объектов
     *
     * @param int $elementId ID элемента
     * @param int $iblockId ID инфоблока
     * @param array|null $colorMap карта значение цвета → HEX (если null, загружается getColorMap())
     * @param bool $forceColorAsText если true, для свойства «Цвет» везде value как текст, type: text (без hex)
     * @return array Массив характеристик [['code' => ..., 'name' => ..., 'value' => ...], ...]
     */
    private function getProductCharacteristics($elementId, $iblockId, array $colorMap = null, $forceColorAsText = false)
    {
        $characteristics = [];
        
        try {
            // Настройки для свойства «Цвет»
            $colorPropertyId = Option::get('yastore.checkout', 'SKU_COLOR_PROPERTY', '');
            if ($colorMap === null) {
                $colorMap = $this->getColorMap();
            }
            
            // Получаем настройки модуля для фильтрации свойств
            $useSku = Option::get('yastore.checkout', 'USE_SKU', 'N');
            $skuPropertiesValue = Option::get('yastore.checkout', 'SKU_PROPERTIES', '');
            $allowedPropertyIds = [];
            
            if ($useSku === 'Y' && !empty($skuPropertiesValue)) {
                $unserialized = @unserialize($skuPropertiesValue);
                if (is_array($unserialized)) {
                    $allowedPropertyIds = array_map('intval', $unserialized);
                }
            }
            if ($useSku === 'Y' && empty($allowedPropertyIds)) {
                return [];
            }
            
            $res = \CIBlockElement::GetByID($elementId);
            if (!$element = $res->GetNextElement()) {
                return [];
            }
            
            $elementProps = $element->GetProperties();
            
            // Получаем список свойств инфоблока
            $propertyIterator = \CIBlockProperty::GetList(
                ['SORT' => 'ASC', 'ID' => 'ASC'],
                [
                    'IBLOCK_ID' => $iblockId,
                    'ACTIVE' => 'Y'
                ]
            );
            
            while ($prop = $propertyIterator->GetNext()) {
                // Если включена фильтрация по настройкам, проверяем, что свойство выбрано
                if (!empty($allowedPropertyIds) && !in_array((int)$prop['ID'], $allowedPropertyIds)) {
                    continue;
                }
                $propCode = strtoupper($prop['CODE'] ?? '');
                
                // Исключаем служебные свойства
                if (stripos($propCode, 'PHOTO') !== false) {
                    continue;
                }
                if (strpos($propCode, 'CML2_') === 0) {
                    continue;
                }
                if ($prop['PROPERTY_TYPE'] === 'F') {
                    continue;
                }
                
                // Получаем значение свойства элемента
                if (!isset($elementProps[$prop['CODE']])) {
                    continue;
                }
                
                $propValue = $elementProps[$prop['CODE']];
                $value = null;
                
                // Получаем значение свойства
                if (isset($propValue['VALUE'])) {
                    if (is_array($propValue['VALUE'])) {
                        $value = $propValue['VALUE'];
                    } else {
                        $value = $propValue['VALUE'];
                    }
                }
                
                // Пропускаем пустые значения
                if (empty($value) && $value !== '0' && $value !== 0) {
                    continue;
                }
                
                // Для списков и справочников получаем текстовое значение
                if ($prop['PROPERTY_TYPE'] === 'L' || $prop['PROPERTY_TYPE'] === 'E') {
                    if (is_array($value)) {
                        $textValues = [];
                        foreach ($value as $val) {
                            if (empty($val) && $val !== '0' && $val !== 0) {
                                continue;
                            }
                            if ($prop['PROPERTY_TYPE'] === 'L') {
                                $enumRes = \CIBlockPropertyEnum::GetList(
                                    [],
                                    ['ID' => $val]
                                );
                                if ($enum = $enumRes->GetNext()) {
                                    $textValues[] = $enum['VALUE'];
                                }
                            } else {
                                $elRes = \CIBlockElement::GetByID($val);
                                if ($el = $elRes->GetNext()) {
                                    $textValues[] = $el['NAME'];
                                }
                            }
                        }
                        $value = !empty($textValues) ? (count($textValues) === 1 ? $textValues[0] : $textValues) : $value;
                    } else {
                        if ($prop['PROPERTY_TYPE'] === 'L') {
                            $enumRes = \CIBlockPropertyEnum::GetList(
                                [],
                                ['ID' => $value]
                            );
                            if ($enum = $enumRes->GetNext()) {
                                $value = $enum['VALUE'];
                            }
                        } else {
                            $elRes = \CIBlockElement::GetByID($value);
                            if ($el = $elRes->GetNext()) {
                                $value = $el['NAME'];
                            }
                        }
                    }
                }
                
                // Пропускаем свойства с несколькими значениями (массив)
                if (is_array($value)) {
                    continue;
                }
                
                // Свойство «Цвет»: при forceColorAsText или отсутствии маппинга — value как текст, type: text
                if ($colorPropertyId !== '' && (string)$prop['ID'] === (string)$colorPropertyId) {
$valueText = (string)$value;
                    $hex = ColorMapHelper::getHexFromColorMapOrNull($colorMap, $valueText);
                    $useTextForColor = $forceColorAsText || ($hex === null);
                    if ($useTextForColor) {
                        $characteristics[] = [
                            'code' => $prop['CODE'],
                            'name' => $prop['NAME'],
                            'value' => $value,
                            'type' => 'text'
                        ];
                    } else {
                        $characteristics[] = [
                            'code' => $prop['CODE'],
                            'name' => $prop['NAME'],
                            'value' => $hex,
                            'value_text' => $valueText,
                            'type' => 'color'
                        ];
                    }
                } else {
                    $characteristics[] = [
                        'code' => $prop['CODE'],
                        'name' => $prop['NAME'],
                        'value' => $value,
                        'type' => 'text'
                    ];
                }
            }
        } catch (\Exception $e) {
            \Bitrix\Main\Application::getInstance()->getExceptionHandler()->writeToLog($e);
        }

        return $characteristics;
    }

    /**
     * Возвращает карту соответствия «значение цвета» → HEX только из сохранённых настроек (SKU_COLOR_MAP).
     * Пресет в админке не подставляется — в API только то, что выбрал пользователь.
     *
     * @return array [ valueText => hex, ... ]
     */
    private function getColorMap()
    {
        $colorMapJson = Option::get('yastore.checkout', 'SKU_COLOR_MAP', '');
        if ($colorMapJson === '') {
            return [];
        }
        $colorMap = is_string($colorMapJson) ? (json_decode($colorMapJson, true) ?: []) : [];
        if (!is_array($colorMap) || empty($colorMap)) {
            return [];
        }
        return ColorMapHelper::normalizeColorMapKeys($colorMap);
    }

    /**
     * Проверяет, есть ли хотя бы одно значение цвета (у основного товара или у вариаций), которого нет в маппинге.
     *
     * @param int $elementId ID элемента (товара или SKU)
     * @param int $iblockId ID инфоблока элемента
     * @param int|null $warehouseId не используется, для совместимости с getProductVariations
     * @param array $colorMap карта значение → HEX
     * @return bool true — если маппинг пустой или хотя бы один цвет не в маппинге
     */
    private function hasUnmappedColorValue($elementId, $iblockId, $warehouseId, array $colorMap)
    {
        $colorPropertyId = Option::get('yastore.checkout', 'SKU_COLOR_PROPERTY', '');
        if ($colorPropertyId === '') {
            return false;
        }
        $values = $this->getAllColorValuesForItem($elementId, $iblockId, $colorPropertyId);
        return ColorMapHelper::hasUnmappedColorValues($values, $colorMap);
    }

    /**
     * Собирает все текстовые значения свойства «Цвет» у основного товара и у всех вариаций.
     *
     * @param int $elementId ID элемента
     * @param int $iblockId ID инфоблока элемента
     * @param string $colorPropertyId ID свойства «Цвет»
     * @return string[]
     */
    private function getAllColorValuesForItem($elementId, $iblockId, $colorPropertyId)
    {
        $result = [];
        $propRes = \CIBlockProperty::GetByID($colorPropertyId);
        if (!($colorProp = $propRes->GetNext()) || empty($colorProp['CODE'])) {
            return $result;
        }
        $colorCode = $colorProp['CODE'];
        $colorPropType = $colorProp['PROPERTY_TYPE'] ?? '';

        // Значение у основного элемента
        if ((int)$colorProp['IBLOCK_ID'] === (int)$iblockId) {
            $res = \CIBlockElement::GetByID($elementId);
            if ($el = $res->GetNextElement()) {
                $props = $el->GetProperties();
                if (isset($props[$colorCode]['VALUE'])) {
                    $val = $props[$colorCode]['VALUE'];
                    $text = $this->resolvePropertyValueToText($val, $colorPropType);
                    if ($text !== null && $text !== '') {
                        $result[] = $text;
                    }
                }
            }
        }

        // Значения у вариаций: получаем родительский товар (если элемент — оффер) или считаем элемент основным товаром
        $productInfo = \CCatalogSku::GetProductInfo($elementId, $iblockId);
        if (!$productInfo || empty($productInfo['ID'])) {
            // Fallback: GetProductInfo может возвращать пустой результат (оффер в части версий Bitrix или основной товар)
            $catalogInfo = \CCatalogSku::GetInfoByIBlock($iblockId);
            if (!$catalogInfo || !is_array($catalogInfo)) {
                return $result;
            }
            $productIblockId = isset($catalogInfo['PRODUCT_IBLOCK_ID']) ? (int)$catalogInfo['PRODUCT_IBLOCK_ID'] : 0;
            $offersIblockIdFromCatalog = isset($catalogInfo['IBLOCK_ID']) ? (int)$catalogInfo['IBLOCK_ID'] : 0;
            $skuPropertyId = isset($catalogInfo['SKU_PROPERTY_ID']) ? (int)$catalogInfo['SKU_PROPERTY_ID'] : 0;
            // Текущий элемент в инфоблоке офферов — получаем ID основного товара из свойства связи
            if ($offersIblockIdFromCatalog > 0 && (int)$iblockId === $offersIblockIdFromCatalog && $skuPropertyId > 0) {
                $linkPropRes = \CIBlockProperty::GetByID($skuPropertyId);
                $linkProp = $linkPropRes ? $linkPropRes->GetNext() : null;
                if ($linkProp && !empty($linkProp['CODE'])) {
                    $el = \CIBlockElement::GetByID($elementId)->GetNextElement();
                    if ($el) {
                        $props = $el->GetProperties();
                        if (isset($props[$linkProp['CODE']]['VALUE'])) {
                            $linkVal = $props[$linkProp['CODE']]['VALUE'];
                            $productId = is_array($linkVal) ? (int)reset($linkVal) : (int)$linkVal;
                            if ($productId > 0 && $productIblockId > 0) {
                                $productInfo = ['ID' => $productId, 'IBLOCK_ID' => $productIblockId];
                            }
                        }
                    }
                }
            }
            // Текущий элемент — основной товар (инфоблок каталога товаров)
            if ((!$productInfo || empty($productInfo['ID'])) && $productIblockId > 0 && (int)$iblockId === $productIblockId) {
                $productInfo = ['ID' => $elementId, 'IBLOCK_ID' => $iblockId];
            }
            if (!$productInfo || empty($productInfo['ID'])) {
                return $result;
            }
        }
        $skuInfo = \CCatalogSKU::GetInfoByProductIBlock($productInfo['IBLOCK_ID']);
        if (!$skuInfo || empty($skuInfo['IBLOCK_ID'])) {
            return $result;
        }
        $offersIblockId = $skuInfo['IBLOCK_ID'];
        $productPropertyId = $skuInfo['SKU_PROPERTY_ID'] ?? null;
        // Свойство «Цвет» может не входить в список свойств вариаций; проверяем, что оно из инфоблока офферов
        if ((int)$colorProp['IBLOCK_ID'] !== (int)$offersIblockId) {
            return $result;
        }
        $filter = [
            'IBLOCK_ID' => $offersIblockId,
            'ACTIVE' => 'Y',
            'ACTIVE_DATE' => 'Y'
        ];
        if ($productPropertyId) {
            $filter['PROPERTY_' . $productPropertyId] = $productInfo['ID'];
        }
        $res = \CIBlockElement::GetList(
            ['SORT' => 'ASC', 'ID' => 'ASC'],
            $filter,
            false,
            false,
            ['ID']
        );
        // Собираем цвет у каждой вариации (включая текущий элемент, если он в списке офферов)
        while ($variation = $res->GetNextElement()) {
            $vProps = $variation->GetProperties();
            if (!isset($vProps[$colorCode]['VALUE'])) {
                continue;
            }
            $val = $vProps[$colorCode]['VALUE'];
            $text = $this->resolvePropertyValueToText($val, $colorPropType);
            if ($text !== null && $text !== '') {
                $result[] = $text;
            }
        }
        return $result;
    }

    /**
     * Преобразует значение свойства в текст (для списка и привязки к элементам).
     *
     * @param mixed $value
     * @param string $propertyType L|E|S|N
     * @return string|null
     */
    private function resolvePropertyValueToText($value, $propertyType)
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_array($value)) {
            $value = reset($value);
        }
        if ($propertyType === 'L') {
            $enumRes = \CIBlockPropertyEnum::GetList([], ['ID' => $value]);
            if ($enum = $enumRes->GetNext()) {
                return (string)$enum['VALUE'];
            }
        }
        if ($propertyType === 'E') {
            $elRes = \CIBlockElement::GetByID($value);
            if ($el = $elRes->GetNext()) {
                return (string)$el['NAME'];
            }
        }
        return (string)$value;
    }

    /**
     * Получает свойства, которыми отличаются вариации торгового предложения
     * 
     * @param int $offersIblockId ID инфоблока торговых предложений
     * @param int|null $productPropertyId ID свойства связи с товаром (исключаем из списка)
     * @return array Массив свойств [CODE => [NAME, PROPERTY_TYPE, ...]]
     */
    private function getVariationProperties($offersIblockId, $productPropertyId = null)
    {
        $properties = [];
        
        try {
            // Получаем настройки модуля для фильтрации свойств
            $useSku = Option::get('yastore.checkout', 'USE_SKU', 'N');
            $skuPropertiesValue = Option::get('yastore.checkout', 'SKU_PROPERTIES', '');
            $allowedPropertyIds = [];
            
            if ($useSku === 'Y' && !empty($skuPropertiesValue)) {
                $unserialized = @unserialize($skuPropertiesValue);
                if (is_array($unserialized)) {
                    $allowedPropertyIds = array_map('intval', $unserialized);
                }
            }
            if ($useSku === 'Y' && empty($allowedPropertyIds)) {
                return [];
            }
            
            $res = \CIBlockProperty::GetList(
                ['SORT' => 'ASC', 'ID' => 'ASC'],
                [
                    'IBLOCK_ID' => $offersIblockId,
                    'ACTIVE' => 'Y'
                ]
            );
            
            while ($prop = $res->GetNext()) {
                // Исключаем свойство связи с товаром
                if ($productPropertyId && $prop['ID'] == $productPropertyId) {
                    continue;
                }
                
                // Если включена фильтрация по настройкам, проверяем, что свойство выбрано
                if (!empty($allowedPropertyIds) && !in_array((int)$prop['ID'], $allowedPropertyIds)) {
                    continue;
                }
                
                $propCode = strtoupper($prop['CODE'] ?? '');
                
                // Исключаем свойства, содержащие PHOTO в коде
                if (stripos($propCode, 'PHOTO') !== false) {
                    continue;
                }
                
                // Исключаем свойства, начинающиеся с CML2_
                if (strpos($propCode, 'CML2_') === 0) {
                    continue;
                }
                
                // Исключаем свойства типа файл
                if ($prop['PROPERTY_TYPE'] === 'F') {
                    continue;
                }
                
                // Включаем только свойства, которые могут различаться у вариаций
                // Обычно это списки, справочники, строки, числа
                if (in_array($prop['PROPERTY_TYPE'], ['L', 'E', 'S', 'N'])) {
                    $properties[$prop['CODE']] = [
                        'ID' => $prop['ID'],
                        'CODE' => $prop['CODE'],
                        'NAME' => $prop['NAME'],
                        'PROPERTY_TYPE' => $prop['PROPERTY_TYPE']
                    ];
                }
            }
        } catch (\Exception $e) {
            \Bitrix\Main\Application::getInstance()->getExceptionHandler()->writeToLog($e);
        }
        
        return $properties;
    }
}

