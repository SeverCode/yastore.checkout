<?php
namespace Sale\Handlers\PaySystem;

use Bitrix\Main\Config;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Request;
use Bitrix\Main\Result;
use Bitrix\Main\Text\Encoding;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Sale\Order;
use Bitrix\Sale\PaySystem;
use Bitrix\Sale\Payment;
use Bitrix\Sale\PriceMaths;

Loc::loadMessages(__FILE__);

class YandexKitHandler extends PaySystem\ServiceHandler
{
    /**
     * @param Payment $payment
     * @param Request|null $request
     * @return PaySystem\ServiceResult
     */
    public function initiatePay(Payment $payment, Request $request = null)
    {
        // Заглушка для служебной платежной системы
        $this->setExtraParams([]);
        return $this->showTemplate($payment, "template");
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function getPaymentIdFromRequest(Request $request)
    {
        // Заглушка для служебной платежной системы
        return null;
    }

    /**
     * @return array
     */
    public function getCurrencyList()
    {
        return array('RUB');
    }

    /**
     * @param Request $request
     * @return PaySystem\ServiceResult
     */
    public function processRequest(Payment $payment, Request $request)
    {
        // Заглушка для служебной платежной системы
        $result = new PaySystem\ServiceResult();
        return $result;
    }
}

// Создаем алиас для класса, который ожидает Bitrix
// Bitrix формирует имя класса как Sale\Handlers\PaySystem\yandex_kitHandler
if (!class_exists('Sale\Handlers\PaySystem\yandex_kitHandler')) {
    class_alias('Sale\Handlers\PaySystem\YandexKitHandler', 'Sale\Handlers\PaySystem\yandex_kitHandler');
}



