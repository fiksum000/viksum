<?php
namespace App\Models; use Illuminate\Database\Eloquent\Model;
class WaLog extends Model { protected $fillable=['customer_id','target','event','message','status','response']; protected $casts=['response'=>'array']; }
