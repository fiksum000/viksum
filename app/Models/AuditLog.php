<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\Relations\BelongsTo;
class AuditLog extends Model { protected $fillable=['user_id','action','entity_type','entity_id','meta','ip']; protected $casts=['meta'=>'array']; public function user():BelongsTo{return $this->belongsTo(User::class);} }
