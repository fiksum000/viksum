<?php

namespace Tests\Feature;

use App\Support\IsolationScript;
use Tests\TestCase;

class IsolationScriptTest extends TestCase
{
    public function test_generated_script_uses_documented_redirect_syntax_and_restricts_proxy_access(): void
    {
        $script = IsolationScript::forBillingUrl(
            'https://billing.fiksum.my.id/',
            'billing.fiksum.my.id',
            'ISOLIR'
        );

        $this->assertStringContainsString(
            '/ip proxy access add action=redirect action-data="https://billing.fiksum.my.id/isolir" local-port=8097',
            $script
        );
        $this->assertStringContainsString(
            '/ip firewall filter add chain=input src-address-list="FIKSUM-ISOLIR" protocol=tcp dst-port=8097 action=accept',
            $script
        );
        $this->assertStringContainsString(
            '/ip firewall filter add chain=input protocol=tcp dst-port=8097 action=drop',
            $script
        );
        $this->assertStringNotContainsString('redirect-to=', $script);
        $this->assertStringNotContainsString('/ip firewall nat disable', $script);
        $this->assertStringNotContainsString('/ip proxy access disable', $script);
    }

    public function test_routeros_string_values_escape_quotes_and_newlines(): void
    {
        $script = IsolationScript::forBillingUrl(
            'https://billing.example.test',
            "portal.example.test\"\n/ip firewall nat remove [find]",
            'ISOLIR'
        );

        $this->assertStringContainsString(
            'dst-host="portal.example.test\\"\\n/ip firewall nat remove [find]"',
            $script
        );
    }
}
