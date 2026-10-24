<?php
namespace App\Http\Middleware;

use App\Services\LanguageRegistry;
use Closure;
use Illuminate\Http\Request;

class SetLocale
{
    public function __construct(private LanguageRegistry $languages) {}

    public function handle(Request $request, Closure $next)
    {
        $hint = $request->header('X-WojteX-Locale');
        if ($request->is('install')) $hint = $request->isMethod('GET') ? $request->query('lang', $hint) : $request->input('locale', $hint);
        $session = $request->hasSession() ? $request->session()->get('locale') : null;
        $locale = is_string($hint) && $this->languages->has($hint) ? $hint : (is_string($session) && $this->languages->has($session) ? $session : $this->languages->defaultLocale());
        $this->languages->activate($locale);
        if ($request->is('install') && $request->isMethod('GET') && is_string($hint) && $this->languages->has($hint) && $request->hasSession()) $request->session()->put('locale', $locale);
        $response = $next($request);
        $response->headers->set('Content-Language', app()->getLocale());
        $response->setVary(['Cookie', 'X-WojteX-Locale'], false);
        return $response;
    }
}
