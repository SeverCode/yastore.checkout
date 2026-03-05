<?php
if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true) die();

use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

// Заглушка для служебной платежной системы
?>
<div class="yandex-kit-service-payment">
    <p><?= Loc::getMessage('YANDEX_KIT_SERVICE_PAYMENT_DESCRIPTION') ?></p>
    <p><strong><?= Loc::getMessage('YANDEX_KIT_SERVICE_PAYMENT_NOTE') ?></strong></p>
</div>



