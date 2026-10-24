#!/usr/bin/env python3
"""Guard all PM API routes at Laravel's actual route registry (not HTTP/PDO)."""
import json
import os
from pathlib import Path
import subprocess

root = Path(__file__).resolve().parents[2]
result = subprocess.run(
    ['php', 'artisan', 'route:list', '--path=api/messages', '--json'],
    cwd=root, capture_output=True, text=True,
    env={**os.environ, 'APP_ENV': 'testing'},
)
assert result.returncode == 0, (result.stdout + '\n' + result.stderr)
routes = json.loads(result.stdout)
expected = {
    ('GET|HEAD', 'api/messages'),
    ('POST', 'api/messages'),
    ('GET|HEAD', 'api/messages/blocks'),
    ('POST', 'api/messages/blocks'),
    ('DELETE', 'api/messages/blocks/{id}'),
    ('GET|HEAD', 'api/messages/recipients'),
    ('DELETE', 'api/messages/{id}'),
    ('PATCH', 'api/messages/{id}/read'),
    ('POST', 'api/messages/{id}/report'),
}
actual = {(r['method'], r['uri']) for r in routes}
assert actual == expected, f'Unexpected PM route set {actual ^ expected}'
for r in routes:
    assert 'auth' in r['middleware'], f'{r["uri"]} missing auth'
    assert 'active_pm' in r['middleware'], f'RED: {r["method"]} {r["uri"]} unguarded by active_pm middleware'
print(f'PASS: {len(routes)} actual registered PM Laravel routes protect current account (route-level, not HTTP)')
