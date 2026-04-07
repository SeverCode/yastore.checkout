<?php
namespace Yastore\Checkout\Handlers;

use Bitrix\Main\Application;
use Bitrix\Main\Web\Json;
use Bitrix\Main\Loader;
use Bitrix\Main\Config\Option;
use Bitrix\Catalog\StoreTable;
use Bitrix\Catalog\StoreProductTable;

abstract class BaseHandler
{
    /** Коды ошибок для внешней системы (ErrorCode) */
    const ERROR_INVALID_INPUT = 'INVALID_INPUT';
    const ERROR_UNAUTHORIZED = 'UNAUTHORIZED';
    const ERROR_NOT_FOUND = 'NOT_FOUND';
    const ERROR_CONFLICT = 'CONFLICT';
    const ERROR_INTERNAL = 'INTERNAL_ERROR';
    const ERROR_PRODUCT_NOT_FOUND = 'PRODUCT_NOT_FOUND';
    const ERROR_INVENTORY_CONFLICT = 'INVENTORY_CONFLICT';

    protected $request;
    protected $moduleId = 'yastore.checkout';

    public function __construct()
    {
        $this->request = Application::getInstance()->getContext()->getRequest();
    }

    abstract public function handle($orderId = null);

    protected function sendResponse($data, $httpCode = 200)
    {
        http_response_code($httpCode);
        echo Json::encode($data);
    }

    /**
     * Отправка ошибки в формате: {"error": "текст ошибки"}
     * @param string $message Текст ошибки
     * @param int $httpCode HTTP-код (400, 401, 404, 409, 500)
     * @param string|null $code Не используется (оставлен для совместимости вызовов)
     */
    protected function sendError($message, $httpCode = 500, $code = null)
    {
        http_response_code($httpCode);
        echo Json::encode(['error' => $message]);
    }

    /**
     * Отправка ошибки с дополнительными полями в теле (например debug, actual_inventory).
     * Формат: {"error": "текст ошибки", ...extra}
     * @param string $message Текст ошибки
     * @param int $httpCode HTTP-код
     * @param string|null $code Не используется (оставлен для совместимости вызовов)
     * @param array $extra Ключи и значения, добавляемые в корень JSON
     */
    protected function sendErrorWithData($message, $httpCode = 500, $code = null, array $extra = [])
    {
        http_response_code($httpCode);
        $body = array_merge(['error' => $message], $extra);
        echo Json::encode($body);
    }

    /**
     * Режим «только общий остаток»: в API тот же виртуальный склад id=1, без учёта остатков по складам.
     */
    protected function useGeneralStockOnly()
    {
        return Option::get($this->moduleId, 'USE_GENERAL_STOCK_ONLY', 'N') === 'Y';
    }

    /**
     * Склад для ответов API в режиме общего остатка — тот же, что и виртуальный (id 1).
     * @return array{id: string, name: string}
     */
    protected function getGeneralWarehouseForApi()
    {
        return $this->getVirtualWarehouse();
    }

    /**
     * Возвращает данные виртуального склада
     * @return array
     */
    protected function getVirtualWarehouse()
    {
        return [
            'id' => '1',
            'name' => 'Виртуальный склад',
        ];
    }

    /**
     * Проверяет наличие остатков по складам для товара
     * @param int $productId ID товара
     * @return bool true если есть остатки по складам, false если нет
     */
    protected function hasWarehouseStock($productId)
    {
        if (!Loader::includeModule('catalog')) {
            return false;
        }

        // Проверяем наличие записей в StoreProductTable с AMOUNT > 0
        $storeProduct = StoreProductTable::getList([
            'filter' => [
                'PRODUCT_ID' => $productId,
                '>AMOUNT' => 0
            ],
            'select' => ['ID'],
            'limit' => 1
        ])->fetch();

        return (bool)$storeProduct;
    }

    /**
     * Проверяет наличие складов в системе
     * @return bool true если есть активные склады, false если нет
     */
    protected function hasWarehouses()
    {
        if (!Loader::includeModule('catalog')) {
            return false;
        }

        $warehouse = StoreTable::getList([
            'filter' => ['ACTIVE' => 'Y'],
            'select' => ['ID'],
            'limit' => 1
        ])->fetch();

        return (bool)$warehouse;
    }

    /**
    
     * Проверяет, включен ли складской учет
     * Совместимость с Bitrix 18.5 и новыми версиями
     * @return bool
     */
    protected function isStoreControlEnabled()
    {
        if (!Loader::includeModule('sale')) {
            return false;
        }

        // Проверяем наличие метода в новой версии
        if (method_exists('Bitrix\Sale\Configuration', 'useStoreControl')) {
            return \Bitrix\Sale\Configuration::useStoreControl();
        }

        // Fallback для старых версий
        return \Bitrix\Main\Config\Option::get('catalog', 'default_use_store_control', 'N') === 'Y';
    }
}
