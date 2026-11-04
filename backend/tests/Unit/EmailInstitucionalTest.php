<?php

namespace Tests\Unit;

use App\Support\Rules\EmailInstitucional;
use Tests\TestCase;

// Todas las cuentas son @abc.gob.ar salvo la del admin inicial.
class EmailInstitucionalTest extends TestCase
{
    private function pasa(?string $email): ?string
    {
        $fallo = null;
        (new EmailInstitucional)->validate('email', $email, function ($msg) use (&$fallo) {
            $fallo = $msg;
        });

        return $fallo;
    }

    public function test_acepta_institucional_y_admin(): void
    {
        $this->assertNull($this->pasa('preceptor@abc.gob.ar'));
        $this->assertNull($this->pasa('PRECEPTOR@ABC.GOB.AR'));
        $this->assertNull($this->pasa('  admin@admin.com  '));
    }

    public function test_rechaza_otro_dominio(): void
    {
        $this->assertSame('El correo tiene que ser @abc.gob.ar.', $this->pasa('profe@gmail.com'));
        $this->assertSame('El correo tiene que ser @abc.gob.ar.', $this->pasa('admin@admin.com.ar'));
        $this->assertSame('El correo tiene que ser @abc.gob.ar.', $this->pasa('no-es-un-correo'));
    }
}
