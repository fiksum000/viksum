<?php
namespace App\Services;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
class TripayService {
  private function baseUrl():string{return config('services.tripay.mode')==='production'?'https://tripay.co.id/api':'https://tripay.co.id/api-sandbox';}
  private function headers():array{return ['Authorization'=>'Bearer '.config('services.tripay.api_key'),'Accept'=>'application/json'];}
  public function availableChannels():array{
    if(!config('services.tripay.api_key'))return [];
    return Cache::remember('tripay.channels.'.config('services.tripay.mode'),300,function():array{
      $response=Http::timeout(15)->withHeaders($this->headers())->get($this->baseUrl().'/merchant/payment-channel');
      if($response->failed()||!($response->json('success')??false))throw new RuntimeException('Daftar channel Tripay tidak dapat dimuat. Periksa API key dan koneksi.');
      return collect($response->json('data',[]))->filter(fn($channel)=>($channel['active']??false)===true)->map(fn($channel)=>['code'=>(string)$channel['code'],'name'=>(string)$channel['name']])->values()->all();
    });
  }
  public function assertAvailableChannel(string $method):void{
    if(!collect($this->availableChannels())->contains(fn($channel)=>hash_equals($channel['code'],$method)))throw new RuntimeException('Metode pembayaran tidak aktif atau tidak tersedia pada akun Tripay.');
  }
  public function createTransaction(string $method,string $merchantRef,string $customerName,string $customerEmail,int $amount,string $phone=''):array{
    $merchant=config('services.tripay.merchant_code'); $private=config('services.tripay.private_key');
    $signature=hash_hmac('sha256',$merchant.$merchantRef.$amount,$private);
    $payload=['method'=>$method,'merchant_ref'=>$merchantRef,'amount'=>$amount,'customer_name'=>$customerName,'customer_email'=>$customerEmail?:'customer@example.com','customer_phone'=>$phone,'order_items'=>[['name'=>'Internet','price'=>$amount,'quantity'=>1]],'callback_url'=>config('services.tripay.callback_url'),'return_url'=>config('services.tripay.return_url'),'expired_time'=>now(config('billing.timezone'))->addMinutes(config('services.tripay.expiry_minutes'))->timestamp,'signature'=>$signature];
    $r=Http::timeout(20)->withHeaders($this->headers())->asForm()->post($this->baseUrl().'/transaction/create',$payload); if($r->failed())throw new RuntimeException('Tripay HTTP error: '.$r->body()); $json=$r->json(); if(!($json['success']??false))throw new RuntimeException('Tripay: '.($json['message']??'Unknown error')); return $json['data']??[];
  }
  public function verifyCallbackSignature(string $raw,?string $signature):bool{if(!$signature)return false;$expected=hash_hmac('sha256',$raw,(string)config('services.tripay.private_key'));return hash_equals($expected,$signature);}
}
