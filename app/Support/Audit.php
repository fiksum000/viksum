<?php
namespace App\Support;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
class Audit { public static function log(string $action, ?string $type=null, ?int $id=null, array $meta=[]):void{AuditLog::create(['user_id'=>session('user_id'),'action'=>$action,'entity_type'=>$type,'entity_id'=>$id,'meta'=>$meta,'ip'=>request()?->ip()]);} }
