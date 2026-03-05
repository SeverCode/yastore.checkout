<?php
namespace Yastore\Checkout;

/**
 * Хелпер для работы с картой цветов (значение → HEX).
 * Вся чистая логика вынесена сюда для возможности unit-тестов без Bitrix.
 */
class ColorMapHelper
{
    /**
     * Нормализует строку для сопоставления цвета (ё → е, trim).
     *
     * @param string $s
     * @return string
     */
    public static function normalizeColorKey($s)
    {
        $s = trim((string)$s);
        $s = str_replace(['ё', 'Ё'], ['е', 'Е'], $s);
        return $s;
    }

    /**
     * Возвращает HEX для значения цвета из карты или null, если значения нет в маппинге.
     *
     * @param array $colorMap
     * @param string $valueText
     * @return string|null
     */
    public static function getHexFromColorMapOrNull(array $colorMap, $valueText)
    {
        if (empty($colorMap)) {
            return null;
        }
        $valueText = (string)$valueText;
        $norm = self::normalizeColorKey($valueText);
        $valueLower = mb_strtolower($valueText, 'UTF-8');
        $normLower = mb_strtolower($norm, 'UTF-8');

        if (isset($colorMap[$valueText])) {
            $hex = (string)$colorMap[$valueText];
            return $hex !== '' ? (strpos($hex, '#') === 0 ? $hex : '#' . $hex) : null;
        }
        if (isset($colorMap[$norm])) {
            $hex = (string)$colorMap[$norm];
            return $hex !== '' ? (strpos($hex, '#') === 0 ? $hex : '#' . $hex) : null;
        }
        if (isset($colorMap[$valueLower])) {
            $hex = (string)$colorMap[$valueLower];
            return $hex !== '' ? (strpos($hex, '#') === 0 ? $hex : '#' . $hex) : null;
        }
        if (isset($colorMap[$normLower])) {
            $hex = (string)$colorMap[$normLower];
            return $hex !== '' ? (strpos($hex, '#') === 0 ? $hex : '#' . $hex) : null;
        }
        foreach ($colorMap as $key => $hex) {
            if (mb_strtolower((string)$key, 'UTF-8') === $valueLower) {
                $hex = (string)$hex;
                return $hex !== '' ? (strpos($hex, '#') === 0 ? $hex : '#' . $hex) : null;
            }
        }
        return null;
    }

    /**
     * Добавляет в карту нормализованные ключи (ё→е), чтобы подходили оба варианта написания.
     *
     * @param array $colorMap [ valueText => hex ]
     * @return array
     */
    public static function normalizeColorMapKeys(array $colorMap)
    {
        $result = $colorMap;
        foreach ($colorMap as $key => $hex) {
            $norm = self::normalizeColorKey($key);
            if ($norm !== $key && !isset($result[$norm])) {
                $result[$norm] = $hex;
            }
        }
        return $result;
    }

    /**
     * Проверяет, есть ли хотя бы одно значение цвета, которого нет в маппинге.
     *
     * @param string[] $colorValues список текстовых значений цвета
     * @param array $colorMap карта значение → HEX
     * @return bool true — если маппинг пустой или хотя бы один цвет не в маппинге
     */
    public static function hasUnmappedColorValues(array $colorValues, array $colorMap)
    {
        if (empty($colorMap)) {
            return true;
        }
        foreach ($colorValues as $textValue) {
            if (self::getHexFromColorMapOrNull($colorMap, $textValue) === null) {
                return true;
            }
        }
        return false;
    }
}
