<?php

namespace App\Support;

final class IsolationScript
{
    public static function forBillingUrl(string $billingUrl, string $billingHost, string $profileName): string
    {
        $billingUrl = rtrim($billingUrl, '/');

        return str_replace(
            ['__BILLING_URL__', '__ISOLATION_URL__', '__BILLING_HOST__', '__ISOLATION_PROFILE__'],
            [
                self::routerString($billingUrl),
                self::routerString($billingUrl.'/isolir'),
                self::routerString($billingHost),
                self::routerString($profileName),
            ],
            <<<'ROUTEROS'
# Halaman isolir Billing RTRW Net — tinjau sebelum diterapkan.
# Script ini MENGUBAH profil PPP, Web Proxy, NAT, dan filter input.
# Pastikan port proxy 8097 belum dipakai; perubahan port Web Proxy dapat
# memengaruhi konfigurasi proxy lain. Simpan backup dan uji saat maintenance.
# APP_URL: __BILLING_URL__
/export file=before-fiksum-isolir

# Profile isolir menandai IP PPP pelanggan yang sedang memakai profile ini.
:local isolirProfile [/ppp profile find where name=__ISOLATION_PROFILE__]
:if ([:len $isolirProfile] = 0) do={
    /ppp profile add copy-from=default name=__ISOLATION_PROFILE__ address-list="FIKSUM-ISOLIR" rate-limit=64k/64k
} else={
    /ppp profile set $isolirProfile address-list="FIKSUM-ISOLIR"
}

# Web Proxy menerima hanya koneksi port 8097 dari IP pada address-list isolir.
/ip proxy set enabled=yes port=8097
/ip firewall filter remove [find where comment="FIKSUM-ISOLIR-Proxy-Allow"]
/ip firewall filter remove [find where comment="FIKSUM-ISOLIR-Proxy-Deny"]
:if ([:len [/ip firewall filter find]] > 0) do={
    /ip firewall filter add chain=input protocol=tcp dst-port=8097 action=drop comment="FIKSUM-ISOLIR-Proxy-Deny" place-before=0
} else={
    /ip firewall filter add chain=input protocol=tcp dst-port=8097 action=drop comment="FIKSUM-ISOLIR-Proxy-Deny"
}
/ip firewall filter add chain=input src-address-list="FIKSUM-ISOLIR" protocol=tcp dst-port=8097 action=accept comment="FIKSUM-ISOLIR-Proxy-Allow" place-before=0

# Hapus hanya rule FIKSUM sebelumnya; jangan ubah rule milik vendor lain.
/ip proxy access remove [find where comment="FIKSUM-ISOLIR-Allow-Portal"]
/ip proxy access remove [find where comment="FIKSUM-ISOLIR-Redirect"]
/ip firewall nat remove [find where comment="FIKSUM-ISOLIR-HTTP"]

# Allow portal lebih dulu, lalu redirect HTTP lain melalui Web Proxy.
:if ([:len [/ip proxy access find]] > 0) do={
    /ip proxy access add action=redirect action-data=__ISOLATION_URL__ local-port=8097 comment="FIKSUM-ISOLIR-Redirect" place-before=0
} else={
    /ip proxy access add action=redirect action-data=__ISOLATION_URL__ local-port=8097 comment="FIKSUM-ISOLIR-Redirect"
}
/ip proxy access add action=allow dst-host=__BILLING_HOST__ comment="FIKSUM-ISOLIR-Allow-Portal" place-before=0

# Redirect hanya HTTP port 80 dari pelanggan dengan profile isolir.
# HTTPS tidak dapat dialihkan transparan oleh Web Proxy RouterOS.
:if ([:len [/ip firewall nat find]] > 0) do={
    /ip firewall nat add chain=dstnat src-address-list="FIKSUM-ISOLIR" protocol=tcp dst-port=80 action=redirect to-ports=8097 comment="FIKSUM-ISOLIR-HTTP" place-before=0
} else={
    /ip firewall nat add chain=dstnat src-address-list="FIKSUM-ISOLIR" protocol=tcp dst-port=80 action=redirect to-ports=8097 comment="FIKSUM-ISOLIR-HTTP"
}

# Rule MSRadius lama tidak diubah otomatis. Tinjau manual hanya jika bertabrakan.
ROUTEROS
        );
    }

    private static function routerString(string $value): string
    {
        $escaped = str_replace(
            ["\\", '"', "\r", "\n"],
            ["\\\\", '\\"', '\\r', '\\n'],
            $value
        );

        return '"'.$escaped.'"';
    }
}
