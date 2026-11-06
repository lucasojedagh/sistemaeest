<?php

// Proveedor de servicios de rutas. Define limitadores de frecuencia y carga los archivos de rutas.

namespace App\Providers;

use App\Dominios\Usuarios\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    // Configura limitadores de frecuencia y carga las rutas de api.php y web.php.
    public function boot(): void
    {
        // El limitador 'api' corre ANTES del middleware de sesion (auth.token),
        // asi que aca $request->user() todavia es null. Si se usa la IP, en el
        // colegio —donde todos salen por la misma direccion— los 60 pedidos por
        // minuto se reparten entre todas las cuentas abiertas y la pantalla
        // empieza a responder "Demasiados intentos". Por eso la cuenta se saca
        // de la misma caché que llena auth.token (5 minutos) y cada usuario tiene
        // su propio cupo. Sin sesion valida se sigue agrupando por IP, que es lo
        // que frena a quien manda tokens inventados.
        RateLimiter::for('api', function (Request $request) {
            $token = (string) $request->bearerToken();
            if ($token !== '') {
                $dueno = Cache::get('auth_token_' . $token);
                if ($dueno instanceof User && $dueno->id) {
                    return Limit::perMinute(180)->by('usuario:' . $dueno->id);
                }
            }

            return Limit::perMinute(60)->by($request->ip());
        });

        // Login: 5 intentos por minuto POR CUENTA (email+IP), no por IP sola.
        // Así, en una escuela donde todos salen por la misma IP, los errores
        // de uno no bloquean al resto.
        RateLimiter::for('login', function (Request $request) {
            // Si el email llega como array u otro tipo raro, se usa ''
            // en vez de romper con un 500.
            $email = $request->input('email', '');
            $email = is_string($email) ? strtolower(trim($email)) : '';
            return Limit::perMinute(5)->by($email . '|' . $request->ip());
        });

        // Recupero de contraseña: 5 intentos cada 10 minutos POR CUENTA.
        // (Por IP sola, 3 personas pidiendo el link en una hora bloqueaban
        // a todo el colegio por casi una hora.)
        RateLimiter::for('password', function (Request $request) {
            $email = $request->input('email', '');
            $email = is_string($email) ? strtolower(trim($email)) : '';
            return Limit::perMinutes(10, 5)->by($email . '|' . $request->ip());
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}
