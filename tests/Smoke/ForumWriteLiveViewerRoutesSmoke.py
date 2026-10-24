#!/usr/bin/env python3
"""Registered Laravel forum mutation routes must refresh identity before controller preflight.

This executes the production Laravel route compiler, not PHP PDO/HTTP. A cached
moderator role otherwise passes private-category preflight, while downstream
single-statement guards only recheck ban/ownership/lock, not group visibility.
"""
import json
import os
from pathlib import Path
import subprocess

root = Path(__file__).resolve().parents[2]
result = subprocess.run(
    ['php', 'artisan', 'route:list', '--json'], cwd=root,
    capture_output=True, text=True, env={**os.environ, 'APP_ENV': 'testing'},
)
assert result.returncode == 0, result.stdout + '\n' + result.stderr
routes = json.loads(result.stdout)
expected = {
    ('POST', 'api/threads'),
    ('POST', 'api/threads/{id}/posts'),
    ('PATCH', 'api/threads/{id}'),
    ('DELETE', 'api/threads/{id}'),
    ('POST', 'api/threads/{id}/bookmark'),
    ('PATCH', 'api/posts/{id}'),
    ('DELETE', 'api/posts/{id}'),
    ('POST', 'api/posts/{id}/reaction'),
    ('POST', 'api/posts/{id}/attachments'),
}
seen = set()
for route in routes:
    pair = (route['method'], route['uri'])
    if pair not in expected:
        continue
    seen.add(pair)
    middleware = route['middleware']
    assert 'auth' in middleware, f'{pair} missing auth'
    assert 'fresh_forum_reader' in middleware, (
        f'RED: {pair} uses stale moderator role for PRIVATE forum write preflight: '
        'fresh_forum_reader missing'
    )
assert seen == expected, f'Missing forum write routes: {expected - seen}'
print(f'PASS: {len(expected)} actual Laravel forum mutation routes refresh live viewer before controller (not HTTP/PDO)')
