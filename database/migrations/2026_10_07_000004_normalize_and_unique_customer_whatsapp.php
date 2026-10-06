<?php

use App\Support\WhatsappNumber;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $rows = DB::table('customers')->whereNotNull('whatsapp_number')->orderBy('id')->get(['id', 'whatsapp_number']);
        $normalized = [];
        $owners = [];

        foreach ($rows as $row) {
            $number = WhatsappNumber::normalize($row->whatsapp_number);
            if (!WhatsappNumber::isValid($number)) {
                throw new \RuntimeException("Nomor WhatsApp pelanggan #{$row->id} tidak valid. Perbaiki ke format Indonesia 08… atau 628… sebelum migrasi.");
            }
            if ($number === null) {
                continue;
            }
            $owners[$number][] = $row->id;
            $normalized[$row->id] = $number;
        }

        $duplicates = array_filter($owners, fn (array $ids): bool => count($ids) > 1);
        if ($duplicates !== []) {
            $customerIds = implode(', ', array_merge(...array_values($duplicates)));
            throw new \RuntimeException("Nomor WhatsApp duplikat ditemukan pada pelanggan ID: {$customerIds}. Perbaiki nomor tersebut sebelum migrasi.");
        }

        foreach ($normalized as $id => $number) {
            DB::table('customers')->where('id', $id)->update(['whatsapp_number' => $number]);
        }

        Schema::table('customers', function ($table): void {
            $table->unique('whatsapp_number', 'customers_whatsapp_number_unique');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function ($table): void {
            $table->dropUnique('customers_whatsapp_number_unique');
        });
    }
};

