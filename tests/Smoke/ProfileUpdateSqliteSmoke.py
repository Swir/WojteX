#!/usr/bin/env python3
"""Execute Laravel-compiled production UPDATE in sqlite3, not PHP PDO or HTTP."""
import json
import sqlite3
import subprocess
from pathlib import Path

root = Path(__file__).resolve().parents[2]
result = subprocess.run(['php',str(root / 'tests/Smoke/ProfileUpdateSqlCompile.php')],
                        cwd=root, capture_output=True, text=True)
assert result.returncode == 0, result.stdout + result.stderr
scripts = json.loads(result.stdout)
checks=0

def eq(actual, expected, reason):
    global checks
    assert actual == expected, f'{reason}: {actual!r} != {expected!r}'
    checks += 1

for grammar in ('sqlite','mysql','mariadb'):
    for mode in ('with_pref','without_pref'):
        statement = scripts[grammar][mode]
        eq(statement['sql'].lower().lstrip().startswith('update '), True, f'{grammar} real update SQL')
        eq(len(statement['bindings']) == statement['sql'].count('?'), True, f'{grammar} binding count')
        eq('is_banned' in statement['sql'] and 'id' in statement['sql'], True, f'{grammar} live row guard')
        eq('allow_private_messages' in statement['sql'], mode=='with_pref', f'{grammar} only explicit preference')

conn = sqlite3.connect(':memory:')
conn.execute('''CREATE TABLE users(id INTEGER PRIMARY KEY, name TEXT NOT NULL, bio TEXT NOT NULL,
 allow_private_messages INTEGER NOT NULL, is_banned INTEGER NOT NULL, role TEXT NOT NULL,
 password TEXT NOT NULL, updated_at TEXT NOT NULL)''')
conn.executemany('INSERT INTO users VALUES (?,?,?,?,?,?,?,?)', [
 (7,'Before','Before bio',1,0,'member','dummy-hash','2026-10-22 12:00:00'),
 (8,'Banned','Old bio',1,1,'member','other-hash','2026-10-22 12:00:00')
])
with_pref=scripts['sqlite']['with_pref']
without_pref=scripts['sqlite']['without_pref']

def execute(statement):
    return conn.execute(statement['sql'], statement['bindings']).rowcount

def row(user=7):
    return conn.execute('SELECT name,bio,allow_private_messages,is_banned,role,password FROM users WHERE id=?',(user,)).fetchone()

# Authorized update changes only explicitly requested fields, not credentials or moderation.
eq(execute(with_pref),1,'live owner changed')
eq(row(),('Renamed','Updated biography',0,0,'member','dummy-hash'),'correct fields only')
conn.execute("UPDATE users SET name='Unchanged before ban',bio='Original',is_banned=1 WHERE id=7")
eq(execute(with_pref),0,'late ban blocks SQL update')
eq(row(),('Unchanged before ban','Original',0,1,'member','dummy-hash'),'ban preserves all profile values')
conn.execute('DELETE FROM users WHERE id=7')
eq(execute(with_pref),0,'deleted account cannot be edited')
conn.execute("INSERT INTO users VALUES(7,'Before again','Other bio',0,0,'member','new-hash','2026-10-22 12:00:00')")
eq(execute(without_pref),1,'old client without preference is permitted')
eq(row(),('Renamed','Updated biography',0,0,'member','new-hash'),'old client preserves existing optout')
eq(execute(with_pref),1,'explicit preference update can be repeated')
eq(row(),('Renamed','Updated biography',0,0,'member','new-hash'),'repeat does not change protected columns')
eq(execute(without_pref),1,'SQLite may report idempotent row as affected')
# The stored account with another id is not changed by the actor-id-bound statement.
eq(row(8),('Banned','Old bio',1,1,'member','other-hash'),'other account untouched')
print(f'PASS {checks} actual Laravel-compiled SQL assertions via Python sqlite3 (not PHP PDO/HTTP)')
