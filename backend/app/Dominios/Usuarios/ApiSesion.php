<?php

namespace App\Dominios\Usuarios;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Una sesion abierta: un ingreso al sistema desde un equipo/navegador.
 *
 * Permite tener varias sesiones vivas por persona (PC del aula + celular) y
 * cerrar una sola sin terminar las demas.
 */
class ApiSesion extends Model
{
    // Días sin usar el sistema antes de que el token deje de servir. Es largo a
    // propósito: nadie trabaja una semana y vuelve al mes, y si vence, el
    // frontend manda al login y se entra de nuevo. Evita que un token robado o
    // un equipo olvidado siga vivo para siempre.
    public const INACTIVIDAD_DIAS = 30;

    protected $table = 'api_sesiones';

    protected $fillable = [
        'user_id', 'token', 'dispositivo', 'ip_address', 'user_agent',
        'ultimo_uso_at',
    ];

    protected $hidden = ['token', 'user_agent'];

    protected $casts = [
        'ultimo_uso_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Registra un ingreso nuevo y devuelve su token. */
    public static function abrir(int $userId, Request $request, ?string $token = null): self
    {
        $ua = (string) $request->userAgent();

        return static::create([
            'user_id'       => $userId,
            'token'         => $token ?: \Illuminate\Support\Str::random(60),
            'dispositivo'   => self::describirDispositivo($ua),
            'ip_address'    => $request->ip(),
            'user_agent'    => mb_substr($ua, 0, 500),
            'ultimo_uso_at' => now(),
        ]);
    }

    /** Cierra todas las sesiones de una persona (cambio de clave, baja, etc.). */
    public static function cerrarTodas(int $userId): int
    {
        return static::where('user_id', $userId)->delete();
    }

    /**
     * Marca el uso sin escribir en cada pedido: solo si la ultima visita tiene
     * mas de 5 minutos, para no convertir cada request en un UPDATE.
     */
    public function marcarUso(): void
    {
        if (!$this->ultimo_uso_at || $this->ultimo_uso_at->lt(Carbon::now()->subMinutes(5))) {
            $this->forceFill(['ultimo_uso_at' => now()])->saveQuietly();
        }
    }

    /**
     * La sesión sigue viva mientras se haya usado dentro de la ventana de
     * inactividad. Sin marca de uso se toma la fecha de alta.
     */
    public function estaViva(): bool
    {
        $limite = Carbon::now()->subDays(self::INACTIVIDAD_DIAS);
        $ultima = $this->ultimo_uso_at ?? $this->created_at;

        return $ultima !== null && $ultima->gt($limite);
    }

    /** Traduce el user agent a algo entendible: "Chrome en Windows". */
    public static function describirDispositivo(string $ua): string
    {
        if ($ua === '') return 'Equipo desconocido';

        $navegador = 'Navegador';
        foreach (['Edg' => 'Edge', 'OPR' => 'Opera', 'Chrome' => 'Chrome',
                  'Firefox' => 'Firefox', 'Safari' => 'Safari'] as $marca => $nombre) {
            if (stripos($ua, $marca) !== false) { $navegador = $nombre; break; }
        }

        $sistema = 'Sistema desconocido';
        if (stripos($ua, 'Windows') !== false)      $sistema = 'Windows';
        elseif (stripos($ua, 'iPhone') !== false)   $sistema = 'iPhone';
        elseif (stripos($ua, 'iPad') !== false)     $sistema = 'iPad';
        elseif (stripos($ua, 'Android') !== false)  $sistema = 'Android';
        elseif (stripos($ua, 'Mac OS') !== false)   $sistema = 'Mac';
        elseif (stripos($ua, 'Linux') !== false)    $sistema = 'Linux';

        return $navegador . ' en ' . $sistema;
    }
}
