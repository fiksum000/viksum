<?php
namespace App\Console\Commands; use Illuminate\Console\Command; use App\Services\FupService;
class CollectFupUsage extends Command {protected $signature='billing:fup-collect';protected $description='Ambil delta pemakaian PPPoE untuk FUP';public function handle(FupService $fup):int{$n=$fup->collect();$this->info("{$n} sample FUP tersimpan.");return self::SUCCESS;}}
