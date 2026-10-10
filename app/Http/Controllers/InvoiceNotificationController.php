<?php

namespace App\Http\Controllers;

use App\Models\BillingNotification;
use App\Services\BillingNotificationService;
use App\Services\FonnteService;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use App\Models\WaLog;
use Illuminate\Http\Request;

class InvoiceNotificationController
{
    public function retry(BillingNotification $notification, BillingNotificationService $notifications, FonnteService $fonnte)
    {
        if ($notification->status !== 'failed') {
            return back()->with('warning', 'Hanya notifikasi berstatus gagal yang dapat dikirim ulang.');
        }

        if (! in_array($notification->event, ['billing_reminder', 'isolation_warning', 'payment_success', 'isolation'], true)) {
            return back()->with('error', 'Jenis notifikasi ini belum didukung untuk pengiriman ulang.');
        }

        if (! $fonnte->isConfigured()) {
            return back()->with('error', 'Koneksi WhatsApp belum aktif. Periksa pengaturan Fonnte sebelum mencoba kirim ulang.');
        }

        $notification->loadMissing(['customer', 'invoice.customer']);
        $invoice = $notification->invoice;
        $customer = $notification->customer ?: $invoice?->customer;
        if (! $invoice || ! $customer) {
            return back()->with('error', 'Data invoice atau pelanggan tidak ditemukan; notifikasi tidak dikirim ulang.');
        }

        $target = $customer->whatsapp_number ?: $customer->phone;
        if (! $target) {
            return back()->with('error', 'Nomor WhatsApp pelanggan saat ini belum diisi. Perbarui data pelanggan terlebih dahulu.');
        }

        $event = $notification->event;
        $timezone = config('billing.timezone');
        $dueDate = $invoice->due_date ? CarbonImmutable::parse($invoice->due_date, $timezone) : null;
        $amount = number_format((int) $invoice->total, 0, ',', '.');
        $serviceUsername = $customer->service_type === 'pppoe' ? $customer->pppoe_username : $customer->hotspot_username;
        $paymentUrl = $invoice->payment_url ?: route('public.pay', $invoice->public_token);
        $variables = [
            'name' => $customer->name,
            'customer_code' => $customer->customer_code,
            'portal_username' => $customer->customer_code,
            'service_username' => $serviceUsername,
            'pppoe_username' => $customer->pppoe_username,
            'portal_url' => route('portal.login'),
            'invoice_number' => $invoice->invoice_number,
            'amount' => $amount,
        ];
        $scheduledFor = $notification->scheduled_for;
        $oncePerInvoice = false;

        if ($event === 'billing_reminder') {
            if ($invoice->status !== 'unpaid' || ! $dueDate) {
                return back()->with('warning', 'Pengingat tidak dikirim ulang karena invoice sudah bukan belum lunas atau tanggal jatuh tempo tidak tersedia.');
            }
            $variables += ['due_date' => $dueDate->format('d-m-Y'), 'payment_url' => $paymentUrl, 'days_offset' => (string) $scheduledFor?->diffInDays($dueDate, false)];
            $message = "Yth. {$customer->name}, kami mengingatkan tagihan {$invoice->invoice_number} sebesar Rp {$amount} yang jatuh tempo pada {$dueDate->format('d-m-Y')}. ID pelanggan: {$customer->customer_code}. Username layanan: {$serviceUsername}. Silakan lakukan pembayaran melalui {$paymentUrl}. Portal pelanggan: ".route('portal.login')." (ID login: {$customer->customer_code}). Terima kasih.";
        } elseif ($event === 'isolation_warning') {
            if ($invoice->status !== 'unpaid' || ! $dueDate || $customer->status !== 'active' || ! $customer->is_auto_isolate) {
                return back()->with('warning', 'Peringatan isolir tidak dikirim ulang karena tagihan atau status layanan sudah berubah.');
            }
            $graceDays = max(0, (int) ($customer->grace_days ?? config('billing.grace_days', 0)));
            $warningDate = $dueDate->copy()->addDays($graceDays);
            $isolationDate = $warningDate->copy()->addDay();
            if (! $notification->scheduled_for
                || ! $notification->scheduled_for->isSameDay(now($timezone))
                || ! $warningDate->isSameDay(now($timezone))) {
                return back()->with('warning', 'Peringatan H-1 hanya dapat dikirim ulang pada hari yang sama agar tidak mengirim jadwal isolir yang sudah lewat.');
            }
            $variables += ['due_date' => $dueDate->format('d-m-Y'), 'isolation_date' => $isolationDate->format('d-m-Y'), 'payment_url' => $paymentUrl, 'grace_days' => (string) $graceDays];
            $message = "Yth. {$customer->name}, tagihan {$invoice->invoice_number} sebesar Rp {$amount} belum kami terima. Batas pembayaran sebelum isolir: {$isolationDate->format('d-m-Y')}. ID pelanggan: {$customer->customer_code}. Username layanan: {$serviceUsername}. Pembayaran: {$paymentUrl}. Portal pelanggan: ".route('portal.login')." (ID login: {$customer->customer_code}). Mohon lakukan pembayaran agar layanan tidak diisolir.";
        } elseif ($event === 'payment_success') {
            if ($invoice->status !== 'paid') {
                return back()->with('warning', 'Konfirmasi pembayaran tidak dikirim ulang karena invoice belum berstatus lunas.');
            }
            if (! $notification->scheduled_for || $notification->scheduled_for->toDateString() !== '1970-01-01') {
                return back()->with('error', 'Kunci idempotensi notifikasi pembayaran tidak sesuai; hubungi administrator sebelum mencoba lagi.');
            }
            $message = "Yth. {$customer->name}, pembayaran invoice {$invoice->invoice_number} sebesar Rp {$amount} telah kami terima. Terima kasih. ID pelanggan: {$customer->customer_code}. Username layanan: {$serviceUsername}. Portal pelanggan: ".route('portal.login')." (ID login: {$customer->customer_code}).";
            $oncePerInvoice = true;
        } else {
            if ($customer->status !== 'isolated' || $invoice->status !== 'unpaid') {
                return back()->with('warning', 'Pesan isolir tidak dikirim ulang karena status layanan atau invoice sudah berubah.');
            }
            $message = "Yth. {$customer->name}, layanan internet Anda sedang diisolir karena tagihan belum dibayar. ID pelanggan: {$customer->customer_code}. Username layanan: {$serviceUsername}. Selesaikan pembayaran melalui portal pelanggan: ".route('portal.login')." (ID login: {$customer->customer_code}). Terima kasih.";
        }

        try {
            $queued = $notifications->queue($invoice, $event, $target, $message, $variables, $scheduledFor, $oncePerInvoice);
        } catch (\Throwable $exception) {
            report($exception);
            return back()->with('error', 'Notifikasi belum masuk antrean. Periksa koneksi WhatsApp dan worker, lalu coba lagi.');
        }

        if (! $queued) {
            return back()->with('info', 'Notifikasi tidak diantrekan ulang karena statusnya berubah atau sudah diproses.');
        }

        Audit::log('invoice_notification.retry_queued', BillingNotification::class, $notification->id, [
            'event' => $event,
            'invoice_id' => $invoice->id,
            'target' => $target,
        ]);

        return back()->with('success', 'Notifikasi gagal masuk antrean ulang ke nomor WhatsApp pelanggan saat ini.');
    }
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

