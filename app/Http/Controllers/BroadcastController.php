<?php

namespace App\Http\Controllers;

use App\Jobs\SendWhatsAppMessage;
use App\Models\Customer;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;

class BroadcastController extends Controller
{
    public function send(Request $request)
    {
        $data = $request->validate(['audience' => 'required|in:all,active,isolated,unpaid', 'message' => 'required|string|max:1000']);
        if (!config('services.fonnte.token')) {
            return back()->with('error', 'Isi FONNTE_TOKEN di .env sebelum mengirim broadcast.');
        }

        $customers = Customer::query()->whereNotNull('phone')->where('phone', '!=', '');
        if ($data['audience'] === 'active' || $data['audience'] === 'isolated') {
            $customers->where('status', $data['audience']);
        } elseif ($data['audience'] === 'unpaid') {
            $customers->whereHas('invoices', fn ($query) => $query->where('status', 'unpaid'));
        }

        $queued = 0;
        $jobs = [];
        $customers->select(['id', 'phone'])->orderBy('id')->chunkById(100, function ($batch) use (&$jobs, &$queued, $data): void {
            foreach ($batch as $customer) {
                $jobs[] = new SendWhatsAppMessage($customer->id, $customer->phone, $data['message'], 'broadcast', ['message' => $data['message']]);
                $queued++;
            }
            Bus::batch($jobs)->name('WhatsApp broadcast')->dispatch();
            $jobs = [];
        });

        Audit::log('wa.broadcast_queued', Customer::class, null, ['audience' => $data['audience'], 'count' => $queued]);
        return back()->with('success', "{$queued} pesan masuk antrean broadcast.");
    }
}
