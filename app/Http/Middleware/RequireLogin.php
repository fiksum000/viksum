<?php
namespace App\Http\Middleware;
use Closure; use Illuminate\Http\Request; use Symfony\Component\HttpFoundation\Response;
class RequireLogin { public function handle(Request $request, Closure $next): Response { $user=\App\Models\User::find($request->session()->get('user_id')); if(!$user){$request->session()->forget('user_id');return redirect()->route('login')->with('error','Silakan login terlebih dahulu.');} $request->attributes->set('billing_user',$user); view()->share('billingUser',$user); return $next($request); } }
