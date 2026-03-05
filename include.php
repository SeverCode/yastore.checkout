<?php
\Bitrix\Main\Loader::registerAutoLoadClasses('yastore.checkout', [
    '\Yastore\Checkout\Handlers' => 'lib/handlers.php',
    '\Yastore\Checkout\Controller' => 'lib/controller/checkout.php',
    '\Yastore\Checkout\Api' => 'lib/api.php',
    '\Yastore\Checkout\Handlers\BaseHandler' => 'lib/handlers/BaseHandler.php',
    '\Yastore\Checkout\Handlers\CheckBasketHandler' => 'lib/handlers/CheckBasketHandler.php',
    '\Yastore\Checkout\Handlers\WarehousesHandler' => 'lib/handlers/WarehousesHandler.php',
    '\Yastore\Checkout\Handlers\OrdersHandler' => 'lib/handlers/OrdersHandler.php',
    '\Yastore\Checkout\Handlers\SettingsHandler' => 'lib/handlers/SettingsHandler.php',
    '\Yastore\Checkout\Delivery\YandexKitDelivery' => 'lib/delivery/yandexkitdelivery.php',
]);