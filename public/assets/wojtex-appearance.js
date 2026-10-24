/* WojteX appearance preferences. Local to this browser; no account data stored. */
(() => {
    'use strict';
    const key = 'wojtex.appearance.v1';
    const modes = ['default', 'dark', 'midnight', 'light', 'system'];
    const accents = ['default', 'blue', 'violet', 'cyan', 'green', 'amber', 'rose'];
    const initial = () => ({theme: 'default', accent: 'default', density: 'comfortable'});
    const normalize = value => ({
        theme: modes.includes(value?.theme) ? value.theme : 'default',
        accent: accents.includes(value?.accent) ? value.accent : 'default',
        density: value?.density === 'compact' ? 'compact' : 'comfortable'
    });
    const media = window.matchMedia('(prefers-color-scheme: dark)');
    let settings = {theme: 'dark', accent: 'blue'};
    function read() {
        try {
            const raw = localStorage.getItem(key);
            if (raw !== null) return normalize(JSON.parse(raw));
            const legacy = localStorage.getItem('veyra.theme');
            return normalize({theme: ['dark', 'light'].includes(legacy) ? legacy : 'default'});
        } catch (_) { return initial(); }
    }
    let preferences = read();
    function apply(nextSettings) {
        if (nextSettings) settings = nextSettings;
        let theme = preferences.theme === 'default' ? settings.theme : preferences.theme;
        if (theme === 'system') theme = media.matches ? 'dark' : 'light';
        if (!['dark', 'midnight', 'light'].includes(theme)) theme = 'dark';
        let accent = preferences.accent === 'default' ? settings.accent : preferences.accent;
        if (!accents.includes(accent) || accent === 'default') accent = 'blue';
        Object.assign(document.documentElement.dataset, {theme, accent, density: preferences.density});
        const meta = document.querySelector('meta[name="theme-color"]');
        if (meta) meta.content = {dark:'#10121b',midnight:'#080f1d',light:'#f3f4f9'}[theme];
    }
    function syncForm() {
        const form = document.querySelector('form[data-form="appearance"]');
        if (form) for (const input of form.querySelectorAll('input[type="radio"]')) {
            input.checked = preferences[input.name] === input.value;
        }
    }
    function persist() {
        try { localStorage.setItem(key, JSON.stringify(preferences)); return true; }
        catch (_) { return false; }
    }
    function update(form) {
        preferences = normalize(Object.fromEntries(new FormData(form)));
        apply();
        return persist();
    }
    function reset() {
        preferences = initial();
        try { localStorage.removeItem('veyra.theme'); } catch (_) {}
        const saved = persist();
        apply(); syncForm();
        return saved;
    }
    function render(tx) {
        const tile = (name, value, label, art='') => `<label class="wx-choice"><input type="radio" name="${name}" value="${value}" ${preferences[name]===value?'checked':''}><span class="wx-choice-body">${art}<span>${label}</span></span></label>`;
        return `<form data-form="appearance"><fieldset class="wx-fieldset"><legend>${tx('ui.appearance.theme')}</legend><div class="wx-themes">${modes.map(mode=>tile('theme',mode,tx('ui.appearance.'+mode),`<span class="wx-preview wx-preview-${mode}" aria-hidden="true"><i></i><b></b><em></em></span>`)).join('')}</div></fieldset>
        <fieldset class="wx-fieldset"><legend>${tx('ui.appearance.accent')}</legend><div class="wx-accents">${accents.map(accent=>tile('accent',accent,tx('ui.appearance.'+accent),`<span class="wx-swatch wx-swatch-${accent}" aria-hidden="true"></span>`)).join('')}</div></fieldset>
        <fieldset class="wx-fieldset"><legend>${tx('ui.appearance.density')}</legend><div class="wx-density">${['comfortable','compact'].map(density=>tile('density',density,tx('ui.appearance.'+density))).join('')}</div></fieldset>
        <p class="wx-appearance-note" role="status" id="wx-appearance-status">${tx('ui.appearance.saved_hint')}</p><div class="form-footer"><button type="button" class="button button-ghost" data-action="reset-appearance">${tx('ui.appearance.reset')}</button><button type="button" class="button button-primary" data-action="close-dialog">${tx('ui.appearance.done')}</button></div></form>`;
    }
    media.addEventListener('change', () => apply());
    window.addEventListener('storage', event => { if (event.key === key || event.key === null) { preferences = read(); apply(); syncForm(); } });
    window.WojtexAppearance = Object.freeze({apply, update, reset, render});
    apply();
})();
