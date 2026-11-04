<?php

// Proveedor de servicios de autenticacion. Registra politicas (policies) de autorizacion.

namespace App\Providers;

// use Illuminate\Support\Facades\Gate;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        //
    ];

    /**
     * Register any authentication / authorization services.
     */
    // Registra las politicas de autorizacion y cualquier logica de Gates.
    public function boot(): void
    {
        //
    }
}
