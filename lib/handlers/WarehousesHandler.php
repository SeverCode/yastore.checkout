<?php
namespace Yastore\Checkout\Handlers;

use Bitrix\Main\Loader;
use Bitrix\Catalog\StoreTable;
use Bitrix\Sale\Configuration;

class WarehousesHandler extends BaseHandler
{
    public function handle($orderId = null)
    {
        try {
            if (!Loader::includeModule('catalog')) {
                $this->sendError('Catalog module not available', 500);
                return;
            }

            // Подключаем модуль sale для проверки складского учета
            if (!Loader::includeModule('sale')) {
                $this->sendError('Sale module not available', 500);
                return;
            }

            $isStoreControl = $this->isStoreControlEnabled();

            // Получаем склады из базы данных
            $warehouses = StoreTable::getList([
                'filter' => ['ACTIVE' => 'Y'],
                'select' => ['*'],
                'order' => ['SORT' => 'ASC', 'ID' => 'ASC']
            ]);

            $warehousesList = [];
            while ($warehouse = $warehouses->fetch()) {
                $warehouseLower = array_change_key_case($warehouse, CASE_LOWER);
                $warehousesList[] = $warehouseLower;
            }

            // Если складов нет в системе - возвращаем виртуальный склад
            if (empty($warehousesList)) {
                $virtualWarehouse = $this->getVirtualWarehouse();
                $warehousesList[] = [
                    'id' => $virtualWarehouse['id'],
                    'title' => $virtualWarehouse['name'],
                    'xml_id' => $virtualWarehouse['id'],
                    'active' => 'Y'
                ];
            } else {
                // При выключенном складском учете возвращаем только первый склад
                if (!$isStoreControl) {
                    $warehousesList = [reset($warehousesList)];
                }
            }

            $this->sendResponse([
                'warehouses' => $warehousesList
            ]);

        } catch (\Exception $e) {
            $this->sendError('Failed to get warehouses: ' . $e->getMessage(), 500);
        }
    }
}

