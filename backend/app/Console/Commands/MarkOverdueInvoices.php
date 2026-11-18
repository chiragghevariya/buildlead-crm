<?php

namespace App\Console\Commands;

use App\Services\Erp\InvoiceService;
use Illuminate\Console\Command;

class MarkOverdueInvoices extends Command
{
    protected $signature = 'invoices:mark-overdue';

    protected $description = 'Flag sent invoices whose due date has passed as overdue (scheduled daily)';

    public function handle(InvoiceService $invoices): int
    {
        $count = $invoices->markOverdue();

        $this->info($count === 0 ? 'No new overdue invoices.' : "{$count} ".str('invoice')->plural($count).' marked as overdue.');

        return self::SUCCESS;
    }
}
