<?php

namespace App\Tasks;

use App\Module\TaskReportMail;

class TaskReportMailTask extends AbstractTask
{
    public function start(): void
    {
        TaskReportMail::run();
    }

    public function end(): void
    {
    }
}
