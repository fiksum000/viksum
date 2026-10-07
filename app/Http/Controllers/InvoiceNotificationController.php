<?php

namespace App\Http\Controllers;

use App\Models\BillingNotification;
use App\Models\WaLog;
use Illuminate\Http\Request;

class InvoiceNotificationController
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:queued,sent,failed'],
            'event' => ['nullable', 'in:billing_reminder,isolation_warning,payment_success,isolation'],
            'period' => ['nullable', 'date_format:Y-m'],
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        $query = BillingNotification::query()
            ->with(['customer:id,customer_code,name,phone,whatsapp_number', 'invoice:id,invoice_number,period,due_date,total,status', 'waLogs'])
            ->when($filters['status'] ?? null, fn ($builder, $status) => $builder->where('status', $status))
            ->when($filters['event'] ?? null, fn ($builder, $event) => $builder->where('event', $event))
            ->when($filters['period'] ?? null, fn ($builder, $period) => $builder->whereHas('invoice', fn ($invoice) => $invoice->where('period', $period)))
            ->when($filters['search'] ?? null, function ($builder, $search): void {
                $builder->where(fn ($searchQuery) => $searchQuery
                    ->whereHas('customer', fn ($customer) => $customer->where('name', 'like', '%'.$search.'%')->orWhere('customer_code', 'like', '%'.$search.'%')->orWhere('whatsapp_number', 'like', '%'.$search.'%')->orWhere('phone', 'like', '%'.$search.'%'))
                    ->orWhereHas('invoice', fn ($invoice) => $invoice->where('invoice_number', 'like', '%'.$search.'%')));
            });

        $countsQuery = clone $query;
        $counts = [
            'queued' => (clone $countsQuery)->where('status', 'queued')->count(),
            'sent' => (clone $countsQuery)->where('status', 'sent')->count(),
            'failed' => (clone $countsQuery)->where('status', 'failed')->count(),
        ];

        $notifications = $query->latest('updated_at')->paginate(30)->withQueryString();
        $otherLogs = WaLog::query()
            ->with('customer:id,customer_code,name')
            ->whereNull('billing_notification_id')
            ->whereNotIn('event', ['incoming', 'device_status'])
            ->when($filters['status'] ?? null, fn ($builder, $status) => $builder->where('status', $status))
            ->when($filters['event'] ?? null, fn ($builder, $event) => $builder->where('event', $event))
            ->when($filters['search'] ?? null, function ($builder, $search): void {
                $builder->where(fn ($searchQuery) => $searchQuery
                    ->whereHas('customer', fn ($customer) => $customer->where('name', 'like', '%'.$search.'%')->orWhere('customer_code', 'like', '%'.$search.'%'))
                    ->orWhere('target', 'like', '%'.$search.'%'));
            })
            ->latest()->limit(100)->get();

        return view('invoices.notifications', compact('notifications', 'counts', 'filters', 'otherLogs'));
    }
}

