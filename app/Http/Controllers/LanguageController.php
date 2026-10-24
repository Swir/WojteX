<?php
namespace App\Http\Controllers;

use App\Services\ForumService;
use App\Services\LanguageRegistry;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LanguageController extends Controller
{
    public function __construct(private LanguageRegistry $languages) {}

    public function choose(Request $request)
    {
        $data = $request->validate(['locale'=>['required','string',Rule::in(array_column($this->languages->catalog(),'code'))]]);
        $request->session()->put('locale', $data['locale']);
        $this->languages->activate($data['locale']);
        return response()->json(['i18n'=>$this->languages->payload()]);
    }

    public function index()
    {
        return response()->json(['languages'=>$this->languages->catalog(),'default_locale'=>$this->languages->defaultLocale()]);
    }

    public function template(Request $request)
    {
        $data = $request->validate(['locale'=>['sometimes','required','string',Rule::in(array_column($this->languages->catalog(),'code'))]]);
        $locale = $data['locale'] ?? 'en';
        $json = json_encode($this->languages->export($locale), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        return response($json,200,['Content-Type'=>'application/json; charset=UTF-8','Content-Disposition'=>'attachment; filename="veyra-language-'.$locale.'.json"','X-Content-Type-Options'=>'nosniff','Cache-Control'=>'private, no-store']);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['file'=>'required|file|max:1024','replace'=>'sometimes|boolean']);
        if (strtolower($data['file']->getClientOriginalExtension()) !== 'json') throw ValidationException::withMessages(['file'=>__('server.language_json_extension')]);
        $language = $this->languages->install(file_get_contents($data['file']->getRealPath()), $request->boolean('replace'));
        ForumService::audit('language.installed',['locale'=>$language['code'],'version'=>$language['version'],'coverage'=>$language['coverage']]);
        return response()->json(['language'=>$language,'languages'=>$this->languages->catalog()],201);
    }
}
