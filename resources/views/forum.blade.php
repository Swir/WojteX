@php($localePayload = app(\App\Services\LanguageRegistry::class)->payload())
<!DOCTYPE html>
<html lang="{{ $localePayload['locale'] }}" data-theme="dark" data-accent="blue">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#10121b">
    <meta name="description" content="{{ __('ui.nav.description') }}" data-i18n-content="ui.nav.description">
    <title>WojteX Forum — by Swir</title>
    <link rel="icon" type="image/svg+xml" href="/assets/wojtex.svg?v=0.1.0-beta.1">
    <link rel="stylesheet" href="/assets/forum.css?v=0.1.0-beta.1">
    <script type="application/json" id="veyra-i18n">{!! json_encode($localePayload, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE) !!}</script>
    <link rel="stylesheet" href="/assets/wojtex.css?v=0.1.0-beta.1">
    <link rel="stylesheet" href="/assets/custom.css?v=0.1.0-beta.1">
    <script src="/assets/wojtex-appearance.js?v=0.1.0-beta.1"></script>
    <script src="/assets/forum.js?v=0.1.0-beta.1" defer></script>
</head>
<body>
    <a class="skip-link" href="#main-content" data-i18n="ui.nav.skip">{{ __('ui.nav.skip') }}</a>
    <div class="sidebar-backdrop" id="sidebar-backdrop"></div>
    <aside class="sidebar" id="sidebar" aria-label="{{ __('ui.nav.main') }}" data-i18n-aria-label="ui.nav.main">
        <a class="brand" href="#/" aria-label="{{ __('ui.nav.home_label') }}" data-i18n-aria-label="ui.nav.home_label">
            <span class="brand-mark"><svg viewBox="0 0 40 40" aria-hidden="true"><path d="M3 13 6 27 10 18 14 27 17 13M22 13 33 27M33 13 22 27" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
            <span class="brand-copy"><strong>Wojte<span>X</span></strong><small>FORUM <span>by Swir</span></small></span>
        </a>
        <div class="workspace-label"><span class="status-dot"></span><span data-i18n="ui.nav.community">{{ __('ui.nav.community') }}</span><span class="tiny-pill">BETA</span></div>
        <nav class="main-nav" id="main-nav">
            <a href="#/" class="nav-item active" data-nav="home"><span data-icon="layout"></span><span data-i18n="ui.nav.discover">{{ __('ui.nav.discover') }}</span><span class="nav-dot"></span></a>
            <a href="#/latest" class="nav-item" data-nav="latest"><span data-icon="spark"></span><span data-i18n="ui.nav.latest">{{ __('ui.nav.latest') }}</span></a>
            <a href="#/chat" class="nav-item" data-nav="chat"><span data-icon="chat"></span><span data-i18n="ui.shout.title">{{ __('ui.shout.title') }}</span></a>
            <a href="#/rankings" class="nav-item" data-nav="rankings"><span data-icon="trophy"></span><span data-i18n="ui.nav.rankings">{{ __('ui.nav.rankings') }}</span></a>
            <a href="#/messages" class="nav-item" data-nav="messages" hidden><span data-icon="message"></span><span data-i18n="ui.messages.title">{{ __('ui.messages.title') }}</span></a>
            <a href="#/saved" class="nav-item" data-nav="saved"><span data-icon="bookmark"></span><span data-i18n="ui.nav.saved">{{ __('ui.nav.saved') }}</span></a>
        </nav>
        <div class="nav-section-title"><span data-i18n="ui.nav.spaces">{{ __('ui.nav.spaces') }}</span><span data-icon="layers"></span></div>
        <nav id="category-nav" class="category-nav" aria-label="{{ __('ui.nav.categories') }}" data-i18n-aria-label="ui.nav.categories"><div class="skeleton skeleton-line"></div><div class="skeleton skeleton-line"></div><div class="skeleton skeleton-line"></div></nav>
        <div class="sidebar-bottom">
            <div class="community-mini" id="community-mini"><span class="community-orb" data-icon="users"></span><div><strong data-i18n="ui.nav.together">{{ __('ui.nav.together') }}</strong><small data-i18n="ui.nav.next_idea">{{ __('ui.nav.next_idea') }}</small></div></div>
            <div id="sidebar-admin"></div>
            <span class="sidebar-link sidebar-credit"><span data-icon="code"></span><span data-i18n="ui.nav.created_by">{{ __('ui.nav.created_by') }}</span></span>
            <div class="version-line"><span>WojteX Forum</span><span id="version-label">0.1.0-beta.1</span></div>
        </div>
    </aside>
    <div class="app-shell">
        <header class="topbar">
            <div class="topbar-start"><button class="icon-button mobile-menu" type="button" data-action="toggle-menu" aria-label="{{ __('ui.nav.menu_open') }}" data-i18n-aria-label="ui.nav.menu_open"><span data-icon="menu"></span></button><span class="topbar-context"><span data-icon="command"></span><span id="topbar-label">{{ __('ui.nav.center') }}</span></span></div>
            <form class="search-box" id="search-form" role="search"><span data-icon="search"></span><input id="global-search" type="search" placeholder="{{ __('ui.nav.search_placeholder') }}" data-i18n-placeholder="ui.nav.search_placeholder" aria-label="{{ __('ui.nav.search_label') }}" data-i18n-aria-label="ui.nav.search_label" maxlength="150"><kbd>/</kbd></form>
            <div class="topbar-actions">
                <label class="locale-control"><span data-icon="globe"></span><span class="sr-only" data-i18n="ui.languages.selector">{{ __('ui.languages.selector') }}</span><select id="locale-picker" aria-label="{{ __('ui.languages.selector') }}" data-i18n-aria-label="ui.languages.selector">@foreach($localePayload['languages'] as $language)<option value="{{ $language['code'] }}" @selected($language['code'] === $localePayload['locale'])>{{ $language['native_name'] }}</option>@endforeach</select></label>
                <button class="icon-button wx-appearance-button" type="button" data-action="appearance" aria-label="{{ __('ui.nav.theme_label') }}" data-i18n-aria-label="ui.nav.theme_label" title="{{ __('ui.nav.theme_title') }}" data-i18n-title="ui.nav.theme_title"><span data-icon="sun"></span><span class="wx-appearance-label" data-i18n="ui.appearance.title">{{ __('ui.appearance.title') }}</span></button><div class="account-control" id="account-control"><button class="button button-small button-ghost" data-action="login" data-i18n="ui.auth.sign_in">{{ __('ui.auth.sign_in') }}</button></div>
            </div>
        </header>
        <main id="main-content" tabindex="-1">
            <div class="page-header"><div><div class="eyebrow" id="page-eyebrow">{{ __('ui.feed.eyebrow') }}</div><h1 id="page-title">{{ __('ui.feed.home_title') }}</h1><p id="page-subtitle">{{ __('ui.feed.home_subtitle') }}</p></div><button type="button" class="button button-primary new-topic-button" data-action="new-topic"><span data-icon="plus"></span><span data-i18n="ui.topic.new">{{ __('ui.topic.new') }}</span></button></div>
            <div class="workspace-grid" id="workspace-grid">
                <section class="main-column" id="view" aria-live="polite" aria-busy="true"><div class="welcome-skeleton skeleton"></div><div class="panel loading-panel"><div class="skeleton skeleton-line"></div><div class="skeleton skeleton-line"></div><div class="skeleton skeleton-line"></div><span class="loading-label" data-i18n="ui.nav.connecting">{{ __('ui.nav.connecting') }}</span></div></section>
                <aside class="right-column" id="right-column" aria-label="{{ __('ui.nav.activity') }}" data-i18n-aria-label="ui.nav.activity"><section class="panel skeleton-side"><div class="skeleton skeleton-line"></div><div class="skeleton skeleton-line"></div><div class="skeleton skeleton-line"></div></section></aside>
            </div>
            <footer class="page-footer"><span><span class="footer-brand">Powered by WojteX</span> <span data-i18n="ui.footer.slogan">{{ __('ui.footer.slogan') }}</span></span><span><span data-i18n="ui.footer.created_by">{{ __('ui.footer.created_by') }}</span> <span class="credit-name">Swir</span></span></footer>
        </main>
    </div>
    <dialog id="app-dialog" class="app-dialog" aria-labelledby="dialog-title"><div id="dialog-content"></div></dialog>
    <div id="toast-region" class="toast-region" aria-live="polite" aria-atomic="true"></div>
    <noscript><div class="noscript-notice" data-i18n="ui.nav.noscript">{{ __('ui.nav.noscript') }}</div></noscript>
</body>
</html>
