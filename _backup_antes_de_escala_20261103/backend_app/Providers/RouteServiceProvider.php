<?php

// Proveedor de servicios de rutas. Define limitadores de frecuencia y carga los archivos de rutas.

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
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
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
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
