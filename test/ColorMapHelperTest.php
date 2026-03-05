<?php
/**
 * Тесты для Yastore\Checkout\ColorMapHelper: карта цветов, нормализация, проверка «все в маппинге».
 */
namespace Yastore\Checkout\Test;

use PHPUnit\Framework\TestCase;
use Yastore\Checkout\ColorMapHelper;

class ColorMapHelperTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(ColorMapHelper::class)) {
            require_once dirname(__DIR__) . '/lib/ColorMapHelper.php';
        }
    }

    // --- normalizeColorKey ---

    public function testNormalizeColorKeyTrim(): void
    {
        $this->assertSame('белый', ColorMapHelper::normalizeColorKey('  белый  '));
        $this->assertSame('', ColorMapHelper::normalizeColorKey('   '));
    }

    public function testNormalizeColorKeyYoToE(): void
    {
        $this->assertSame('черный', ColorMapHelper::normalizeColorKey('чёрный'));
        $this->assertSame('зеленый', ColorMapHelper::normalizeColorKey('зелёный'));
        $this->assertSame('Елка', ColorMapHelper::normalizeColorKey('Ёлка'));
    }

    public function testNormalizeColorKeyEmptyAndString(): void
    {
        $this->assertSame('', ColorMapHelper::normalizeColorKey(''));
        $this->assertSame('red', ColorMapHelper::normalizeColorKey('red'));
    }

    // --- getHexFromColorMapOrNull ---

    public function testGetHexFromColorMapOrNullEmptyMap(): void
    {
        $this->assertNull(ColorMapHelper::getHexFromColorMapOrNull([], 'белый'));
    }

    public function testGetHexFromColorMapOrNullExactMatch(): void
    {
        $map = ['белый' => '#FFFFFF', 'черный' => '000000'];
        $this->assertSame('#FFFFFF', ColorMapHelper::getHexFromColorMapOrNull($map, 'белый'));
        $this->assertSame('#000000', ColorMapHelper::getHexFromColorMapOrNull($map, 'черный'));
    }

    public function testGetHexFromColorMapOrNullHexWithOrWithoutHash(): void
    {
        $map = ['red' => 'FF0000', 'green' => '#00FF00'];
        $this->assertSame('#FF0000', ColorMapHelper::getHexFromColorMapOrNull($map, 'red'));
        $this->assertSame('#00FF00', ColorMapHelper::getHexFromColorMapOrNull($map, 'green'));
    }

    public function testGetHexFromColorMapOrNullNormalizedKey(): void
    {
        $map = ['чёрный' => '#000000'];
        $this->assertSame('#000000', ColorMapHelper::getHexFromColorMapOrNull($map, 'чёрный'));
        $mapNorm = ColorMapHelper::normalizeColorMapKeys($map);
        $this->assertSame('#000000', ColorMapHelper::getHexFromColorMapOrNull($mapNorm, 'черный'));
    }

    public function testGetHexFromColorMapOrNullCaseInsensitive(): void
    {
        $map = ['White' => '#FFFFFF'];
        $this->assertSame('#FFFFFF', ColorMapHelper::getHexFromColorMapOrNull($map, 'white'));
        $this->assertSame('#FFFFFF', ColorMapHelper::getHexFromColorMapOrNull($map, 'WHITE'));
    }

    public function testGetHexFromColorMapOrNullValueNotInMap(): void
    {
        $map = ['белый' => '#FFFFFF'];
        $this->assertNull(ColorMapHelper::getHexFromColorMapOrNull($map, 'серый'));
        $this->assertNull(ColorMapHelper::getHexFromColorMapOrNull($map, ''));
    }

    public function testGetHexFromColorMapOrNullEmptyHexReturnsNull(): void
    {
        $map = ['белый' => ''];
        $this->assertNull(ColorMapHelper::getHexFromColorMapOrNull($map, 'белый'));
    }

    // --- normalizeColorMapKeys ---

    public function testNormalizeColorMapKeysAddsNormalized(): void
    {
        $map = ['чёрный' => '#000000'];
        $result = ColorMapHelper::normalizeColorMapKeys($map);
        $this->assertArrayHasKey('чёрный', $result);
        $this->assertArrayHasKey('черный', $result);
        $this->assertSame('#000000', $result['черный']);
    }

    public function testNormalizeColorMapKeysDoesNotOverwrite(): void
    {
        $map = ['черный' => '#111', 'чёрный' => '#222'];
        $result = ColorMapHelper::normalizeColorMapKeys($map);
        $this->assertSame('#111', $result['черный']);
        $this->assertSame('#222', $result['чёрный']);
    }

    // --- hasUnmappedColorValues ---

    public function testHasUnmappedColorValuesEmptyMap(): void
    {
        $this->assertTrue(ColorMapHelper::hasUnmappedColorValues(['белый'], []));
        $this->assertTrue(ColorMapHelper::hasUnmappedColorValues([], []));
    }

    public function testHasUnmappedColorValuesAllMapped(): void
    {
        $map = ['белый' => '#FFF', 'черный' => '#000'];
        $this->assertFalse(ColorMapHelper::hasUnmappedColorValues(['белый', 'черный'], $map));
        $this->assertFalse(ColorMapHelper::hasUnmappedColorValues(['белый'], $map));
    }

    public function testHasUnmappedColorValuesOneUnmapped(): void
    {
        $map = ['белый' => '#FFF'];
        $this->assertTrue(ColorMapHelper::hasUnmappedColorValues(['белый', 'серый'], $map));
        $this->assertTrue(ColorMapHelper::hasUnmappedColorValues(['серый'], $map));
    }

    public function testHasUnmappedColorValuesEmptyValues(): void
    {
        $map = ['белый' => '#FFF'];
        $this->assertFalse(ColorMapHelper::hasUnmappedColorValues([], $map));
    }
}
