<?php

namespace App\Http\Middleware;

use App\Models\Customer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireCustomerLogin
{
    public function handle(Request $request, Closure $next): Response
    {
        $customer = Customer::find($request->session()->get('customer_id'));
        if (!$customer || !$customer->portal_password) {
            $request->session()->forget('customer_id');
            return redirect()->route('portal.login')->with('error', 'Silakan login ke portal pelanggan.');
        }

        $request->attributes->set('billing_customer', $customer);\n        if ($customer->status === 'isolated' && !$request->routeIs('portal.isolated', 'portal.logout')) {\n            return redirect()->route('portal.isolated');\n        }
        return $next($request);
    }
}
