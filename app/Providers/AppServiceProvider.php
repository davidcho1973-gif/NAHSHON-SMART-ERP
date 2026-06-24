<?php

namespace App\Providers;

use App\Models\Employee;
use App\Observers\EmployeePayrollProfileObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Provision a payroll wage profile whenever a new employee is created.
        Employee::observe(EmployeePayrollProfileObserver::class);
    }
}
