<?php
/**
 * Тесты сценариев формата характеристик (type: color vs text, value / value_text).
 * Проверяет логику без Bitrix: при forceColorAsText или отсутствии hex — type text;
 * при наличии hex — type color, value = hex, value_text = текст.
 */
namespace Yastore\Checkout\Test;

use PHPUnit\Framework\TestCase;
use Yastore\Checkout\ColorMapHelper;

class CharacteristicsScenariosTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(ColorMapHelper::class)) {
            require_once dirname(__DIR__) . '/lib/ColorMapHelper.php';
        }
    }

    /**
     * Имитация логики getProductCharacteristics/getProductVariations для одного свойства «Цвет».
     */
    private static function buildColorCharacteristic(string $valueText, array $colorMap, bool $forceColorAsText): array
    {
        $hex = ColorMapHelper::getHexFromColorMapOrNull($colorMap, $valueText);
        $useTextForColor = $forceColorAsText || ($hex === null);
        if ($useTextForColor) {
            return ['code' => 'COLOR', 'name' => 'Цвет', 'value' => $valueText, 'type' => 'text'];
        }
        return [
            'code' => 'COLOR',
            'name' => 'Цвет',
            'value' => $hex,
            'value_text' => $valueText,
            'type' => 'color'
        ];
    }

    public function testForceColorAsTextAlwaysText(): void
    {
        $map = ['белый' => '#FFFFFF'];
        $ch = self::buildColorCharacteristic('белый', $map, true);
        $this->assertSame('text', $ch['type']);
        $this->assertSame('белый', $ch['value']);
        $this->assertArrayNotHasKey('value_text', $ch);
    }

    public function testMappedColorWithoutForceReturnsColor(): void
    {
        $map = ['белый' => '#FFFFFF'];
        $ch = self::buildColorCharacteristic('белый', $map, false);
        $this->assertSame('color', $ch['type']);
        $this->assertSame('#FFFFFF', $ch['value']);
        $this->assertSame('белый', $ch['value_text']);
    }

    public function testUnmappedColorWithoutForceReturnsText(): void
    {
        $map = ['белый' => '#FFFFFF'];
        $ch = self::buildColorCharacteristic('серый', $map, false);
        $this->assertSame('text', $ch['type']);
        $this->assertSame('серый', $ch['value']);
        $this->assertArrayNotHasKey('value_text', $ch);
    }

    public function testEmptyMapReturnsText(): void
    {
        $ch = self::buildColorCharacteristic('белый', [], false);
        $this->assertSame('text', $ch['type']);
        $this->assertSame('белый', $ch['value']);
    }
}
