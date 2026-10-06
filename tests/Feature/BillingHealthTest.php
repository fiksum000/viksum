<?php
namespace Tests\Feature; use Tests\TestCase;
class BillingHealthTest extends TestCase { public function test_health_endpoint_is_ok():void{$this->get('/up')->assertOk();} }
