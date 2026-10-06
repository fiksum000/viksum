<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\Relations\HasMany; use Illuminate\Encryption\Encrypter;
class Router extends Model { protected $fillable=['name','host','port','username','password','ssl','enabled','last_seen_at','meta','notes','traffic_interface']; protected $hidden=['password']; protected $casts=['password'=>'encrypted','ssl'=>'boolean','enabled'=>'boolean','last_seen_at'=>'datetime','meta'=>'array']; public function customers():HasMany{return $this->hasMany(Customer::class);} }
