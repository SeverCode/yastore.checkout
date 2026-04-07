<?php
namespace Yastore\Checkout\Handlers;

use Bitrix\Main\Loader;
use Bitrix\Catalog\StoreTable;

class WarehousesHandler extends BaseHandler
{
    public function handle($orderId = null)
    {
        try {
            if (!Loader::includeModule('catalog')) {
                $this->sendError('Catalog module not available', 500);
                return;
            }

            if ($this->useGeneralStockOnly()) {
                $gw = $this->getGeneralWarehouseForApi();
                $warehousesList = [[
                    'id' => $gw['id'],
                    'title' => $gw['name'],
                    'xml_id' => $gw['id'],
                    'active' => 'Y',
                ]];
            } else {
                // Получаем склады из базы данных
                $warehouses = StoreTable::getList([
                    'filter' => ['ACTIVE' => 'Y'],
                    'select' => ['*'],
                    'order' => ['SORT' => 'ASC', 'ID' => 'ASC']
                ]);

                $warehousesList = [];
                while ($warehouse = $warehouses->fetch()) {
                    $warehouseLower = array_change_key_case($warehouse, CASE_LOWER);
                    $warehouseLower['id'] = (string)$warehouse['ID'];
                    $warehousesList[] = $warehouseLower;
                }

                // Если складов нет в системе - возвращаем виртуальный склад
                if (empty($warehousesList)) {
                    $virtualWarehouse = $this->getVirtualWarehouse();
                    $warehousesList[] = [
                        'id' => (string)$virtualWarehouse['id'],
                        'title' => $virtualWarehouse['name'],
                        'xml_id' => $virtualWarehouse['id'],
                        'active' => 'Y'
                    ];
                }
            }
            // Всегда отдаём все активные склады — для выбора точек выдачи (ПВЗ) нужен полный список

            $this->sendResponse([
                'warehouses' => $warehousesList
            ]);

        } catch (\Exception $e) {
            $this->sendError('Failed to get warehouses: ' . $e->getMessage(), 500);
        }
    }
}

