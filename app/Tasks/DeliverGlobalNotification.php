<?php

namespace App\Tasks;

use App\Module\GlobalNotification;

class DeliverGlobalNotification extends AbstractTask
{
    public function __construct(private int $deliveryId)
    {
        parent::__construct($deliveryId);
    }

    public function start()
    {
        GlobalNotification::deliver($this->deliveryId);
    }

    public function end()
    {
    }
}
