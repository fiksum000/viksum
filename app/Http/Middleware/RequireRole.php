<?php
namespace App\Http\Middleware;
use Closure; use Illuminate\Http\Request; use Symfony\Component\HttpFoundation\Response; use App\Models\User;
class RequireRole { public function handle(Request $request, Closure $next, ...$roles): Response { $u=User::find($request->session()->get('user_id')); if(!$u || !in_array($u->role,$roles,true)) abort(403,'Tidak memiliki hak akses.'); return $next($request); } }
