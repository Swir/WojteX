#!/usr/bin/env python3
"""Zero-dependency source integrity checks; not a substitute for PHP HTTP tests."""
from __future__ import annotations

import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DOMAINS = ('ui', 'install', 'server', 'import')
PATTERN = re.compile(r':([A-Za-z_][A-Za-z0-9_]*)')
MESSAGES: dict[str, dict[str, str]] = {}

for locale in ('en', 'pl'):
    merged = {}
    for domain in DOMAINS:
        filename = ROOT / 'lang' / locale / f'{domain}.json'
        catalog = json.loads(filename.read_text(encoding='utf-8'))
        assert isinstance(catalog, dict), f'{filename} must be an object'
        for key, value in catalog.items():
            assert key not in merged, f'Duplicate catalog key: {locale}.{key}'
            assert isinstance(value, str) and value.strip(), f'Empty translation: {locale}.{key}'
            merged[key] = value
    MESSAGES[locale] = merged
assert MESSAGES['en'].keys() == MESSAGES['pl'].keys(), 'Built-in locale keys diverge'
for key, english in MESSAGES['en'].items():
    polish = MESSAGES['pl'][key]
    assert sorted(set(PATTERN.findall(english))) == sorted(set(PATTERN.findall(polish))), f'Placeholder mismatch: {key}'

javascript = (ROOT / 'public/assets/forum.js').read_text(encoding='utf-8')
missing = []
for match in re.finditer(r'\b(?:tr|tx)\(\s*[\'\"]([^\'\"]+)[\'\"]', javascript):
    if match.group(1).startswith(('ui.', 'server.')) and not match.group(1).endswith('.') and match.group(1) not in MESSAGES['en']:
        missing.append(match.group(1))
assert not missing, f'UI uses unknown translation keys: {sorted(set(missing))}'

required = {
    'server.password_reset_sent', 'server.password_reset_invalid', 'server.password_reset_complete',
    'server.password_mail_subject', 'server.password_mail_expiration', 'ui.auth.forgot_link',
    'ui.auth.forgot_submit', 'ui.auth.reset_title', 'ui.auth.reset_submit',
    'ui.auth.recovery_unavailable',  # Old language packs must remain loadable.
}
assert required <= MESSAGES['en'].keys(), 'Required recovery or compatibility translations missing'
assert '#/reset-password?token=' in (ROOT / 'app/Notifications/VeyraPasswordReset.php').read_text(encoding='utf-8')
print(f'PASS: {len(MESSAGES["en"])} matching PL/EN keys, placeholders, JS references and recovery compatibility')
