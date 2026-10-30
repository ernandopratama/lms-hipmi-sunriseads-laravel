<?php
namespace App\Http\Middleware;
use App\Support\WebSession;
use Closure;
use Illuminate\Http\Request;
class EnsureWebSession
{
    public function handle(Request $r, Closure $next)
    {
        app(WebSession::class)->touch($r);
        return $next($r);
    }
}
