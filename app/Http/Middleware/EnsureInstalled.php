<?php
namespace App\Http\Middleware;
use App\Services\ForumService;
use Closure;
use Illuminate\Http\Request;
class EnsureInstalled {
    public function handle(Request $request, Closure $next) {
        if (!ForumService::installed()) return $request->is('api/*') ? response()->json(['message'=>__('server.install_first'),'install_url'=>'/install'],503) : redirect('/install');
        if ($request->user()?->is_banned) {
            auth()->logout(); $request->session()->invalidate(); $request->session()->regenerateToken();
            abort(403,__('server.account_blocked'));
        }
        return $next($request);
    }
}
