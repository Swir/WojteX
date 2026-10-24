<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="color-scheme" content="dark">
    <title>{{ __('install.page_title') }} — WojteX Forum</title>
    <style>
        *{box-sizing:border-box}body{margin:0;background:#0c101c;color:#e9edf8;font:15px/1.6 system-ui,sans-serif;min-height:100vh;background-image:radial-gradient(ellipse at 15% 10%,#29204c 0,transparent 50%)}a{color:#a99aff}main{max-width:1120px;margin:auto;padding:48px 24px;display:grid;grid-template-columns:1fr 1.15fr;gap:56px}.brand{display:flex;align-items:center;gap:12px;font-size:25px;font-weight:800;letter-spacing:2px}.mark{background:linear-gradient(145deg,#9b7aff,#5e45dc);width:44px;height:44px;display:grid;place-items:center;border-radius:13px;letter-spacing:-5px;padding-right:5px}.by{font-size:11px;letter-spacing:0;color:#8391ad}.eyebrow{color:#a99aff;text-transform:uppercase;font-size:11px;letter-spacing:3px;margin:38px 0 16px}h1{font-size:43px;line-height:1.15;letter-spacing:-1.8px;margin:0 0 22px}p{color:#97a3ba}.checks{list-style:none;padding:0;margin:28px 0}.checks li{padding:7px 0;color:#b7c2d6;font-size:13px}.ok{color:#52d8b0}.bad{color:#ff8697}.card{background:#141a2a;border:1px solid #29314b;border-radius:22px;padding:30px;box-shadow:0 25px 70px #0003}h2{margin:0 0 5px;font-size:23px}.intro{font-size:13px;margin:0 0 22px}label{display:block;margin:16px 0 6px;font-size:12px;font-weight:650;color:#c4cde0}input:not([type=checkbox]):not([type=radio]),select{display:block;width:100%;background:#0e1422;border:1px solid #303952;border-radius:10px;color:#f0f2fc;padding:12px 14px;font:inherit;outline:none}input:focus-visible,select:focus-visible,button:focus-visible{outline:2px solid #b6a2ff;outline-offset:3px}.two{display:grid;grid-template-columns:1fr 1fr;gap:14px}.hint{font-size:11px;color:#99a6be;margin:8px 0}.check{display:flex;gap:10px;align-items:flex-start;font-weight:400;font-size:12px;margin:20px 0}.check input{accent-color:#9173ff;margin-top:4px;flex-shrink:0}button{width:100%;border:0;border-radius:11px;background:linear-gradient(100deg,#9170ff,#7456e4);color:white;font:700 14px system-ui;padding:15px;cursor:pointer}button:disabled{opacity:.5;cursor:not-allowed}.errors{background:#3b202b;border:1px solid #773847;color:#ffd2da;padding:12px 18px 12px 30px;border-radius:10px;font-size:13px}.footer{font-size:11px;color:#8996b0;margin-top:25px}.pill{display:inline-block;border:1px solid #594783;color:#b5a1e9;border-radius:20px;padding:4px 10px;font-size:10px;letter-spacing:1px;margin-top:15px}.language-form{margin-top:22px;max-width:340px}.language-controls{display:flex;gap:9px;align-items:center}.language-controls select{padding:9px 12px;font-size:13px;min-width:0}.language-controls button{width:auto;padding:11px 14px;font-size:12px;white-space:nowrap;background:#25243f;border:1px solid #514772;color:#e2d8ff}.modes{border:0;padding:0;margin:22px 0 20px}.modes legend{padding:0;margin-bottom:8px;color:#c4cde0;font-size:12px;font-weight:650}.mode-card{display:flex;gap:11px;align-items:flex-start;border:1px solid #303952;background:#101626;border-radius:11px;padding:13px;margin:8px 0;cursor:pointer}.mode-card:has(input:checked){border-color:#9e86ff;background:#25203d;box-shadow:0 0 0 1px #9e86ff30}.mode-card input{accent-color:#9e86ff;margin-top:4px;flex-shrink:0}.mode-card strong{display:block;color:#edf0fc;font-size:13px}.mode-card small{display:block;color:#a6b2ca;font-size:11px;font-weight:400;line-height:1.6;margin-top:3px}.demo-choice{background:#101626;border-radius:10px;padding:12px}.demo-choice strong{display:block;font-size:12px;color:#d1d9eb}.demo-choice small{display:block;margin-top:3px;color:#99a6be;font-size:11px;line-height:1.6}.start-note{border-left:2px solid #8f71e8;padding-left:14px;font-size:13px;color:#b7c2d6;margin:24px 0}.start-note strong{color:#e4e9f6;display:block;margin-bottom:4px}@media(max-width:800px){main{grid-template-columns:1fr;gap:28px;padding:28px 18px}.eyebrow{margin-top:28px}h1{font-size:34px}.checks{margin:15px 0}.card{padding:23px}.two{grid-template-columns:1fr;gap:0}.pill{margin-top:8px}.language-form{max-width:100%}.start-note{margin:20px 0}}
    </style>
</head>
<body>
<main>
    <section>
        <div class="brand"><span class="mark" aria-hidden="true">&lt;/&gt;</span><div>VEYRA<div class="by">FORUM · by Swir</div></div></div>
        <form method="get" action="/install" class="language-form">
            <label for="install-language">{{ __('install.language.label') }}</label>
            <div class="language-controls">
                <select id="install-language" name="lang">
                    @foreach($languages as $language)
                        <option value="{{ $language['code'] }}" @selected($language['code'] === app()->getLocale())>{{ $language['native_name'] }}</option>
                    @endforeach
                </select>
                <button type="submit">{{ __('install.language.apply') }}</button>
            </div>
            <p class="hint">{{ __('install.language.hint') }}</p>
        </form>
        <div class="eyebrow">{{ __('install.eyebrow') }}</div>
        <h1>{{ __('install.headline.first') }}<br>{{ __('install.headline.second') }}</h1>
        <p>{{ __('install.description') }}</p>
        <div class="start-note"><strong>{{ __('install.clean_start.title') }}</strong>{{ __('install.clean_start.description') }}</div>
        <ul class="checks" aria-label="{{ __('install.checks.heading') }}">
            @foreach($checks as $label => $ok)
                <li><span class="{{ $ok ? 'ok' : 'bad' }}" aria-label="{{ $ok ? __('install.checks.passed') : __('install.checks.failed') }}">{{ $ok ? '✓' : '✕' }}</span> {{ $label }}</li>
            @endforeach
        </ul>
        <span class="pill">{{ \App\Services\ForumService::VERSION }}</span>
        <p class="footer">{{ __('install.footer') }} · by Swir</p>
    </section>
    <section class="card" aria-labelledby="install-heading">
        <h2 id="install-heading">{{ __('install.form.title') }}</h2>
        <p class="intro">{{ __('install.form.intro') }}</p>
        @if($errors->any())
            <ul class="errors" role="alert">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        @endif
        <form method="post" action="/install">
            @csrf
            <input type="hidden" name="locale" value="{{ app()->getLocale() }}">
            <fieldset class="modes">
                <legend>{{ __('install.mode.label') }}</legend>
                <label class="mode-card" for="mode-fresh">
                    <input id="mode-fresh" type="radio" name="setup_mode" value="fresh" @checked(old('setup_mode', 'fresh') !== 'migrate')>
                    <span><strong>{{ __('install.mode.fresh.title') }}</strong><small>{{ __('install.mode.fresh.description') }}</small></span>
                </label>
                <label class="mode-card" for="mode-migrate">
                    <input id="mode-migrate" type="radio" name="setup_mode" value="migrate" @checked(old('setup_mode', 'fresh') === 'migrate')>
                    <span><strong>{{ __('install.mode.migrate.title') }}</strong><small>{{ __('install.mode.migrate.description') }}</small></span>
                </label>
            </fieldset>
            <label for="install_token">{{ __('install.fields.token') }}</label>
            <input id="install_token" name="install_token" required autocomplete="off" maxlength="100">
            <p class="hint">{{ __('install.token_hint', ['path' => 'storage/install-token', 'command' => 'php tools/prepare.php']) }}</p>
            <label for="site_name">{{ __('install.fields.site_name') }}</label>
            <input id="site_name" name="site_name" value="{{ old('site_name', 'WojteX Forum') }}" required minlength="2" maxlength="80">
            <div class="two">
                <div><label for="name">{{ __('install.fields.name') }}</label><input id="name" name="name" value="{{ old('name') }}" required autocomplete="nickname" minlength="2" maxlength="50"></div>
                <div><label for="email">{{ __('install.fields.email') }}</label><input id="email" name="email" value="{{ old('email') }}" type="email" required autocomplete="email" maxlength="254"></div>
            </div>
            <label for="password">{{ __('install.fields.password') }}</label>
            <input id="password" name="password" type="password" required minlength="12" maxlength="128" autocomplete="new-password">
            <label for="password_confirmation">{{ __('install.fields.password_confirmation') }}</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required minlength="12" maxlength="128" autocomplete="new-password">
            <label class="check demo-choice">
                <input type="checkbox" name="demo" value="1" @checked(old('demo'))>
                <span><strong>{{ __('install.demo.label') }}</strong><small>{{ __('install.demo.hint') }}</small></span>
            </label>
            <button type="submit" @disabled(in_array(false, $checks, true))>{{ __('install.submit') }} →</button>
            <p class="hint">{{ __('install.database_hint', ['file' => '.env']) }}</p>
        </form>
    </section>
</main>
</body>
</html>
