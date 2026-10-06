<?php

use App\Http\Controllers\{AuthController, BroadcastController, CustomerController, CustomerImportExportController, CustomerPortalController, DashboardController, DashboardTrafficController, HotspotController, InvoiceController, InvoicePdfController, PackageController, PaymentController, PublicPaymentController, ReportsController, RouterController, UserController, WaTemplateController};
use App\Http\Controllers\{OltController, OnuController, PaymentSettingsController};
use Illuminate\Support\Facades\Route;

Route::get('/login', [AuthController::class, 'loginForm'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1')->name('login.attempt');
Route::get('/pay/{token}', [PublicPaymentController::class, 'show'])->middleware('throttle:30,1')->name('public.pay');
Route::get('/isolir', fn () => response()->view('public.isolation')->header('Cache-Control', 'no-store, private'))->middleware('throttle:60,1')->name('public.isolation');
Route::post('/pay/{token}', [PublicPaymentController::class, 'create'])->middleware('throttle:10,1')->name('public.pay.create');
Route::get('/isolir', function () {
    return session('customer_id') ? redirect()->route('portal.isolated') : redirect()->route('portal.login');
})->name('public.isolated');
Route::get('/portal/login', [CustomerPortalController::class, 'loginForm'])->name('portal.login');
Route::post('/portal/login', [CustomerPortalController::class, 'login'])->middleware('throttle:10,1')->name('portal.login.submit');
Route::middleware('customer.session')->prefix('/portal')->name('portal.')->group(function (): void {
    Route::get('/', [CustomerPortalController::class, 'home'])->name('home');
    Route::get('/isolir', [CustomerPortalController::class, 'isolated'])->name('isolated');
    Route::get('/invoices/{invoice}', [CustomerPortalController::class, 'invoice'])->name('invoices.show');
    Route::get('/invoices/{invoice}/pdf', [CustomerPortalController::class, 'pdf'])->name('invoices.pdf');
    Route::post('/logout', [CustomerPortalController::class, 'logout'])->name('logout');
});

Route::middleware('auth.session')->group(function (): void {
    Route::get('/', fn () => redirect()->route('dashboard'));
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/dashboard/traffic', DashboardTrafficController::class)->middleware('throttle:10,1')->name('dashboard.traffic');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::middleware('role:super_admin,admin,operator,technician')->group(function (): void {
        Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
    });

    Route::get('/customers/export', [CustomerImportExportController::class, 'export'])->middleware('role:super_admin,admin,finance')->name('customers.export');

    Route::middleware('role:super_admin,admin,operator')->group(function (): void {
        Route::get('/customers/create', [CustomerController::class, 'create'])->name('customers.create');
        Route::post('/customers', [CustomerController::class, 'store'])->name('customers.store');
        Route::get('/customers/{customer}/edit', [CustomerController::class, 'edit'])->name('customers.edit');
        Route::put('/customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');
        Route::post('/customers/import', [CustomerImportExportController::class, 'import'])->middleware('role:super_admin,admin')->name('customers.import');
    });

    Route::middleware('role:super_admin,admin')->group(function (): void {
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
        Route::get('/whatsapp', [WaTemplateController::class, 'index'])->name('whatsapp.index');
        Route::put('/whatsapp/connection', [WaTemplateController::class, 'updateConnection'])->name('whatsapp.connection.update');
        Route::put('/whatsapp/templates/{template}', [WaTemplateController::class, 'update'])->name('whatsapp.templates.update');
        Route::post('/whatsapp/broadcast', [BroadcastController::class, 'send'])->middleware('throttle:5,1')->name('whatsapp.broadcast');
        Route::get('/payment-settings', [PaymentSettingsController::class, 'index'])->name('payment-settings.index');
        Route::put('/payment-settings', [PaymentSettingsController::class, 'update'])->name('payment-settings.update');
        Route::get('/packages', [PackageController::class, 'index'])->name('packages.index');
        Route::post('/packages', [PackageController::class, 'store'])->name('packages.store');
        Route::put('/packages/{package}', [PackageController::class, 'update'])->name('packages.update');
        Route::delete('/packages/{package}', [PackageController::class, 'destroy'])->name('packages.destroy');
        Route::post('/routers', [RouterController::class, 'store'])->name('routers.store');
        Route::put('/routers/{router}', [RouterController::class, 'update'])->name('routers.update');
        Route::delete('/routers/{router}', [RouterController::class, 'destroy'])->name('routers.destroy');
    });

    Route::middleware('role:super_admin,admin,technician')->group(function (): void {
        Route::get('/routers', [RouterController::class, 'index'])->name('routers.index');
        Route::post('/routers/{router}/test', [RouterController::class, 'test'])->name('routers.test');
        Route::patch('/routers/{router}/traffic-interface', [RouterController::class, 'updateTrafficInterface'])->name('routers.traffic-interface');
        Route::get('/olts', [OltController::class, 'index'])->name('olts.index');
        Route::get('/onus', [OnuController::class, 'index'])->name('onus.index');
        Route::get('/hotspot', [HotspotController::class, 'index'])->name('hotspot.index');
        Route::get('/routers/{router}/hotspot-active', [HotspotController::class, 'active'])->name('hotspot.active');
    });

    Route::middleware('role:super_admin,admin')->group(function (): void {
        Route::post('/olts', [OltController::class, 'store'])->name('olts.store');
        Route::put('/olts/{olt}', [OltController::class, 'update'])->name('olts.update');
        Route::delete('/olts/{olt}', [OltController::class, 'destroy'])->name('olts.destroy');
        Route::post('/onus', [OnuController::class, 'store'])->name('onus.store');
        Route::put('/onus/{onu}', [OnuController::class, 'update'])->name('onus.update');
        Route::delete('/onus/{onu}', [OnuController::class, 'destroy'])->name('onus.destroy');
        Route::post('/hotspot/vouchers', [HotspotController::class, 'generate'])->name('hotspot.generate');
        Route::patch('/hotspot/vouchers/{voucher}', [HotspotController::class, 'toggle'])->name('hotspot.toggle');
        Route::delete('/hotspot/vouchers/{voucher}', [HotspotController::class, 'destroy'])->name('hotspot.destroy');
        Route::get('/hotspot/vouchers/print', [HotspotController::class, 'print'])->name('hotspot.print');
    });

    Route::middleware('role:super_admin,admin,finance')->group(function (): void {
        Route::get('/reports', [ReportsController::class, 'index'])->name('reports.index');
        Route::get('/reports/export', [ReportsController::class, 'export'])->name('reports.export');
        Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
        Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
        Route::get('/invoices/{invoice}/pdf', InvoicePdfController::class)->name('invoices.pdf');
        Route::post('/invoices/generate', [InvoiceController::class, 'generate'])->middleware('role:super_admin,admin')->name('invoices.generate');
        Route::post('/invoices/{invoice}/pay', [InvoiceController::class, 'pay'])->name('invoices.pay');
        Route::put('/invoices/{invoice}/adjustments', [InvoiceController::class, 'adjust'])->name('invoices.adjust');
        Route::post('/invoices/{invoice}/manual-payment', [PaymentController::class, 'manual'])->name('invoices.manual-payment');
    });
});
