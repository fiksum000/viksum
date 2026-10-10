<?php
namespace App\Services;
use App\Models\Router;
use App\Models\HotspotProfile;
use Illuminate\Support\Facades\Log;
use RouterOS\Client; use RouterOS\Config; use RouterOS\Query; use RuntimeException;
class RouterOsService {
  private function client(Router $router): Client { if(!$router->enabled) throw new RuntimeException('Router dinonaktifkan.'); $cfg=new Config(['host'=>$router->host,'user'=>$router->username,'pass'=>$router->password,'port'=>$router->port,'ssl'=>$router->ssl,'socket_timeout'=>5]); return new Client($cfg); }
  public function testConnection(Router $router):array { try{$client=$this->client($router);$rows=$client->query('/system/resource/print')->read();$resource=$rows[0]??[];$meta=$router->meta??[];$meta['resource']=$resource;$identity=$client->query('/system/identity/print')->read();$meta['identity']=$identity[0]['name']??$router->name;$router->update(['last_seen_at'=>now(),'meta'=>$meta]);return ['ok'=>true,'resource'=>$resource,'identity'=>$meta['identity']];}catch(\Throwable $e){Log::warning('MikroTik test failed', ['router'=>$router->id,'error'=>$e->getMessage()]); return ['ok'=>false,'error'=>$e->getMessage()];} }
  public function interfaceTraffic(Router $router): array
  {
    $interface = trim((string) $router->traffic_interface);
    if ($interface === '') throw new RuntimeException('Pilih interface trafik pada data router.');
    $rows = $this->client($router)->query((new Query('/interface/monitor-traffic'))->equal('interface', $interface)->equal('once'))->read();
    $row = $rows[0] ?? [];
    return ['interface' => $interface, 'rx_bps' => (int) ($row['rx-bits-per-second'] ?? 0), 'tx_bps' => (int) ($row['tx-bits-per-second'] ?? 0)];
  }
  public function findPppSecret(Router $router,string $username):array{return $this->client($router)->query((new Query('/ppp/secret/print'))->where('name',$username))->read();}
  private function pppId(Router $r,string $u):string { $rows=$this->findPppSecret($r,$u); $id=$rows[0]['.id']??null; if(!$id) throw new RuntimeException("PPPoE user {$u} tidak ditemukan."); return $id; }
  public function setPppProfile(Router $router,string $username,string $profile):void{$c=$this->client($router);$c->query((new Query('/ppp/secret/set'))->equal('.id',$this->pppId($router,$username))->equal('profile',$profile))->read();}
  public function enablePppSecret(Router $router,string $username,bool $enable):void{$c=$this->client($router);$c->query((new Query('/ppp/secret/set'))->equal('.id',$this->pppId($router,$username))->equal('disabled',$enable?'no':'yes'))->read();}
  public function disconnectPppActive(Router $router,string $username):void{$c=$this->client($router);$rows=$c->query((new Query('/ppp/active/print'))->where('name',$username))->read();foreach($rows as $row){if(isset($row['.id']))$c->query((new Query('/ppp/active/remove'))->equal('.id',$row['.id']))->read();}}
  public function activePppMap(Router $router):array{$rows=$this->client($router)->query('/ppp/active/print')->read();$out=[];foreach($rows as $r){$n=$r['name']??null;if($n)$out[$n]=$r;}return $out;}
  public function createOrUpdatePppSecret(Router $router,array $data,?string $previousUsername=null):void
  {
      $client = $this->client($router);
      $name = $data['name'];
      $profile = $data['profile'] ?? 'default';
      $profiles = $client->query('/ppp/profile/print')->read();
      $profileExists = collect($profiles)->contains(fn ($row) => ($row['name'] ?? null) === $profile);
      if (!$profileExists) {
          throw new RuntimeException("Profil PPP '{$profile}' tidak ditemukan di MikroTik. Perbaiki profil normal pada paket atau pelanggan.");
      }

      $secrets = $this->listPppSecrets($router);
      $findByName = fn (string $username) => collect($secrets)->first(fn ($row) => ($row['name'] ?? null) === $username);
      $existingTarget = $findByName($name);
      $existingPrevious = filled($previousUsername) ? $findByName($previousUsername) : null;

      // Only update a secret known to belong to this customer. Never overwrite
      // an unrelated MikroTik secret just because the username happens to match.
      if ($existingTarget && (!$existingPrevious || ($existingPrevious['.id'] ?? null) !== ($existingTarget['.id'] ?? null))) {
          throw new RuntimeException("Username PPP '{$name}' sudah ada di MikroTik dan tidak cocok dengan secret pelanggan ini. Gunakan username lain atau periksa router yang dipilih.");
      }

      $secretToUpdate = $existingPrevious;
      $query = (new Query($secretToUpdate ? '/ppp/secret/set' : '/ppp/secret/add'))
          ->equal('name', $name)
          ->equal('password', $data['password'] ?? '')
          ->equal('service', 'pppoe')
          ->equal('profile', $profile);
      if ($secretToUpdate) {
          $query->equal('.id', $secretToUpdate['.id']);
      }
      $client->query($query)->read();

      $verified = $this->findPppSecret($router, $name);
      if (!$verified) {
          throw new RuntimeException("Secret PPP '{$name}' tidak terverifikasi setelah dikirim ke MikroTik.");
      }
  }
  public function deletePppSecret(Router $router,string $username):void{$client=$this->client($router);$rows=$this->findPppSecret($router,$username);foreach($rows as $row){if(isset($row['.id']))$client->query((new Query('/ppp/secret/remove'))->equal('.id',$row['.id']))->read();}}
  public function listPppSecrets(Router $router):array{return $this->client($router)->query('/ppp/secret/print')->read();}
  public function listPppProfiles(Router $router):array{return $this->client($router)->query('/ppp/profile/print')->read();}
  public function listHotspotUsers(Router $router): array
  {
    return $this->client($router)->query('/ip/hotspot/user/print')->read();
  }

  public function listHotspotActive(Router $router): array
  {
    return $this->client($router)->query('/ip/hotspot/active/print')->read();
  }

  public function listHotspotProfiles(Router $router): array
  {
    return $this->client($router)->query('/ip/hotspot/user/profile/print')->read();
  }

  /**
   * Push the application-managed profile and its optional FUP profile to RouterOS.
   * MikroTik rate-limit order is upload (RX) / download (TX).
   */
  public function syncHotspotProfile(HotspotProfile $profile): void
  {
    $router = $profile->router;
    if (! $router || ! $router->enabled) {
      throw new RuntimeException('Router profil Hotspot tidak tersedia atau dinonaktifkan.');
    }

    $client = $this->client($router);
    $onLogin = $this->managedHotspotOnLoginScript($profile->bind_mac);
    $this->upsertHotspotRouterProfile(
      $client,
      $profile->name,
      $profile->upload_speed.'/'.$profile->download_speed,
      $profile->shared_users,
      $onLogin,
    );

    if ($profile->fup_limit_bytes > 0 && filled($profile->fup_upload_speed) && filled($profile->fup_download_speed)) {
      $this->upsertHotspotRouterProfile(
        $client,
        $profile->name.'-FUP',
        $profile->fup_upload_speed.'/'.$profile->fup_download_speed,
        $profile->shared_users,
        $onLogin,
      );
    }
  }

  private function upsertHotspotRouterProfile(
    Client $client,
    string $name,
    string $rateLimit,
    int $sharedUsers,
    string $onLogin,
  ): void {
    $rows = $client->query('/ip/hotspot/user/profile/print')->read();
    $matches = collect($rows)->filter(fn (array $row) => ($row['name'] ?? null) === $name)->values();
    if ($matches->count() > 1) {
      throw new RuntimeException("Profil RouterOS '{$name}' ditemukan lebih dari satu kali; sinkronisasi dihentikan.");
    }

    $query = new Query($matches->isNotEmpty() ? '/ip/hotspot/user/profile/set' : '/ip/hotspot/user/profile/add');
    $query->equal('name', $name)
      ->equal('rate-limit', $rateLimit)
      ->equal('shared-users', (string) max(1, $sharedUsers))
      ->equal('on-login', $onLogin);

    if ($matches->isNotEmpty()) {
      $id = $matches[0]['.id'] ?? null;
      if (! $id) {
        throw new RuntimeException("ID profil RouterOS '{$name}' tidak tersedia.");
      }
      $query->equal('.id', $id);
    }

    $client->query($query)->read();
    $verified = $client->query('/ip/hotspot/user/profile/print')->read();
    $row = collect($verified)->first(fn (array $item) => ($item['name'] ?? null) === $name);
    if (! $row
      || ($row['rate-limit'] ?? null) !== $rateLimit
      || (int) ($row['shared-users'] ?? 1) !== max(1, $sharedUsers)) {
      throw new RuntimeException("Profil RouterOS '{$name}' tidak cocok setelah sinkronisasi.");
    }
  }

  private function managedHotspotOnLoginScript(bool $bindMac): string
  {
    $script = ':local u $"user"; :local id [/ip hotspot user find where name=$u]; :if ([:len $id] > 0) do={ :local c [/ip hotspot user get $id comment]; :if ([:find $c "VIKSUM:V:"] = 0) do={ :if ([:find $c "|FIRST="] = nil) do={ /ip hotspot user set $id comment=($c . "|FIRST=" . [/system clock get date] . " " . [/system clock get time]); }';

    if ($bindMac) {
      $script .= ' :local savedMac [/ip hotspot user get $id mac-address]; :if ($savedMac = "00:00:00:00:00:00") do={ /ip hotspot user set $id mac-address=$"mac-address"; }';
    }

    return $script.' } }';
  }

  public function setHotspotUserProfile(Router $router, string $username, string $profile): void
  {
    $client = $this->client($router);
    $rows = $client->query((new Query('/ip/hotspot/user/print'))->where('name', $username))->read();
    $matches = collect($rows)->filter(fn (array $row) => ($row['name'] ?? null) === $username)->values();

    if ($matches->count() !== 1 || ! isset($matches[0]['.id'])) {
      throw new RuntimeException("Akun Hotspot '{$username}' tidak ditemukan secara unik di router.");
    }

    $profiles = $client->query('/ip/hotspot/user/profile/print')->read();
    if (! collect($profiles)->contains(fn (array $row) => ($row['name'] ?? null) === $profile)) {
      throw new RuntimeException("Profil Hotspot '{$profile}' belum ada di router.");
    }

    $client->query((new Query('/ip/hotspot/user/set'))
      ->equal('.id', $matches[0]['.id'])
      ->equal('profile', $profile))->read();
  }

  public function assertHotspotProfileExists(Router $router, string $profile): void
  {
    if (trim($profile) === '') {
      throw new RuntimeException('Profil Hotspot wajib diisi.');
    }

    $exists = collect($this->listHotspotProfiles($router))
      ->contains(fn (array $row) => ($row['name'] ?? null) === $profile);

    if (! $exists) {
      throw new RuntimeException("Profil Hotspot '{$profile}' tidak ditemukan pada router {$router->name}.");
    }
  }

  /**
   * Create a voucher account on RouterOS. The calling generator validates the
   * profile once before a batch; other callers should leave $profileValidated false.
   */
  public function createHotspotUser(
    Router $router,
    string $username,
    string $password,
    string $profile,
    string $comment = '',
    bool $profileValidated = false,
  ): void {
    if (! $profileValidated) {
      $this->assertHotspotProfileExists($router, $profile);
    }

    $client = $this->client($router);
    $existing = $client->query('/ip/hotspot/user/print')->read();
    if (collect($existing)->contains(fn (array $row) => ($row['name'] ?? null) === $username)) {
      throw new RuntimeException("Username Hotspot '{$username}' sudah ada di router.");
    }

    $client->query((new Query('/ip/hotspot/user/add'))
      ->equal('name', $username)
      ->equal('password', $password)
      ->equal('profile', $profile)
      ->equal('comment', $comment))->read();

    $verified = $client->query((new Query('/ip/hotspot/user/print'))->where('name', $username))->read();
    if (! collect($verified)->contains(fn (array $row) => ($row['name'] ?? null) === $username)) {
      throw new RuntimeException("Voucher Hotspot '{$username}' tidak terverifikasi setelah dibuat.");
    }
  }

  /**
   * Synchronize a customer account without overwriting an unrelated RouterOS
   * user that happens to have the requested username.
   */
  public function createOrUpdateHotspotUser(
    Router $router,
    string $username,
    string $password,
    string $profile,
    string $comment = '',
    ?string $previousUsername = null,
    bool $enabled = true,
  ): void {
    $client = $this->client($router);
    $profiles = $client->query('/ip/hotspot/user/profile/print')->read();
    if (! collect($profiles)->contains(fn (array $row) => ($row['name'] ?? null) === $profile)) {
      throw new RuntimeException("Profil Hotspot '{$profile}' tidak ditemukan pada router {$router->name}.");
    }

    $users = $client->query('/ip/hotspot/user/print')->read();
    $findByName = fn (string $candidate) => collect($users)
      ->first(fn (array $row) => ($row['name'] ?? null) === $candidate);
    $target = $findByName($username);
    $previous = filled($previousUsername) ? $findByName($previousUsername) : null;

    if ($target && (! $previous || ($target['.id'] ?? null) !== ($previous['.id'] ?? null))) {
      throw new RuntimeException("Username Hotspot '{$username}' sudah ada di MikroTik dan tidak cocok dengan akun pelanggan ini.");
    }

    $query = new Query($previous ? '/ip/hotspot/user/set' : '/ip/hotspot/user/add');
    $query->equal('name', $username)
      ->equal('password', $password)
      ->equal('profile', $profile)
      ->equal('comment', $comment)
      ->equal('disabled', $enabled ? 'no' : 'yes');

    if ($previous) {
      $query->equal('.id', $previous['.id']);
    }

    $client->query($query)->read();

    $verified = $client->query((new Query('/ip/hotspot/user/print'))->where('name', $username))->read();
    if (! collect($verified)->contains(fn (array $row) => ($row['name'] ?? null) === $username)) {
      throw new RuntimeException("Akun Hotspot '{$username}' tidak terverifikasi setelah disinkronkan.");
    }
  }

  public function setHotspotUserEnabled(Router $router, string $username, bool $enabled): void
  {
    $client = $this->client($router);
    $rows = $client->query((new Query('/ip/hotspot/user/print'))->where('name', $username))->read();
    $matches = collect($rows)->filter(fn (array $row) => ($row['name'] ?? null) === $username)->values();

    if ($matches->count() !== 1 || ! isset($matches[0]['.id'])) {
      throw new RuntimeException("Akun Hotspot '{$username}' tidak ditemukan secara unik di router.");
    }

    $client->query((new Query('/ip/hotspot/user/set'))
      ->equal('.id', $matches[0]['.id'])
      ->equal('disabled', $enabled ? 'no' : 'yes'))->read();
  }

  public function disconnectHotspotActive(Router $router, string $username): void
  {
    $client = $this->client($router);
    $rows = $client->query((new Query('/ip/hotspot/active/print'))->where('user', $username))->read();

    foreach ($rows as $row) {
      if (isset($row['.id'])) {
        $client->query((new Query('/ip/hotspot/active/remove'))->equal('.id', $row['.id']))->read();
      }
    }
  }

  public function deleteHotspotUser(Router $router, string $username): void
  {
    $client = $this->client($router);
    $rows = $client->query((new Query('/ip/hotspot/user/print'))->where('name', $username))->read();

    foreach ($rows as $row) {
      if (isset($row['.id']) && ($row['name'] ?? null) === $username) {
        $client->query((new Query('/ip/hotspot/user/remove'))->equal('.id', $row['.id']))->read();
      }
    }
  }

  /** Read current per-session PPPoE rates without changing RouterOS configuration. */
  public function activePppTrafficMap(Router $router, array $usernames = []):array
  {
    $requested = [];
    foreach ($usernames as $username) {
      if (is_string($username) && $username !== '') $requested[$username] = true;
    }
    if ($requested === []) return [];

    $client = $this->client($router);
    $rows = $client->query('/ppp/active/print')->read();
    $interfaces = $client->query('/interface/print')->read();
    $active = [];
    foreach ($rows as $row) {
      $name = $row['name'] ?? null;
      if ($name && ($row['service'] ?? 'pppoe') === 'pppoe' && isset($requested[$name])) {
        $active[$name] = $row;
      }
    }

    $pppoeInterfaces = [];
    foreach ($interfaces as $interface) {
      $name = $interface['name'] ?? '';
      if (($interface['type'] ?? '') !== 'pppoe-in'
        || !str_starts_with($name, '<pppoe-')
        || !str_ends_with($name, '>')) continue;
      $pppoeInterfaces[substr($name, strlen('<pppoe-'), -1)] = $name;
    }

    $out = [];
    foreach ($active as $username => $row) {
      $out[$username] = [
        'session_id' => (string) ($row['session-id'] ?? ''),
        'caller_id' => (string) ($row['caller-id'] ?? ''),
        'address' => (string) ($row['address'] ?? ''),
        'download_bps' => null,
        'upload_bps' => null,
      ];

      $interface = $pppoeInterfaces[$username] ?? null;
      if (!$interface) continue;

      try {
        $sample = $client->query((new Query('/interface/monitor-traffic'))
          ->equal('interface', $interface)
          ->equal('once', ''))->read()[0] ?? [];
        $rx = $sample['rx-bits-per-second'] ?? null;
        $tx = $sample['tx-bits-per-second'] ?? null;
        if (is_numeric($rx) && is_numeric($tx)) {
          // On a PPPoE interface, RX is customer upload and TX is download.
          $out[$username]['download_bps'] = max(0, (int) $tx);
          $out[$username]['upload_bps'] = max(0, (int) $rx);
        }
      } catch (\Throwable) {
        // Keep the active session visible; a per-interface read failure is not offline.
      }
    }
    return $out;
  }
  /** Read active Hotspot byte counters without changing RouterOS state. */
  public function activeHotspotTrafficMap(Router $router):array
  {
    $out=[];
    foreach($this->listHotspotActive($router) as $row){
      $name=$row['user']??null; if(!$name) continue;
      $out[$name]=['session_id'=>(string)($row['.id']??''),'caller_id'=>(string)($row['mac-address']??''),'address'=>(string)($row['address']??''),'download_bytes'=>is_numeric($row['bytes-out']??null)?(int)$row['bytes-out']:null,'upload_bytes'=>is_numeric($row['bytes-in']??null)?(int)$row['bytes-in']:null];
    }
    return $out;
  }
}
