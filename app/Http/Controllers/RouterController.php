<?php
namespace App\Http\Controllers;
use App\Models\Router; use App\Services\RouterOsService; use Illuminate\Http\Request;
class RouterController extends Controller { public function index()
    {
        $billingUrl = rtrim((string) config('app.url'), '/');
        $billingHost = parse_url($billingUrl, PHP_URL_HOST) ?: 'billing.example.com';
        $isolationProfile = (string) config('billing.isolation_profile', 'ISOLIR');
        $isolationScript = <<<'ROUTEROS'
# Setup halaman isolir Billing RTRW Net
# APP_URL: __BILLING_URL__
# Backup konfigurasi router sebelum menerapkan
/export file=before-fiksum-isolir

# Profile ISOLIR menandai IP pelanggan terisolir pada address-list
:local isolirProfile [/ppp profile find where name="__ISOLATION_PROFILE__"]
:if ([:len $isolirProfile] = 0) do={
    /ppp profile add copy-from=default name="__ISOLATION_PROFILE__" address-list="FIKSUM-ISOLIR" rate-limit=64k/64k
} else={
    /ppp profile set $isolirProfile address-list="FIKSUM-ISOLIR"
}

# Web Proxy untuk halaman publik isolir
/ip proxy set enabled=yes port=8097

# Hapus hanya rule FIKSUM sebelumnya agar script aman dijalankan ulang
/ip proxy access remove [find where comment="FIKSUM-ISOLIR-Allow-Portal"]
/ip proxy access remove [find where comment="FIKSUM-ISOLIR-Redirect"]
/ip firewall nat remove [find where comment="FIKSUM-ISOLIR-HTTP"]

# Urutan penting: izinkan host billing, lalu alihkan HTTP lainnya
/ip proxy access add action=redirect action-data="__ISOLATION_URL__" local-port=8097 comment="FIKSUM-ISOLIR-Redirect" place-before=0
/ip proxy access add action=allow dst-host="__BILLING_HOST__" comment="FIKSUM-ISOLIR-Allow-Portal" place-before=0

# Hanya HTTP port 80 dari IP pelanggan yang memakai profile ISOLIR
/ip firewall nat add chain=dstnat src-address-list="FIKSUM-ISOLIR" protocol=tcp dst-port=80 action=redirect to-ports=8097 comment="FIKSUM-ISOLIR-HTTP" place-before=0

# Matikan rule lama MSRadius yang redirect semua pelanggan atau port HTTPS
/ip firewall nat disable [find where comment="MSISOLIR"]
/ip proxy access disable [find where comment="DENY OTHER THAN THE ISOLIR IP THAT GOES TO THE WEB PROXY BY MS"]
ROUTEROS;
        $isolationScript = str_replace(
            ['__BILLING_URL__', '__ISOLATION_URL__', '__BILLING_HOST__', '__ISOLATION_PROFILE__'],
            [$billingUrl, $billingUrl.'/isolir', $billingHost, $isolationProfile],
            $isolationScript
        );

        return view('routers.index', [
            'routers' => Router::latest()->get(),
            'isolationScript' => $isolationScript,
            'billingHost' => $billingHost,
            'isolationMethod' => config('billing.isolation_method', 'profile'),
        ]);
    } public function store(Request $r){$d=$r->validate(['name'=>'required|max:100','host'=>'required|max:255','port'=>'required|integer|min:1|max:65535','username'=>'required|max:100','password'=>'required','ssl'=>'nullable|boolean','enabled'=>'nullable|boolean','notes'=>'nullable','traffic_interface'=>'nullable|max:120','traffic_interface'=>'nullable|max:120','traffic_interface'=>'nullable|max:120']);Router::create($d);return back()->with('success','Router disimpan.');} public function update(Request $r,Router $router){$d=$r->validate(['name'=>'required|max:100','host'=>'required|max:255','port'=>'required|integer|min:1|max:65535','username'=>'required|max:100','password'=>'nullable','ssl'=>'nullable|boolean','enabled'=>'nullable|boolean','notes'=>'nullable']);if($d['password']==='')unset($d['password']);$router->update($d);return back()->with('success','Router diperbarui.');} public function updateTrafficInterface(Request $r,Router $router){$d=$r->validate(['traffic_interface'=>'required|string|max:120']);$router->update($d);return back()->with('success','Interface trafik router diperbarui.');} public function test(Router $router,RouterOsService $api){$r=$api->testConnection($router);return back()->with($r['ok']?'success':'error',$r['ok']?'Koneksi berhasil. Identity/resource terbaca.':'Koneksi gagal: '.$r['error']);} public function destroy(Router $router){$router->delete();return back()->with('success','Router dihapus.');}}
