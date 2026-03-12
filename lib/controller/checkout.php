<?php
namespace Yastore\Checkout\Controller;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Sale;
use Bitrix\Sale\Basket;
use Bitrix\Sale\Order;
use Yastore\Checkout\ProductIdResolver;

class Checkout extends Controller
{
    private static $MODULE_ID = "yastore.checkout";
    private static $CHECKOUT_URL_BASE = "https://checkout.yastore.yandex.ru";

    private $siteId;
    private $currencyCode;
    private $userId;

    public function __construct()
    {
        parent::__construct();
        Loader::includeModule('sale');
        Loader::includeModule('catalog');
        $this->siteId = Application::getInstance()->getContext()->getSite();
        $this->currencyCode = Sale\Internals\SiteCurrencyTable::getSiteCurrency($this->siteId);
        $this->userId = Option::get(self::$MODULE_ID, 'YASTORE_USER_ID', '');
    }

    public function configureActions()
    {
        return [
            'basketToCheckout' => [
                'prefilters' => []
            ],
        ];
    }

    /**
     * Строит URL для редиректа на Яндекс KIT GET /express (host, metric_client_id, data).
     * Ответ API: 302 redirect to kit checkout или 500.
     */
    public function basketToCheckoutAction($metricaClientId)
    {
        $basket = Basket::loadItemsForFUser(Sale\Fuser::getId(), $this->siteId);
        if ($basket->isEmpty()) {
            $this->addError(new Error('Empty basket', 500));
            return null;
        }

        $expressData = $this->buildExpressData($basket);
        if (empty($expressData['items'])) {
            $this->addError(new Error('No valid basket items', 500));
            return null;
        }

        $expressUrl = 'https://checkout.kit.yandex.ru/express';

        $request = Application::getInstance()->getContext()->getRequest();
        $host = $request->getHttpHost() ?: $request->getServer()->get('HTTP_HOST');

        $dataJson = json_encode($expressData, JSON_UNESCAPED_UNICODE);
        $params = [
            'host' => $host,
            'data' => base64_encode($dataJson),
        ];
        if ($metricaClientId !== null && $metricaClientId !== '') {
            $params['metric_client_id'] = $metricaClientId;
        }

        $redirectUrl = $expressUrl . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        return [
            'status' => 'success',
            'url' => $redirectUrl,
        ];
    }

    /**
     * Данные для GET /express: items[{ id, quantity, price, final_price }], city (optional).
     */
    private function buildExpressData(Basket $basket)
    {
        $discounts = $this->getDiscounts($basket);
        $items = [];

        foreach ($basket as $basketItem) {
            $productId = $basketItem->getProductId();
            $basketCode = $basketItem->getBasketCode();
            $quantity = (int) $basketItem->getQuantity();
            $basePrice = (float) $basketItem->getBasePrice();
            $finalPrice = (float) $basketItem->getFinalPrice();

            if (isset($discounts['PRICES']['BASKET'][$basketCode]['PRICE'])) {
                $finalPrice = (float) $discounts['PRICES']['BASKET'][$basketCode]['PRICE'];
            }
            if ((int) $finalPrice == 0 && (int) $basePrice == 0) {
                $resultPrice = $this->getResultPrice($productId);
                if ($resultPrice) {
                    $basePrice = (float) $resultPrice['BASE_PRICE'];
                    $finalPrice = (float) $resultPrice['DISCOUNT_PRICE'];
                }
            }

            $items[] = [
                'id' => ProductIdResolver::getExternalId($productId),
                'quantity' => $quantity,
                'price' => $basePrice,
                'final_price' => $finalPrice,
            ];
        }

        return ['items' => $items];
    }

    private function createDataItem($basketItem, $productId, $discounts)
    {
        $itemData = \CIBlockElement::GetByID($productId)->GetNext();
        if (empty($itemData) || !$itemData['ACTIVE']) {
            return;
        }

        $imageUrl = $this->getImageUrl($itemData, $productId);

        $detailPageUrl = !empty($itemData['DETAIL_PAGE_URL']) ?
            (\Bitrix\Main\Engine\UrlManager::getInstance()->getHostUrl() . $itemData['DETAIL_PAGE_URL']) :
            '';

        $basketCode = $basketItem->getBasketCode();
        $finalPrice = $basketItem->getFinalPrice();
        if (isset($discounts["PRICES"]['BASKET'][$basketCode])) {
            $finalPrice = $discounts["PRICES"]['BASKET'][$basketCode]["PRICE"];
        }

        $name = $basketItem->getField('NAME');
        if (empty($name)) {
            $name = $itemData['NAME'];
        }

        $basePrice = $basketItem->getBasePrice();

        if ((int) $finalPrice == 0) {
            $resultPrice = $this->getResultPrice($productId);
            $finalPrice = (float) $resultPrice['DISCOUNT_PRICE'];
            $basePrice = (float) $resultPrice['BASE_PRICE'];
        }

        $dataItem = [
            "id" => (string) $productId,
            "name" => $name,
            "quantity" => (int) $basketItem->getQuantity(),
            "price" => (string) $basePrice,
            "final_price" => (string) $finalPrice,
            "url" => $detailPageUrl,
            "img" => $imageUrl,
        ];

        $weight = $basketItem->getField('WEIGHT');
        $dimensions = $basketItem->getField('DIMENSIONS');
        if (!empty($dimensions)) {
            $dimensions = unserialize($dimensions);
            $height = $dimensions['HEIGHT'];
            $depth = $dimensions['LENGTH'];
            $width = $dimensions['WIDTH'];
        }
        if (!empty($width)) {
            $dataItem['width_per_item_cm'] = (int) ($width / 10.0);
        }
        if (!empty($height)) {
            $dataItem['height_per_item_cm'] = (int) ($height / 10.0);
        }
        if (!empty($depth)) {
            $dataItem['depth_per_item_cm'] = (int) ($depth / 10.0);
        }
        if (!empty($weight)) {
            $dataItem['weight_per_item_grams'] = (int) ($weight / 10.0);
        }

        return $dataItem;
    }

    private function getDiscounts($basket)
    {
        $discounts = \Bitrix\Sale\Discount::buildFromBasket($basket, new \Bitrix\Sale\Discount\Context\Fuser($basket->getFUserId(true)));
        $discounts->calculate();
        $arBasketDiscounts = $discounts->getApplyResult(true);
        return $arBasketDiscounts;
    }

    private function getImageUrl($itemData, $productId)
    {
        $imageID = null;

        $getImageId = function ($data) {
            foreach (['PREVIEW_PICTURE', 'DETAIL_PICTURE'] as $field) {
                $value = $data[$field] ?? '';
                if (is_array($value)) {
                    $value = $value['ID'] ?? $value['VALUE'] ?? '';
                }
                if (!empty($value)) {
                    return $value;
                }
            }
            return null;
        };

        $imageID = $getImageId($itemData);

        if (empty($imageID)) {
            $productInfo = \CCatalogSku::GetProductInfo($productId);
            if ($productInfo && !empty($productInfo['ID'])) {
                $res = \CIBlockElement::GetList(
                    [],
                    ['ID' => $productInfo['ID']],
                    false,
                    false,
                    ['ID', 'PREVIEW_PICTURE', 'DETAIL_PICTURE']
                );
                if ($parentData = $res->GetNext()) {
                    $imageID = $getImageId($parentData);
                }
            }
        }

        $imageUrl = '';
        if (!empty($imageID)) {
            $imagePath = \CFile::GetPath($imageID);
            if (empty($imagePath)) {
                $fileInfo = \CFile::GetFileArray($imageID);
                $imagePath = $fileInfo ? $fileInfo['SRC'] : '';
            }
            $imageUrl = \Bitrix\Main\Engine\UrlManager::getInstance()->getHostUrl() . $imagePath;
        }

        return $imageUrl;
    }

    private function createOrder($basket)
    {
        $order = Order::create($this->siteId, $this->userId);
        $order->setPersonTypeId(1);
        $order->setBasket($basket);
        $order->setField('CURRENCY', $this->currencyCode);

        $order->doFinalAction(true);

        return $order;
    }

    private function getResultPrice($productId)
    {
        $arPrice = \CCatalogProduct::GetOptimalPrice($productId, 1, [], 'N');

        if ($arPrice) {
            return $arPrice['RESULT_PRICE'];
        } else {
            $this->addError(new Error("No price for productId=$productId", 400));
            return null;
        }
    }
}