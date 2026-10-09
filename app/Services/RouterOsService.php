<?php
namespace App\Services;
use App\Models\Router;
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
  public function listHotspotUsers(Router $router):array{return $this->client($router)->query('/ip/hotspot/user/print')->read();}
  public function listHotspotActive(Router $router):array{return $this->client($router)->query('/ip/hotspot/active/print')->read();}
  public function listHotspotProfiles(Router $router):array{return $this->client($router)->query('/ip/hotspot/user/profile/print')->read();}
  public function createHotspotUser(Router $router,string $username,string $password,string $profile,string $comment=''):void{$this->client($router)->query((new Query('/ip/hotspot/user/add'))->equal('name',$username)->equal('password',$password)->equal('profile',$profile)->equal('comment',$comment))->read();}
  public function setHotspotUserEnabled(Router $router,string $username,bool $enabled):void{$client=$this->client($router);$rows=$client->query((new Query('/ip/hotspot/user/print'))->where('name',$username))->read();$id=$rows[0]['.id']??null;if(!$id)throw new RuntimeException("Hotspot user {$username} tidak ditemukan.");$client->query((new Query('/ip/hotspot/user/set'))->equal('.id',$id)->equal('disabled',$enabled?'no':'yes'))->read();}
  public function disconnectHotspotActive(Router $router,string $username):void{$client=$this->client($router);$rows=$client->query((new Query('/ip/hotspot/active/print'))->where('user',$username))->read();foreach($rows as $row){if(isset($row['.id']))$client->query((new Query('/ip/hotspot/active/remove'))->equal('.id',$row['.id']))->read();}}
    public function deleteHotspotUser(Router $router,string $username):void{$client=$this->client($router);$rows=$client->query((new Query('/ip/hotspot/user/print'))->where('name',$username))->read();foreach($rows as $row){if(isset($row['.id']))$client->query((new Query('/ip/hotspot/user/remove'))->equal('.id',$row['.id']))->read();}}
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
      } catch (\\Throwable) {
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
