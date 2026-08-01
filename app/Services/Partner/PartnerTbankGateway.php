<?php

namespace App\Services\Partner;

use App\Models\ShopOrder;
use App\Models\ShopPaymentMethod;
use App\Services\TbankPaymentService;

class PartnerTbankGateway
{
    public function initiate(ShopPaymentMethod $method, ShopOrder $order): array
    {
        return (new TbankPaymentService($method->settings ?? []))->initiatePayment($order);
    }
}
