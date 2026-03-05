<?php
/**
 * Запуск тестов без PHPUnit (php test/run_tests.php из корня модуля).
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$moduleRoot = dirname(__DIR__);
require_once $moduleRoot . '/lib/ColorMapHelper.php';

use Yastore\Checkout\ColorMapHelper;

$failed = 0;
$passed = 0;

function assert_same($expected, $actual, string $msg): void {
    global $failed, $passed;
    if ($expected === $actual) {
        $passed++;
        echo "  OK: $msg\n";
    } else {
        $failed++;
        echo "  FAIL: $msg (expected " . json_encode($expected) . ", got " . json_encode($actual) . ")\n";
    }
}

function assert_true($value, string $msg): void {
    assert_same(true, $value, $msg);
}

function assert_false($value, string $msg): void {
    assert_same(false, $value, $msg);
}

function assert_null($value, string $msg): void {
    global $failed, $passed;
    if ($value === null) {
        $passed++;
        echo "  OK: $msg\n";
    } else {
        $failed++;
        echo "  FAIL: $msg (expected null, got " . json_encode($value) . ")\n";
    }
}

echo "=== ColorMapHelper: normalizeColorKey ===\n";
assert_same('белый', ColorMapHelper::normalizeColorKey('  белый  '), 'trim');
assert_same('черный', ColorMapHelper::normalizeColorKey('чёрный'), 'ё→е');
assert_same('', ColorMapHelper::normalizeColorKey('   '), 'spaces only → empty');

echo "\n=== ColorMapHelper: getHexFromColorMapOrNull ===\n";
assert_null(ColorMapHelper::getHexFromColorMapOrNull([], 'белый'), 'empty map → null');
$map = ['белый' => '#FFFFFF', 'черный' => '000000'];
assert_same('#FFFFFF', ColorMapHelper::getHexFromColorMapOrNull($map, 'белый'), 'exact match with #');
assert_same('#000000', ColorMapHelper::getHexFromColorMapOrNull($map, 'черный'), 'exact match without #');
assert_null(ColorMapHelper::getHexFromColorMapOrNull($map, 'серый'), 'value not in map → null');
assert_null(ColorMapHelper::getHexFromColorMapOrNull(['белый' => ''], 'белый'), 'empty hex → null');

echo "\n=== ColorMapHelper: normalizeColorMapKeys ===\n";
$mapYo = ['чёрный' => '#000000'];
$norm = ColorMapHelper::normalizeColorMapKeys($mapYo);
assert_true(isset($norm['черный']), 'normalized key added');
assert_same('#000000', $norm['черный'], 'normalized value');

echo "\n=== ColorMapHelper: hasUnmappedColorValues ===\n";
assert_true(ColorMapHelper::hasUnmappedColorValues(['белый'], []), 'empty map → true');
assert_false(ColorMapHelper::hasUnmappedColorValues(['белый'], ['белый' => '#FFF']), 'all mapped → false');
assert_true(ColorMapHelper::hasUnmappedColorValues(['белый', 'серый'], ['белый' => '#FFF']), 'one unmapped → true');
assert_false(ColorMapHelper::hasUnmappedColorValues([], ['белый' => '#FFF']), 'empty values → false');

echo "\n=== Options: сохранение SKU_COLOR_MAP только при непустом маппинге ===\n";
// Логика из options.php
$shouldSave = function ($postValue) {
    $decoded = is_string($postValue) ? json_decode($postValue, true) : null;
    return is_array($decoded) && !empty($decoded);
};
assert_true($shouldSave('{"белый":"#FFF"}'), 'non-empty JSON → save');
assert_false($shouldSave('{}'), 'empty object → do not save');
assert_false($shouldSave(''), 'empty string → do not save');
assert_false($shouldSave('[]'), 'empty array JSON → do not save');

echo "\n=== Сценарии характеристик (color vs text) ===\n";
$buildCh = function ($valueText, $colorMap, $forceColorAsText) {
    $hex = ColorMapHelper::getHexFromColorMapOrNull($colorMap, $valueText);
    $useText = $forceColorAsText || ($hex === null);
    if ($useText) {
        return ['type' => 'text', 'value' => $valueText];
    }
    return ['type' => 'color', 'value' => $hex, 'value_text' => $valueText];
};
$map = ['белый' => '#FFFFFF'];
$ch = $buildCh('белый', $map, true);
assert_same('text', $ch['type'], 'forceColorAsText → type text');
$ch = $buildCh('белый', $map, false);
assert_same('color', $ch['type'], 'mapped, no force → type color');
assert_same('#FFFFFF', $ch['value'], 'mapped → value = hex');
assert_same('белый', $ch['value_text'], 'mapped → value_text');
$ch = $buildCh('серый', $map, false);
assert_same('text', $ch['type'], 'unmapped → type text');

echo "\n=== Итог: $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
