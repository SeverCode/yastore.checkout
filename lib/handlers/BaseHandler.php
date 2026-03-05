<?php
namespace Yastore\Checkout\Handlers;

use Bitrix\Main\Application;
use Bitrix\Main\Web\Json;
use Bitrix\Main\Loader;
use Bitrix\Catalog\StoreTable;
use Bitrix\Catalog\StoreProductTable;

abstract class BaseHandler
{
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

    protected function sendError($message, $httpCode = 500)
    {
        http_response_code($httpCode);
        echo Json::encode(['error' => $message]);
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
