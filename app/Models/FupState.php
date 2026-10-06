<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\Relations\BelongsTo;
class FupState extends Model { protected $fillable=['customer_id','period','last_rx','last_tx','total_bytes','limited','last_sampled_at']; protected $casts=['last_rx'=>'integer','last_tx'=>'integer','total_bytes'=>'integer','limited'=>'boolean','last_sampled_at'=>'datetime']; public function customer():BelongsTo{return $this->belongsTo(Customer::class);} }
