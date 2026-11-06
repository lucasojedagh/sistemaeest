<?php

namespace App\Dominios\Usuarios;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

// ⬇️ Usamos tu notificación personalizada en español
use App\Notifications\ResetPasswordEs;

/**
 * Modelo que representa un usuario del sistema (autenticacion).
 *
 * Tabla: users
 * Extiende Authenticatable de Laravel. Incluye roles, token API propio
 * (columna users.api_token, ver AuthToken) y notificaciones de
 * restablecimiento de contrasena en espanol.
 */
class User extends Authenticatable
{
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * Agregamos role y api_token porque los seteás vía create().
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'apellido',
        'dni',
        'email',
        'password',
        'role',
        'rol_solicitado',
        'status',
        'api_token',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'api_token',      // ocultamos el token en respuestas JSON
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login_at'     => 'datetime',  // último acceso
        'password'          => 'hashed',
        'debe_cambiar_password' => 'boolean',
    ];

    /**
     * La version de la foto viaja en toda respuesta que serialice un usuario:
     * es la clave con la que el front guarda y pide la imagen, así una foto
     * nueva reemplaza a la vieja en pantalla sin recargar nada.
     */
    protected $appends = ['avatar_version'];

    /**
     * Numero del archivo (milisegundos de la subida) guardado en
     * avatars/{id}/{version}.webp. Null si la cuenta no tiene foto.
     */
    public function getAvatarVersionAttribute(): ?int
    {
        $ruta = $this->attributes['avatar'] ?? null;
        if (!$ruta) {
            return null;
        }

        $version = (int) basename((string) $ruta);
        return $version > 0 ? $version : null;
    }

    /** Relacion: anios y divisiones a las que el usuario tiene acceso */
    public function anioDivisiones()
    {
        return $this->hasMany(UserAnioDivision::class, 'user_id');
    }

    /**
     * Forzamos a usar la notificación de restablecimiento en ES.
     * (Sobrescribe la default de Laravel)
     */
    public function sendPasswordResetNotification($token): void
    {
        $notification = new ResetPasswordEs($token);

        // Asegura español aunque app.locale no sea "es"
        $notification->locale('es');

        $this->notify($notification);
    }
}
