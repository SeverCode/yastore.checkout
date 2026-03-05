# Тесты модуля yastore.checkout

Проверка сценариев работы карты цветов, характеристик и опций.

## Запуск без PHPUnit

Из корня модуля:

```bash
php test/run_tests.php
```

Проверяются:

- **normalizeColorKey** — trim, ё→е
- **getHexFromColorMapOrNull** — пустая карта, точное/нормализованное/регистр, отсутствие в карте, пустой hex
- **normalizeColorMapKeys** — добавление нормализованных ключей
- **hasUnmappedColorValues** — пустая карта → true, все в маппинге → false, хотя бы один не в маппинге → true
- **Options** — сохранение SKU_COLOR_MAP только при непустом декодированном JSON
- **Характеристики** — при `forceColorAsText` или отсутствии hex → type text; при наличии hex → type color, value = hex, value_text

## Запуск через PHPUnit

Если установлен PHPUnit:

```bash
cd bitrix/modules/yastore.checkout/test
phpunit
```

Файлы: `ColorMapHelperTest.php`, `OptionsColorMapTest.php`, `CharacteristicsScenariosTest.php`.

## Интеграционные тесты

Скрипт `../tests/test_product.php` проверяет товар в контуре Bitrix (склад, цены и т.д.) — запуск из CLI с ID товара.
