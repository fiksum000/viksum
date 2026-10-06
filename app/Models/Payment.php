<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\Relations\BelongsTo;
class Payment extends Model { protected $fillable=['invoice_id','provider','reference','merchant_ref','channel','amount','status','checkout_url','raw_payload','paid_at']; protected $casts=['amount'=>'integer','raw_payload'=>'array','paid_at'=>'datetime']; public function invoice():BelongsTo{return $this->belongsTo(Invoice::class);} }
