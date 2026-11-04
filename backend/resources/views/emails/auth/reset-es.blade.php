@php
    $nombre = trim(($user->name ?? '')) ?: 'Hola';
@endphp

@component('mail::message')
# Sistema Escolar • EES N°12

## {{ $nombre }},

Recibimos una solicitud para **restablecer la contraseña** de tu cuenta.

@component('mail::button', ['url' => $url])
Restablecer contraseña
@endcomponent

Este enlace es válido por **{{ $minutes }} minutos**.

Si **no** fuiste vos quien solicitó el cambio, podés **ignorar este correo**; tu contraseña actual seguirá siendo válida.

---

Si el botón no funciona, copiá y pegá este enlace en tu navegador:

{{ $url }}

Gracias,  
**Sistema Escolar – EES N°12**
@endcomponent
