<?php

namespace App\Services;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;

class CustomerQueryService
{
    /** @param array{search?: string|null, status?: string|null, service_type?: string|null, package_id?: int|string|null} $filters */
    public function filtered(array $filters): Builder
    {
        return Customer::query()
            ->with(['package', 'router'])
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query->where(function (Builder $query) use ($search): void {
                $query->where('name', 'like', '%'.$search.'%')
                    ->orWhere('customer_code', 'like', '%'.$search.'%')
                    ->orWhere('pppoe_username', 'like', '%'.$search.'%')
                    ->orWhere('hotspot_username', 'like', '%'.$search.'%')
                    ->orWhere('phone', 'like', '%'.$search.'%')
                    ->orWhere('whatsapp_number', 'like', '%'.$search.'%');
            }))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['service_type'] ?? null, fn (Builder $query, string $type) => $query->where('service_type', $type))
            ->when($filters['package_id'] ?? null, fn (Builder $query, int|string $packageId) => $query->where('package_id', $packageId))
            ->latest('id');
    }
}
