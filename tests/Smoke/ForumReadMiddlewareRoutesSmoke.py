#!/usr/bin/env python3
"""Verify actual Laravel forum GET routes refresh viewer rights; not HTTP/PDO."""
import json
import os
import subprocess
from pathlib import Path

root = Path(__file__).resolve().parents[2]
r = subprocess.run(['php', 'artisan', 'route:list', '--json'], cwd=root,
                   capture_output=True, text=True,
                   env={**os.environ, 'APP_ENV': 'testing'})
assert r.returncode == 0, r.stdout + '\n' + r.stderr
routes = json.loads(r.stdout)
protected = {
    ('GET|HEAD', 'api/bootstrap'),
    ('GET|HEAD', 'api/threads'),
    ('GET|HEAD', 'api/threads/{id}'),
    ('GET|HEAD', 'api/rankings'),
    ('GET|HEAD', 'attachments/{id}'),
}
seen = set()
for route in routes:
    pair = (route['method'], route['uri'])
    if pair in protected:
        seen.add(pair)
        assert 'fresh_forum_reader' in route['middleware'], (
            f'RED: stale privileged forum read {route["method"]} {route["uri"]} '
            'missing fresh_forum_reader middleware'
        )
assert seen == protected, f'Missing protected routes: {protected - seen}'
print(f'PASS: {len(protected)} registered Laravel forum/private download GET routes require live viewer (not HTTP/PDO)')
