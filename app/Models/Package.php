<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\Relations\HasMany;
class Package extends Model { protected $fillable=['name','price','router_id','normal_profile','normal_speed','burst_enabled','burst_limit','burst_threshold','priority','fup_enabled','fup_limit_bytes','fup_speed_after']; protected $casts=['price'=>'integer','burst_enabled'=>'boolean','priority'=>'integer','fup_enabled'=>'boolean','fup_limit_bytes'=>'integer']; public function customers():HasMany{return $this->hasMany(Customer::class);} public function router(){return $this->belongsTo(Router::class);} }
