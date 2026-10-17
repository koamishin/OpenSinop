<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\StudentReceiptDispatcher;
use Illuminate\Console\Command;
use Throwable;

class DispatchStudentReceipts extends Command
{
    protected $signature = 'app:dispatch-student-receipts';

    protected $description = 'Recover settled student receipt reconciliation and classification; never resend transfers or approve spending';

    public function handle(StudentReceiptDispatcher $dispatcher): int
    {
        try {
            $this->info('Student receipt jobs dispatched: '.$dispatcher->dispatch().'. No transfers submitted.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Receipt recovery blocked. Check institution and durable queue configuration. No transfer submitted.');

            return self::FAILURE;
        }
    }
}
