<?php
namespace App\Services;
use App\Models\Router;
use App\Models\Package;
use App\Models\HotspotProfile;
use App\Models\HotspotVoucher;
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
  private function pppId(Router $r, string $u): string
  {
    $rows = collect($this->findPppSecret($r, $u))
      ->filter(fn (array $row) => ($row['name'] ?? null) === $u)
      ->values();
    if ($rows->count() !== 1 || ! isset($rows[0]['.id'])) {
      throw new RuntimeException("PPPoE user '{$u}' tidak ditemukan secara unik di router.");
    }
    return (string) $rows[0]['.id'];
  }

  public function setPppProfile(Router $router, string $username, string $profile): void
  {
    $client = $this->client($router);
    $profiles = $client->query('/ppp/profile/print')->read();
    if (! collect($profiles)->contains(fn (array $row) => ($row['name'] ?? null) === $profile)) {
      throw new RuntimeException("Profil PPP '{$profile}' tidak ditemukan di router {$router->name}.");
    }

    $id = $this->pppId($router, $username);
    $client->query((new Query('/ppp/secret/set'))->equal('.id', $id)->equal('profile', $profile))->read();
    $verified = collect($this->findPppSecret($router, $username))
      ->first(fn (array $row) => ($row['name'] ?? null) === $username);
    if (! $verified || ($verified['profile'] ?? null) !== $profile) {
      throw new RuntimeException("Profil PPP '{$profile}' belum terverifikasi untuk user '{$username}'.");
    }
  }

  /**
   * Only change an existing secret if its current profile is one of the package
   * profiles we expect. This helps protect independently managed PPP accounts.
   */
  public function setPppProfileIfCurrentProfile(
    Router $router,
    string $username,
    array $expectedCurrentProfiles,
    string $targetProfile,
  ): bool {
    $client = $this->client($router);
    $rows = collect($this->findPppSecret($router, $username))
      ->filter(fn (array $row) => ($row['name'] ?? null) === $username)
      ->values();
    if ($rows->count() !== 1 || ! isset($rows[0]['.id'])) {
      throw new RuntimeException("PPP secret '{$username}' tidak ditemukan secara unik.");
    }

    $current = (string) ($rows[0]['profile'] ?? '');
    if (! in_array($current, $expectedCurrentProfiles, true)) {
      throw new RuntimeException("Profil PPP '{$username}' berubah menjadi '{$current}' di luar pemetaan paket. Akun dilewati demi keamanan.");
    }
    if ($current === $targetProfile) {
      return false;
    }

    $profiles = $client->query('/ppp/profile/print')->read();
    if (! collect($profiles)->contains(fn (array $row) => ($row['name'] ?? null) === $targetProfile)) {
      throw new RuntimeException("Profil PPP tujuan '{$targetProfile}' tidak ada di router.");
    }

    $client->query((new Query('/ppp/secret/set'))
      ->equal('.id', $rows[0]['.id'])
      ->equal('profile', $targetProfile))->read();
    $verified = collect($this->findPppSecret($router, $username))
      ->first(fn (array $row) => ($row['name'] ?? null) === $username);
    if (! $verified || ($verified['profile'] ?? null) !== $targetProfile) {
      throw new RuntimeException("Profil PPP '{$targetProfile}' belum terverifikasi untuk user '{$username}'.");
    }

    $this->disconnectPppActive($router, $username);
    return true;
  }

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
      $previousProfile = $secretToUpdate['profile'] ?? null;
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
      if ($secretToUpdate && $previousProfile !== $profile) {
          // Force RouterOS to recreate dynamic queues with the newly assigned profile,
          // including when a customer exits FUP or is restored from profile-based isolation.
          $this->disconnectPppActive($router, $previousUsername ?: $name);
      }
  }
  public function deletePppSecret(Router $router,string $username):void{$client=$this->client($router);$rows=$this->findPppSecret($router,$username);foreach($rows as $row){if(isset($row['.id']))$client->query((new Query('/ppp/secret/remove'))->equal('.id',$row['.id']))->read();}}
  public function listPppSecrets(Router $router):array{return $this->client($router)->query('/ppp/secret/print')->read();}
  public function listPppProfiles(Router $router):array{return $this->client($router)->query('/ppp/profile/print')->read();}

  /**
   * Create/update PPP profiles owned by a billing package.
   * RouterOS rate-limit uses RX/TX from router perspective (client upload/download).
   */
  public function syncPppPackageProfiles(Package $package): bool
  {
    $router = $package->router;
    if (! $router || ! $router->enabled) {
      throw new RuntimeException('Router paket PPPoE tidak tersedia atau dinonaktifkan.');
    }
    if (! $package->exists || $package->normal_profile !== $package->routerProfileName()) {
      throw new RuntimeException('Nama profil PPP internal billing belum disiapkan; sinkronisasi dihentikan.');
    }

    $upload = trim((string) $package->upload_speed);
    $download = trim((string) $package->download_speed);
    if ($upload === '' || $download === '') {
      throw new RuntimeException('Kecepatan upload dan download belum diisi pada paket billing.');
    }

    $rateLimit = $upload.'/'.$download;
    if ($package->burst_enabled) {
      $burst = trim((string) $package->burst_limit);
      $threshold = trim((string) $package->burst_threshold);
      $burstTime = trim((string) ($package->burst_time ?: '5s'));
      $ratePattern = '/^\\d+(?:\\.\\d+)?[kKmMgG]?(?:\\/\\d+(?:\\.\\d+)?[kKmMgG]?)?$/';
      $timePattern = '/^\\d+(?:\\.\\d+)?[smhd](?:\\d+(?:\\.\\d+)?[smhd])*$/i';
      if ($burst === '' || $threshold === ''
        || ! preg_match($ratePattern, $burst)
        || ! preg_match($ratePattern, $threshold)
        || ! preg_match($timePattern, $burstTime)) {
        throw new RuntimeException('Format burst tidak valid. Contoh batas 5M/20M, threshold 2M/10M, waktu 5s.');
      }
      $rateLimit .= ' '.$burst.' '.$threshold.' '.$burstTime.'/'.$burstTime.' '.(int) $package->priority;
    } else {
      // Keep the configured priority while setting burst rate and threshold equal
      // to the base rate, so burst is effectively disabled.
      $rateLimit .= ' '.$rateLimit.' '.$rateLimit.' 1s/1s '.(int) $package->priority;
    }

    $changed = $this->upsertManagedPppProfile(
      $router,
      $package->routerProfileName(),
      $rateLimit,
      'VIKSUM:PACKAGE:'.$package->id.':NORMAL',
    );

    if ($package->fup_enabled) {
      if ((int) $package->fup_limit_bytes <= 0
        || blank($package->fup_upload_speed)
        || blank($package->fup_download_speed)) {
        throw new RuntimeException('FUP aktif tetapi batas GB atau kecepatan upload/download FUP belum lengkap.');
      }

      $changed = $this->upsertManagedPppProfile(
        $router,
        $package->routerFupProfileName(),
        trim((string) $package->fup_upload_speed).'/'.trim((string) $package->fup_download_speed),
        'VIKSUM:PACKAGE:'.$package->id.':FUP',
      ) || $changed;
    }

    return $changed;
  }

  private function upsertManagedPppProfile(
    Router $router,
    string $name,
    string $rateLimit,
    string $ownerComment,
  ): bool {
    $client = $this->client($router);
    $rows = collect($client->query('/ppp/profile/print')->read())
      ->filter(fn (array $row) => ($row['name'] ?? null) === $name)
      ->values();
    if ($rows->count() > 1) {
      throw new RuntimeException("Profil PPP '{$name}' ditemukan lebih dari sekali; sinkronisasi dibatalkan.");
    }

    $existing = $rows->first();
    if ($existing && ($existing['comment'] ?? '') !== $ownerComment) {
      throw new RuntimeException("Nama profil '{$name}' sudah dipakai profil lain. Billing tidak menimpa profil tersebut.");
    }

    $changed = ! $existing
      || ($existing['rate-limit'] ?? '') !== $rateLimit
      || ($existing['only-one'] ?? 'no') !== 'yes'
      || ($existing['change-tcp-mss'] ?? '') !== 'yes'
      || ($existing['comment'] ?? '') !== $ownerComment;

    $query = new Query($existing ? '/ppp/profile/set' : '/ppp/profile/add');
    $query->equal('name', $name)
      ->equal('rate-limit', $rateLimit)
      ->equal('only-one', 'yes')
      ->equal('change-tcp-mss', 'yes')
      ->equal('comment', $ownerComment);
    if ($existing) {
      if (! isset($existing['.id'])) {
        throw new RuntimeException("ID profil PPP '{$name}' tidak tersedia.");
      }
      $query->equal('.id', $existing['.id']);
    }

    $client->query($query)->read();
    $verified = collect($client->query('/ppp/profile/print')->read())
      ->first(fn (array $row) => ($row['name'] ?? null) === $name);
    if (! $verified
      || ($verified['comment'] ?? '') !== $ownerComment
      || ($verified['rate-limit'] ?? null) !== $rateLimit
      || ($verified['only-one'] ?? null) !== 'yes') {
      throw new RuntimeException("Profil PPP '{$name}' tidak cocok setelah sinkronisasi.");
    }

    return $changed;
  }

  public function deleteManagedPppProfile(Router $router, string $name, string $expectedComment): bool
  {
    $client = $this->client($router);
    $profiles = collect($client->query('/ppp/profile/print')->read())
      ->filter(fn (array $row) => ($row['name'] ?? null) === $name)
      ->values();
    if ($profiles->isEmpty()) {
      return false;
    }
    if ($profiles->count() !== 1 || ! isset($profiles[0]['.id'])
      || ($profiles[0]['comment'] ?? '') !== $expectedComment) {
      throw new RuntimeException("Profil PPP '{$name}' bukan profil milik paket billing; profil tidak dihapus.");
    }

    $secrets = $client->query('/ppp/secret/print')->read();
    if (collect($secrets)->contains(fn (array $row) => ($row['profile'] ?? null) === $name)) {
      throw new RuntimeException("Profil PPP '{$name}' masih digunakan secret pada router.");
    }

    $client->query((new Query('/ppp/profile/remove'))->equal('.id', $profiles[0]['.id']))->read();
    return true;
  }

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
  public function syncHotspotProfile(HotspotProfile $profile): bool
  {
    $router = $profile->router;
    if (! $router || ! $router->enabled) {
      throw new RuntimeException('Router profil Hotspot tidak tersedia atau dinonaktifkan.');
    }

    $client = $this->client($router);
    $normalName = $profile->routerProfileName();
    $fupName = $normalName.'-FUP';
    $onLogin = $this->managedHotspotOnLoginScript($profile->bind_mac);
    $normalOwner = 'VIKSUM:HOTSPOT:'.$profile->id.':NORMAL';
    $fupOwner = 'VIKSUM:HOTSPOT:'.$profile->id.':FUP';
    $changedProfiles = [];
    $movedFromFup = [];

    if ($this->upsertHotspotRouterProfile(
      $client,
      $normalName,
      $profile->upload_speed.'/'.$profile->download_speed,
      $profile->shared_users,
      $onLogin,
      $normalOwner,
    )) {
      $changedProfiles[] = $normalName;
    }

    if ($profile->fup_limit_bytes > 0 && filled($profile->fup_upload_speed) && filled($profile->fup_download_speed)) {
      if ($this->upsertHotspotRouterProfile(
        $client,
        $fupName,
        $profile->fup_upload_speed.'/'.$profile->fup_download_speed,
        $profile->shared_users,
        $onLogin,
        $fupOwner,
      )) {
        $changedProfiles[] = $fupName;
      }
    } else {
      // If FUP was removed in billing, restore only users assigned to the owned
      // FUP profile before removing it from the router.
      $users = $client->query('/ip/hotspot/user/print')->read();
      foreach ($users as $user) {
        if (($user['profile'] ?? null) !== $fupName) {
          continue;
        }
        $username = (string) ($user['name'] ?? '');
        $id = $user['.id'] ?? null;
        if ($username === '' || ! $id) {
          throw new RuntimeException("Akun pada profil '{$fupName}' tidak dapat diidentifikasi; profil FUP tidak dihapus.");
        }
        $client->query((new Query('/ip/hotspot/user/set'))
          ->equal('.id', $id)
          ->equal('profile', $normalName))->read();
        $movedFromFup[] = $username;
        HotspotVoucher::query()
          ->where('router_id', $router->id)
          ->where('username', $username)
          ->update(['fup_applied' => false]);
      }

      $remainingUsers = $client->query('/ip/hotspot/user/print')->read();
      if (collect($remainingUsers)->contains(fn (array $user) => ($user['profile'] ?? null) === $fupName)) {
        throw new RuntimeException("Masih ada user yang menggunakan profil FUP '{$fupName}'.");
      }
      $routerProfiles = collect($client->query('/ip/hotspot/user/profile/print')->read())
        ->filter(fn (array $row) => ($row['name'] ?? null) === $fupName)
        ->values();
      if ($routerProfiles->count() > 1) {
        throw new RuntimeException("Profil RouterOS '{$fupName}' ditemukan lebih dari sekali; penghapusan dibatalkan.");
      }
      if ($routerProfiles->isNotEmpty()) {
        if (($routerProfiles[0]['comment'] ?? '') !== $fupOwner || ! isset($routerProfiles[0]['.id'])) {
          throw new RuntimeException("Profil '{$fupName}' bukan profil FUP milik billing; tidak dihapus.");
        }
        $client->query((new Query('/ip/hotspot/user/profile/remove'))->equal('.id', $routerProfiles[0]['.id']))->read();
      }
    }

    // RouterOS builds dynamic queues on login. Reconnect only sessions affected
    // by a changed managed profile, or users moved away from an obsolete FUP profile.
    $managedUsers = collect();
    if ($changedProfiles !== []) {
      $managedUsers = collect($client->query('/ip/hotspot/user/print')->read())
        ->filter(fn (array $user) => in_array($user['profile'] ?? null, $changedProfiles, true))
        ->pluck('name')
        ->filter(fn ($name) => is_string($name) && $name !== '');
    }
    $managedUsers = $managedUsers->merge($movedFromFup)->unique()->values();
    if ($managedUsers->isNotEmpty()) {
      $wanted = array_fill_keys($managedUsers->all(), true);
      foreach ($client->query('/ip/hotspot/active/print')->read() as $session) {
        $username = (string) ($session['user'] ?? '');
        if ($username !== '' && isset($wanted[$username]) && isset($session['.id'])) {
          $client->query((new Query('/ip/hotspot/active/remove'))->equal('.id', $session['.id']))->read();
        }
      }
    }

    return $changedProfiles !== [] || $movedFromFup !== [];
  }

  private function upsertHotspotRouterProfile(
    Client $client,
    string $name,
    string $rateLimit,
    int $sharedUsers,
    string $onLogin,
    string $ownerComment,
  ): bool {
    $rows = collect($client->query('/ip/hotspot/user/profile/print')->read())
      ->filter(fn (array $row) => ($row['name'] ?? null) === $name)
      ->values();
    if ($rows->count() > 1) {
      throw new RuntimeException("Profil RouterOS '{$name}' ditemukan lebih dari sekali; sinkronisasi dihentikan.");
    }

    $existing = $rows->first();
    if ($existing && ($existing['comment'] ?? '') !== $ownerComment) {
      throw new RuntimeException("Nama profil '{$name}' sudah dipakai profil yang bukan milik billing. Profil itu tidak ditimpa.");
    }

    $changed = ! $existing
      || ($existing['rate-limit'] ?? null) !== $rateLimit
      || (int) ($existing['shared-users'] ?? 1) !== max(1, $sharedUsers)
      || ($existing['on-login'] ?? null) !== $onLogin
      || ($existing['comment'] ?? null) !== $ownerComment;

    $query = new Query($existing ? '/ip/hotspot/user/profile/set' : '/ip/hotspot/user/profile/add');
    $query->equal('name', $name)
      ->equal('rate-limit', $rateLimit)
      ->equal('shared-users', (string) max(1, $sharedUsers))
      ->equal('on-login', $onLogin)
      ->equal('comment', $ownerComment);

    if ($existing) {
      $id = $existing['.id'] ?? null;
      if (! $id) {
        throw new RuntimeException("ID profil RouterOS '{$name}' tidak tersedia.");
      }
      $query->equal('.id', $id);
    }

    $client->query($query)->read();
    $verified = collect($client->query('/ip/hotspot/user/profile/print')->read())
      ->first(fn (array $row) => ($row['name'] ?? null) === $name);
    if (! $verified
      || ($verified['comment'] ?? null) !== $ownerComment
      || ($verified['rate-limit'] ?? null) !== $rateLimit
      || (int) ($verified['shared-users'] ?? 1) !== max(1, $sharedUsers)
      || ($verified['on-login'] ?? null) !== $onLogin) {
      throw new RuntimeException("Profil RouterOS '{$name}' tidak cocok setelah sinkronisasi.");
    }

    return $changed;
  }

  private function managedHotspotOnLoginScript(bool $bindMac): string
  {
    $script = ':local u $"user"; :local id [/ip hotspot user find where name=$u]; :if ([:len $id] > 0) do={ :local c [/ip hotspot user get $id comment]; :if ([:find $c "VIKSUM:V:"] = 0) do={ :if ([:find $c "|FIRST="] = nil) do={ /ip hotspot user set $id comment=($c . "|FIRST=" . [/system clock get date] . " " . [/system clock get time]); }';

    if ($bindMac) {
      $script .= ' :local savedMac [/ip hotspot user get $id mac-address]; :if ($savedMac = "00:00:00:00:00:00") do={ /ip hotspot user set $id mac-address=$"mac-address"; }';
    }

    return $script.' } }';
  }

  public function deleteHotspotProfile(HotspotProfile $profile): void
  {
    $router = $profile->router;
    if (! $router || ! $router->enabled) {
      throw new RuntimeException('Router profil Hotspot tidak tersedia atau dinonaktifkan.');
    }

    $client = $this->client($router);
    $names = [$profile->routerProfileName(), $profile->routerProfileName().'-FUP'];
    $users = $client->query('/ip/hotspot/user/print')->read();
    foreach ($users as $user) {
      if (in_array($user['profile'] ?? null, $names, true)) {
        throw new RuntimeException("Profil '{$profile->name}' masih digunakan akun Hotspot di router.");
      }
    }

    $profiles = $client->query('/ip/hotspot/user/profile/print')->read();
    foreach ($names as $name) {
      foreach ($profiles as $row) {
        if (($row['name'] ?? null) === $name && isset($row['.id'])) {
          $client->query((new Query('/ip/hotspot/user/profile/remove'))->equal('.id', $row['.id']))->read();
        }
      }
    }
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
    ?string $expectedPreviousComment = null,
  ): void {
    $client = $this->client($router);
    $profiles = $client->query('/ip/hotspot/user/profile/print')->read();
    if (! collect($profiles)->contains(fn (array $row) => ($row['name'] ?? null) === $profile)) {
      throw new RuntimeException("Profil Hotspot '{$profile}' tidak ditemukan pada router {$router->name}.");
    }

    $users = $client->query('/ip/hotspot/user/print')->read();
    $findByName = function (string $candidate) use ($users): ?array {
      $matches = collect($users)
        ->filter(fn (array $row) => ($row['name'] ?? null) === $candidate)
        ->values();
      if ($matches->count() > 1) {
        throw new RuntimeException("Username Hotspot '{$candidate}' ditemukan lebih dari satu kali pada router.");
      }
      return $matches->first();
    };
    $target = $findByName($username);
    $previous = filled($previousUsername) ? $findByName($previousUsername) : null;

    if ($target && (! $previous || ($target['.id'] ?? null) !== ($previous['.id'] ?? null))) {
      throw new RuntimeException("Username Hotspot '{$username}' sudah ada di MikroTik dan tidak cocok dengan akun pelanggan ini.");
    }

    if ($previous) {
      $actualComment = (string) ($previous['comment'] ?? '');
      $commentMatches = $expectedPreviousComment !== null
        && ($actualComment === $expectedPreviousComment
          || (str_starts_with($expectedPreviousComment, 'VIKSUM:V:')
            && str_starts_with($actualComment, $expectedPreviousComment.'|FIRST=')));
      if (! $commentMatches) {
        throw new RuntimeException("Akun Hotspot lama '{$previousUsername}' tidak memiliki penanda kepemilikan yang cocok; akun tidak diubah.");
      }
    }

    // Preserve a first-login marker if the scheduled worker has not yet copied it
    // into the billing database at the moment of a manual resync.
    $commentToWrite = $comment;
    if ($previous
      && $expectedPreviousComment !== null
      && str_starts_with($expectedPreviousComment, 'VIKSUM:V:')
      && str_starts_with((string) ($previous['comment'] ?? ''), $expectedPreviousComment.'|FIRST=')) {
      $commentToWrite = (string) $previous['comment'];
    }

    $query = new Query($previous ? '/ip/hotspot/user/set' : '/ip/hotspot/user/add');
    $query->equal('name', $username)
      ->equal('password', $password)
      ->equal('profile', $profile)
      ->equal('comment', $commentToWrite)
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

  public function setManagedHotspotUserEnabled(
    Router $router,
    string $username,
    bool $enabled,
    string $expectedComment,
  ): void {
    $client = $this->client($router);
    $rows = $client->query((new Query('/ip/hotspot/user/print'))->where('name', $username))->read();
    $matches = collect($rows)->filter(fn (array $row) => ($row['name'] ?? null) === $username)->values();

    if ($matches->count() !== 1 || ! isset($matches[0]['.id'])) {
      throw new RuntimeException("Akun Hotspot '{$username}' tidak ditemukan secara unik di router.");
    }

    $actualComment = (string) ($matches[0]['comment'] ?? '');
    $owned = $actualComment === $expectedComment
      || (str_starts_with($expectedComment, 'VIKSUM:V:')
        && str_starts_with($actualComment, $expectedComment.'|FIRST='));
    if (! $owned) {
      throw new RuntimeException("Akun Hotspot '{$username}' tidak memiliki penanda kepemilikan yang cocok; status tidak diubah.");
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

  /**
   * Delete only a RouterOS user whose comment proves ownership by this billing
   * record. Returns false if the router account is already absent.
   */
  public function deleteManagedHotspotUser(Router $router, string $username, string $expectedComment): bool
  {
    $client = $this->client($router);
    $rows = $client->query((new Query('/ip/hotspot/user/print'))->where('name', $username))->read();
    $matches = collect($rows)->filter(fn (array $row) => ($row['name'] ?? null) === $username)->values();

    if ($matches->isEmpty()) {
      return false;
    }
    if ($matches->count() !== 1 || ! isset($matches[0]['.id'])) {
      throw new RuntimeException("Akun Hotspot '{$username}' tidak ditemukan secara unik; penghapusan dibatalkan.");
    }

    $actualComment = (string) ($matches[0]['comment'] ?? '');
    $owned = $actualComment === $expectedComment
      || (str_starts_with($expectedComment, 'VIKSUM:V:')
        && str_starts_with($actualComment, $expectedComment.'|FIRST='));
    if (! $owned) {
      throw new RuntimeException("Akun Hotspot '{$username}' tidak memiliki penanda kepemilikan yang cocok; akun tidak dihapus.");
    }

    $this->disconnectHotspotActive($router, $username);
    $client->query((new Query('/ip/hotspot/user/remove'))->equal('.id', $matches[0]['.id']))->read();

    return true;
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
