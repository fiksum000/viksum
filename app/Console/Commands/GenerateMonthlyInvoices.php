<?php
namespace App\Console\Commands; use Illuminate\Console\Command; use App\Services\BillingService;
class GenerateMonthlyInvoices extends Command {protected $signature='billing:generate-invoices {period?}';protected $description='Generate invoice bulanan';public function handle(BillingService $billing):int{$period=$this->argument('period')?:now(config('billing.timezone'))->format('Y-m');$count=$billing->generate($period);$this->info("{$count} invoice dibuat untuk {$period}.");return self::SUCCESS;}}
