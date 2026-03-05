<?php
/**
 * Тесты сценария сохранения SKU_COLOR_MAP в options.php:
 * сохранять маппинг только когда декодированный JSON — непустой массив.
 */
namespace Yastore\Checkout\Test;

use PHPUnit\Framework\TestCase;

class OptionsColorMapTest extends TestCase
{
    /**
     * Логика из options.php: сохранять SKU_COLOR_MAP только при непустом декодированном маппинге.
     */
    public static function shouldSaveColorMap($postValue): bool
    {
        $decoded = is_string($postValue) ? json_decode($postValue, true) : null;
        return is_array($decoded) && !empty($decoded);
    }

    public function testShouldSaveColorMapWhenNonEmptyJson(): void
    {
        $this->assertTrue(self::shouldSaveColorMap('{"белый":"#FFF"}'));
        $this->assertTrue(self::shouldSaveColorMap('{"red":"#F00","green":"#0F0"}'));
    }

    public function testShouldNotSaveColorMapWhenEmptyJson(): void
    {
        $this->assertFalse(self::shouldSaveColorMap('{}'));
        $this->assertFalse(self::shouldSaveColorMap(''));
    }

    public function testShouldNotSaveColorMapWhenInvalidJson(): void
    {
        $this->assertFalse(self::shouldSaveColorMap('not json'));
        $this->assertFalse(self::shouldSaveColorMap('[]'));
    }

    public function testShouldNotSaveColorMapWhenNull(): void
    {
        $this->assertFalse(self::shouldSaveColorMap(null));
    }
}
