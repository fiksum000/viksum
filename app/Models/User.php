<?php
namespace App\Models;
use Illuminate\Foundation\Auth\User as Authenticatable; use Illuminate\Notifications\Notifiable;
class User extends Authenticatable { use Notifiable; protected $fillable=['name','email','password','role']; protected $hidden=['password','remember_token']; protected function casts(): array { return ['password'=>'hashed']; } public function auditLogs(){return $this->hasMany(AuditLog::class);} public function apiTokens(){return $this->hasMany(ApiToken::class);} }
