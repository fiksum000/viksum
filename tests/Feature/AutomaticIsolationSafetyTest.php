<?php

namespace Tests\Feature;

use App\Console\Commands\IsolateOverdueCustomers;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Router;
use App\Services\FonnteService;
use App\Services\IsolationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Tests\TestCase;

class AutomaticIsolationSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_automatic_isolation_respects_customer_grace_days_and_only_isolates_past_the_grace_date(): void
    {
        config(['billing.timezone' => 'Asia/Jakarta', 'billing.grace_days' => 5]);
        Carbon::setTestNow(Carbon::parse('2026-10-10 12:00:00', 'Asia/Jakarta'));

        $overdue = $this->customer('AUTO-OVERDUE', 5);
        $this->invoice($overdue, '2026-10-04', 'INV-AUTO-OVERDUE');
        $boundary = $this->customer('AUTO-BOUNDARY', 5);
        $this->invoice($boundary, '2026-10-05', 'INV-AUTO-BOUNDARY');
        $optedOut = $this->customer('AUTO-OPTOUT', 0, false);
        $this->invoice($optedOut, '2026-10-01', 'INV-AUTO-OPTOUT');

        $isolation = Mockery::mock(IsolationService::class);
        $isolation->shouldReceive('isolate')
            ->once()
            ->withArgs(fn (Customer $customer) => $customer->id === $overdue->id)
            ->andReturnUsing(function (Customer $customer): void {
                $customer->update(['status' => 'isolated']);
            });
        $wa = Mockery::mock(FonnteService::class);
        $wa->shouldReceive('queue')
            ->once()
            ->withArgs(fn ($customerId, $target, $message, $event, $variables) =>
                $customerId === $overdue->id
                && $target === '6281234567890'
                && $event === 'isolation'
                && str_contains($message, 'diisolir sementara'));
        $this->app->instance(IsolationService::class, $isolation);
        $this->app->instance(FonnteService::class, $wa);

        $exitCode = Artisan::call('billing:isolate-overdue');

        $this->assertSame(IsolateOverdueCustomers::SUCCESS, $exitCode);
        $this->assertSame('isolated', $overdue->fresh()->status);
        $this->assertSame('active', $boundary->fresh()->status);
        $this->assertSame('active', $optedOut->fresh()->status);
        $this->assertStringContainsString('1 pelanggan berhasil diisolir', Artisan::output());
    }

    public function test_router_isolation_failure_does_not_mark_the_customer_isolated_or_send_a_notice(): void
    {
        config(['billing.timezone' => 'Asia/Jakarta', 'billing.grace_days' => 0]);
        Carbon::setTestNow(Carbon::parse('2026-10-10 12:00:00', 'Asia/Jakarta'));
        $customer = $this->customer('AUTO-FAILED', 0);
        $this->invoice($customer, '2026-10-01', 'INV-AUTO-FAILED');

        $isolation = Mockery::mock(IsolationService::class);
        $isolation->shouldReceive('isolate')->once()->andThrow(new \RuntimeException('router unavailable'));
        $wa = Mockery::mock(FonnteService::class);
        $wa->shouldNotReceive('queue');
        $this->app->instance(IsolationService::class, $isolation);
        $this->app->instance(FonnteService::class, $wa);

        $exitCode = Artisan::call('billing:isolate-overdue');

        $this->assertSame(IsolateOverdueCustomers::FAILURE, $exitCode);
        $this->assertSame('active', $customer->fresh()->status);
    }

    private function customer(string $code, int $graceDays, bool $autoIsolate = true): Customer
    {
        $router = Router::query()->create([
            'name' => 'Automatic isolation router '.$code,
            'host' => '192.0.2.80',
            'port' => 8728,
            'username' => 'auto-isolation-test',
            'password' => 'test-only-password',
            'enabled' => true,
        ]);
        $package = Package::query()->create([
            'name' => 'Automatic isolation package '.$code,
            'price' => 50000,
            'normal_profile' => 'ppp-normal',
        ]);

        return Customer::query()->create([
            'customer_code' => $code,
            'name' => $code,
            'whatsapp_number' => '6281234567890',
            'service_type' => 'pppoe',
            'status' => 'active',
            'due_day' => 20,
            'grace_days' => $graceDays,
            'is_auto_isolate' => $autoIsolate,
            'router_id' => $router->id,
            'package_id' => $package->id,
            'pppoe_username' => 'ppp-'.$code,
        ]);
    }

    private function invoice(Customer $customer, string $dueDate, string $number): Invoice
    {
        return Invoice::query()->create([
            'invoice_number' => $number,
            'public_token' => bin2hex(random_bytes(24)),
            'customer_id' => $customer->id,
            'period' => '2026-10',
            'issued_at' => '2026-10-01',
            'due_date' => $dueDate,
            'subtotal' => 50000,
            'discount' => 0,
            'penalty' => 0,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'total' => 50000,
            'status' => 'unpaid',
        ]);
    }
}
