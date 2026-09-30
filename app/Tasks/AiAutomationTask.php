<?php

namespace App\Tasks;

use App\Module\AiAutomation;

class AiAutomationTask extends AbstractTask
{
    public function start()
    {
        AiAutomation::run();
    }

    public function end()
    {
    }
}
