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

        // Not cache usage: limiter definitions, and strings that are not throttle middleware.
        RateLimiter::for('api', fn () => null);
        RateLimiter::limiter('api');
        $route->middleware('throttle');
        $route->name('throttle:');
        __('throttle:limit reached');
    }
}
