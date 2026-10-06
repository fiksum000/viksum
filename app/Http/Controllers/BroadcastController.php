<?php

namespace App\Http\Controllers;

use App\Jobs\SendWhatsAppMessage;
use App\Models\Customer;
use App\Support\Audit;
use App\Services\FonnteService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;

class BroadcastController extends Controller
{
    public function send(Request $request, FonnteService $fonnte)
    {
        $data = $request->validate(['audience' => 'required|in:all,active,isolated,unpaid', 'message' => 'required|string|max:1000']);
        if (! $fonnte->isConfigured()) {
            return back()->with('error', 'Aktifkan koneksi Fonnte dan isi API Token di pengaturan WhatsApp terlebih dahulu.');
        }

        $customers = Customer::query()->where(function ($query): void {
            $query->where(fn ($q) => $q->whereNotNull('whatsapp_number')->where('whatsapp_number', '!=', ''))
                ->orWhere(fn ($q) => $q->whereNotNull('phone')->where('phone', '!=', ''));
        });
        if ($data['audience'] === 'active' || $data['audience'] === 'isolated') {
            $customers->where('status', $data['audience']);
        } elseif ($data['audience'] === 'unpaid') {
            $customers->whereHas('invoices', fn ($query) => $query->where('status', 'unpaid'));
        }

        $queued = 0;
        $jobs = [];
        $customers->select(['id', 'phone', 'whatsapp_number'])->orderBy('id')->chunkById(100, function ($batch) use (&$jobs, &$queued, $data): void {
            foreach ($batch as $customer) {
                $jobs[] = new SendWhatsAppMessage($customer->id, $customer->whatsapp_number ?: $customer->phone, $data['message'], 'broadcast', ['message' => $data['message']]);
                $queued++;
            }
            Bus::batch($jobs)->name('WhatsApp broadcast')->dispatch();
            $jobs = [];
        });

        Audit::log('wa.broadcast_queued', Customer::class, null, ['audience' => $data['audience'], 'count' => $queued]);
        return back()->with('success', "{$queued} pesan masuk antrean broadcast.");
    }
}
