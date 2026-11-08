<?php

namespace Tests\Unit;

use App\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Tests\TestCase;

// En el server, PHP-FPM sólo lo alcanza el nginx del compose: la IP que ve
// Laravel es la del contenedor y la del usuario real viene en X-Forwarded-For.
// Sin confiar en ese header, el historial y "Sesiones" guardan la misma
// dirección para toda la escuela y el cupo de pedidos por IP se reparte entre
// todos. nginx pisa el header con $remote_addr (docker/nginx/default.conf), así
// que el navegador no puede falsificarlo: acá se prueba la mitad de Laravel.
class ProxyIpTest extends TestCase
{
    private function pasarPorProxy(Request $r): Request
    {
        return (new TrustProxies())->handle($r, fn($x) => $x);
    }

    private function pedido(string $ipDelProxy): Request
    {
        return Request::create('http://192.168.1.20/api/login', 'POST', [], [], [], [
            'REMOTE_ADDR' => $ipDelProxy,
        ]);
    }

    public function test_toma_la_ip_real_del_usuario(): void
    {
        $r = $this->pedido('172.18.0.4');
        $r->headers->set('X-Forwarded-For', '192.168.1.55');

        $this->assertSame('192.168.1.55', $this->pasarPorProxy($r)->ip());
    }

    public function test_sin_proxy_queda_la_ip_del_pedido(): void
    {
        $r = $this->pedido('192.168.1.55');

        $this->assertSame('192.168.1.55', $this->pasarPorProxy($r)->ip());
    }

    public function test_no_confia_el_host_ni_el_puerto_que_manda_el_navegador(): void
    {
        $r = $this->pedido('172.18.0.4');
        $r->headers->set('X-Forwarded-For', '192.168.1.55');
        $r->headers->set('X-Forwarded-Host', 'falsificado.ejemplo');
        $r->headers->set('X-Forwarded-Port', '6666');

        $r = $this->pasarPorProxy($r);

        $this->assertSame('192.168.1.20', $r->getHost());
        $this->assertNotSame('6666', (string) $r->getPort());
    }

    public function test_el_esquema_lo_informa_el_proxy(): void
    {
        $r = $this->pedido('172.18.0.4');
        $r->headers->set('X-Forwarded-For', '192.168.1.55');
        $r->headers->set('X-Forwarded-Proto', 'https');

        $this->assertTrue($this->pasarPorProxy($r)->isSecure());
    }
}