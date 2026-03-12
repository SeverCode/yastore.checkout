<?php
namespace Yastore\Checkout;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Iblock\ElementTable;

/**
 * Резолв внешнего идентификатора товара (id из API) во внутренний ID элемента инфоблока и обратно.
 * Поддерживается смешанный каталог: при включённых ТП поиск выполняется сначала в инфоблоке ТП, затем в инфоблоке товаров.
 */
class ProductIdResolver
{
    const MODULE_ID = 'yastore.checkout';
    const FIELD_ID = 'ID';
    const FIELD_XML_ID = 'XML_ID';
    const FIELD_CODE = 'CODE';
    const FIELD_PROPERTY = 'PROPERTY';

    /** Последняя отладка resolveToInternalId (заполняется при вызове, сбрасывается в getLastDebug) */
    private static $lastDebug = [];

    /**
     * Список инфоблоков для поиска: только из настроек (при USE_SKU — ТП + инфоблок товаров).
     *
     * @return int[]
     */
    private static function getSearchIblockIds()
    {
        $productIblockId = (int) Option::get(self::MODULE_ID, 'YAKIT_PRODUCT_IBLOCK_ID', 0);
        $useSku = Option::get(self::MODULE_ID, 'USE_SKU', 'N') === 'Y';
        $skuIblockId = (int) Option::get(self::MODULE_ID, 'SKU_IBLOCK_ID', 0);

        $ids = [];
        if ($useSku && $skuIblockId > 0) {
            $ids[] = $skuIblockId;
        }
        if ($productIblockId > 0 && !in_array($productIblockId, $ids, true)) {
            $ids[] = $productIblockId;
        }
        return $ids;
    }

    /**
     * Преобразует идентификатор из запроса (внешний) во внутренний ID элемента.
     *
     * @param string|int $externalId Значение из items[].id в запросе
     * @return int|null Внутренний ID элемента или null, если не найден
     */
    public static function resolveToInternalId($externalId)
    {
        self::$lastDebug = ['externalId' => $externalId, 'steps' => []];

        if ($externalId === '' || $externalId === null) {
            self::$lastDebug['steps'][] = 'early_return: empty';
            return null;
        }

        $fieldRaw = Option::get(self::MODULE_ID, 'YAKIT_PRODUCT_ID_FIELD', self::FIELD_ID);
        $field = trim((string) $fieldRaw);
        if (!in_array($field, [self::FIELD_ID, self::FIELD_XML_ID, self::FIELD_CODE, self::FIELD_PROPERTY], true)) {
            $field = self::FIELD_ID;
        }
        self::$lastDebug['field'] = $field;
        self::$lastDebug['field_raw_from_db'] = $fieldRaw; // что реально в опции (для проверки сохранения)
        self::$lastDebug['YAKIT_PRODUCT_IBLOCK_ID_raw'] = Option::get(self::MODULE_ID, 'YAKIT_PRODUCT_IBLOCK_ID', '');
        self::$lastDebug['YAKIT_PRODUCT_ID_PROPERTY_raw'] = Option::get(self::MODULE_ID, 'YAKIT_PRODUCT_ID_PROPERTY', '');

        if ($field === self::FIELD_ID) {
            $str = trim((string) $externalId);
            if ($str === '' || (string)(int) $str !== $str) {
                self::$lastDebug['steps'][] = 'ID_mode: rejected (not strict integer)';
                return null;
            }
            $id = (int) $str;
            if ($id <= 0) {
                self::$lastDebug['steps'][] = 'ID_mode: id<=0';
                return null;
            }
            if (!Loader::includeModule('iblock')) {
                return $id;
            }
            $el = ElementTable::getById($id)->fetch();
            self::$lastDebug['steps'][] = 'ID_mode: element ' . ($el ? 'found' : 'not found');
            self::$lastDebug['note'] = 'Search by internal ID only. No property/XML_ID/CODE used.';
            return $el ? (int) $el['ID'] : null;
        }

        $iblockIds = self::getSearchIblockIds();
        self::$lastDebug['iblockIds'] = $iblockIds;
        if (empty($iblockIds)) {
            self::$lastDebug['steps'][] = 'return: empty iblockIds';
            return null;
        }
        if (!Loader::includeModule('iblock')) {
            self::$lastDebug['steps'][] = 'return: iblock module not loaded';
            return null;
        }

        $value = trim((string) $externalId);
        if ($value === '') {
            self::$lastDebug['steps'][] = 'return: empty value';
            return null;
        }

        self::$lastDebug['value'] = $value;
        self::$lastDebug['perIblock'] = [];
        foreach ($iblockIds as $iblockId) {
            $result = self::resolveInIblock($iblockId, $field, $value);
            self::$lastDebug['perIblock'][$iblockId] = $result !== null ? 'found:' . $result : 'not_found';
            if ($result !== null) {
                self::$lastDebug['note'] = 'Search by field only (no fallback to ID). Match was in iblock ' . $iblockId;
                return $result;
            }
        }

        self::$lastDebug['steps'][] = 'return: not found in any iblock';
        self::$lastDebug['note'] = 'Only one search mode is used (field). No fallback to ID when field is PROPERTY/XML_ID/CODE.';
        return null;
    }

    /**
     * Возвращает и очищает последнюю отладку (для ответа API при ?debug=1).
     * @return array
     */
    public static function getLastDebug()
    {
        $d = self::$lastDebug;
        self::$lastDebug = [];
        return $d;
    }

    /**
     * Поиск по одному инфоблоку.
     *
     * @param int $iblockId
     * @param string $field
     * @param string $value
     * @return int|null
     */
    private static function resolveInIblock($iblockId, $field, $value)
    {
        $filter = ['IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y'];

        if ($field === self::FIELD_XML_ID) {
            $filter['XML_ID'] = $value;
        } elseif ($field === self::FIELD_CODE) {
            $filter['CODE'] = $value;
        } elseif ($field === self::FIELD_PROPERTY) {
            $propCode = Option::get(self::MODULE_ID, 'YAKIT_PRODUCT_ID_PROPERTY', '');
            if ($propCode === '') {
                return null;
            }
            $filter['PROPERTY_' . $propCode] = $value;
        } else {
            return null;
        }

        if ($field === self::FIELD_PROPERTY) {
            $res = \CIBlockElement::GetList(
                ['ID' => 'ASC'],
                $filter,
                false,
                ['nTopCount' => 1],
                ['ID']
            );
            $row = $res ? $res->Fetch() : null;
            if (!$row && isset($filter['PROPERTY_' . $propCode])) {
                unset($filter['PROPERTY_' . $propCode]);
                $filter['PROPERTY_' . $propCode . '_VALUE'] = $value;
                $res = \CIBlockElement::GetList(
                    ['ID' => 'ASC'],
                    $filter,
                    false,
                    ['nTopCount' => 1],
                    ['ID']
                );
                $row = $res ? $res->Fetch() : null;
            }
            // Строгая проверка: Bitrix-фильтр по свойству может давать нестрогое совпадение (LIKE и т.д.).
            // Возвращаем элемент только если значение свойства точно равно запрошенному.
            if ($row && !self::elementPropertyValueEquals($iblockId, (int) $row['ID'], $propCode, $value)) {
                $row = null;
            }
        } else {
            $row = ElementTable::getList([
                'filter' => $filter,
                'select' => ['ID'],
                'limit' => 1,
            ])->fetch();
        }

        return $row ? (int) $row['ID'] : null;
    }

    /**
     * Проверяет, что у элемента с заданным ID значение свойства с кодом $propCode в точности равно $expectedValue.
     * Используется для строгой проверки после поиска по свойству (избегаем ложных совпадений из-за LIKE в Bitrix).
     *
     * @param int $iblockId
     * @param int $elementId
     * @param string $propCode
     * @param string $expectedValue
     * @return bool
     */
    private static function elementPropertyValueEquals($iblockId, $elementId, $propCode, $expectedValue)
    {
        $propCode = trim((string) $propCode);
        if ($propCode === '') {
            return false;
        }
        $res = \CIBlockElement::GetProperty($iblockId, $elementId, [], ['CODE' => $propCode]);
        if (!$res) {
            return false;
        }
        while ($prop = $res->Fetch()) {
            $val = isset($prop['VALUE']) ? $prop['VALUE'] : '';
            if (is_array($val)) {
                $val = reset($val);
            }
            if ((string) $val === (string) $expectedValue) {
                return true;
            }
        }
        return false;
    }

    /**
     * Возвращает значение идентификатора для ответа API (внешний вид).
     * Элемент может быть из инфоблока товаров или ТП — загрузка по ID без привязки к инфоблоку.
     *
     * @param int $internalId Внутренний ID элемента
     * @return string Значение для поля id в ответе
     */
    public static function getExternalId($internalId)
    {
        $internalId = (int) $internalId;
        if ($internalId <= 0) {
            return '';
        }

        $field = Option::get(self::MODULE_ID, 'YAKIT_PRODUCT_ID_FIELD', self::FIELD_ID);
        if ($field === self::FIELD_ID) {
            return (string) $internalId;
        }

        if (!Loader::includeModule('iblock')) {
            return (string) $internalId;
        }

        $row = ElementTable::getList([
            'filter' => ['ID' => $internalId],
            'select' => ['ID', 'IBLOCK_ID', 'XML_ID', 'CODE'],
            'limit' => 1,
        ])->fetch();

        if (!$row) {
            return (string) $internalId;
        }

        if ($field === self::FIELD_XML_ID) {
            return (string) ($row['XML_ID'] ?? $internalId);
        }
        if ($field === self::FIELD_CODE) {
            return (string) ($row['CODE'] ?? $internalId);
        }
        if ($field === self::FIELD_PROPERTY) {
            $propCode = Option::get(self::MODULE_ID, 'YAKIT_PRODUCT_ID_PROPERTY', '');
            if ($propCode === '') {
                return (string) $internalId;
            }
            $res = \CIBlockElement::GetList(
                [],
                ['ID' => $internalId],
                false,
                false,
                ['ID', 'PROPERTY_' . $propCode]
            );
            $propRow = $res ? $res->Fetch() : null;
            if (!$propRow) {
                return (string) $internalId;
            }
            $v = $propRow['PROPERTY_' . $propCode . '_VALUE'] ?? $propRow['PROPERTY_' . $propCode] ?? $internalId;
            return (string) $v;
        }

        return (string) $internalId;
    }
}
