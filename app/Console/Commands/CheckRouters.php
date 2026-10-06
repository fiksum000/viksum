<?php
namespace App\Console\Commands; use Illuminate\Console\Command; use App\Models\Router; use App\Services\RouterOsService;
class CheckRouters extends Command {protected $signature='billing:check-routers';protected $description='Cek semua router aktif';public function handle(RouterOsService $api):int{foreach(Router::where('enabled',true)->get() as $r){$result=$api->testConnection($r);$this->line(($result['ok']?'OK':'FAIL').' '.$r->name);}return self::SUCCESS;}}
