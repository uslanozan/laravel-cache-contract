<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Tests\Fixtures\Scanner;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

// Scanner fixture: the framework using the cache store on its own. Parsed by ScannerTest, never executed.
final class IndirectUsage
{
    /** @var array<string, list<string>> as in an app/Http/Kernel.php */
    protected array $middlewareGroups = ['api' => ['throttle:api', 'bindings']];

    public function handle(object $user, object $route): void
    {
        RateLimiter::hit('login:'.$user->id);
        RateLimiter::attempt("send-message:{$user->id}", 5, fn () => true);
        \RateLimiter::clear('login:'.$user->id);

        Route::middleware('throttle:60,1')->group(fn () => null);
        $route->middleware(['auth', 'throttle:uploads']);

        // Not throttle middleware: no limiter after the colon, or no colon at all.
        $route->middleware('throttle');
        $route->name('throttle:');
    }
}
