<?php
namespace Yastore\Checkout\Handlers;

use Bitrix\Main\Application;
use Bitrix\Main\Web\Json;
use Bitrix\Main\Loader;
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
     * Отправка ошибки в стандартном формате: error.message + error.code
     * @param string $message Текст ошибки
     * @param int $httpCode HTTP-код (400, 401, 404, 409, 500)
     * @param string|null $code Код ошибки (ErrorCode); если null — выводится по умолчанию из $httpCode
     */
    protected function sendError($message, $httpCode = 500, $code = null)
    {
        if ($code === null) {
            $code = $this->getDefaultErrorCodeForHttpStatus($httpCode);
        }
        http_response_code($httpCode);
        echo Json::encode([
            'error' => [
                'message' => $message,
                'code' => $code
            ]
        ]);
    }

    /**
     * Отправка ошибки с дополнительными полями в теле (например actual_inventory)
     * @param string $message Текст ошибки
     * @param int $httpCode HTTP-код
     * @param string|null $code Код ошибки
     * @param array $extra Ключи и значения, добавляемые в корень JSON (например ['actual_inventory' => $data])
     */
    protected function sendErrorWithData($message, $httpCode = 500, $code = null, array $extra = [])
    {
        if ($code === null) {
            $code = $this->getDefaultErrorCodeForHttpStatus($httpCode);
        }
        http_response_code($httpCode);
        $body = array_merge(
            [
                'error' => [
                    'message' => $message,
                    'code' => $code
                ]
            ],
            $extra
        );
        echo Json::encode($body);
    }

    /**
     * Код ошибки по умолчанию для HTTP-статуса
     */
    private function getDefaultErrorCodeForHttpStatus($httpCode)
    {
        $map = [
            400 => self::ERROR_INVALID_INPUT,
            401 => self::ERROR_UNAUTHORIZED,
            404 => self::ERROR_NOT_FOUND,
            409 => self::ERROR_CONFLICT,
        ];
        return $map[(int)$httpCode] ?? self::ERROR_INTERNAL;
    }

    /**
     * Возвращает данные виртуального склада
     * @return array
     */
    protected function getVirtualWarehouse()
    {
        return [
            'id' => '1',
            'name' => 'Виртуальный склад'
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
