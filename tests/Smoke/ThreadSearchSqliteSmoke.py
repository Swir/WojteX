#!/usr/bin/env python3
"""Execute production Laravel search SQL using Python SQLite, NOT PHP PDO/HTTP."""
import json
import os
from pathlib import Path
import sqlite3
import subprocess

root = Path(__file__).resolve().parents[2]
run = subprocess.run(['php', str(root / 'tests/Smoke/ThreadSearchSqlCompile.php')], cwd=root,
    env={**os.environ, 'APP_ENV':'testing', 'DB_CONNECTION':'sqlite', 'DB_DATABASE':':memory:'},
    capture_output=True, text=True)
assert run.returncode == 0, run.stderr + run.stdout
assert run.stdout.lstrip().startswith('{'), 'compiler did not return JSON: '+run.stdout[:400]
queries = json.loads(run.stdout)
db = sqlite3.connect(':memory:')
db.execute('CREATE TABLE threads (id INTEGER PRIMARY KEY, category_id INTEGER NOT NULL, title TEXT NOT NULL)')
db.execute('CREATE TABLE posts (id INTEGER PRIMARY KEY, thread_id INTEGER NOT NULL, body TEXT NOT NULL)')
db.executemany('INSERT INTO threads VALUES (?,?,?)', [
    (1,1,'100% complete'), (2,1,'1000 complete'),
    (3,1,'nick_name'), (4,1,'nickXname'),
    (5,1,'hello!world'), (6,1,'helloXworld'),
    (7,1,'ordinary subject'), (8,2,'100% private'),
    (9,1,'ŻÓŁW na forum'), (10,1,'unrelated'),
])
db.executemany('INSERT INTO posts VALUES (?,?,?)', [
    (1,7,'body*mark search term'),
    (2,8,'body*mark secret category'),
    (3,10,"no text injected here"),
])
checks = 0
def verify(condition, message):
    global checks
    assert condition, message
    checks += 1

def found(term):
    query = queries[term]
    verify(' ESCAPE ' in query['sql'], f'{term}: no explicit wildcard escape')
    verify('posts' in query['sql'], f'{term}: post body not included in search')
    return [row[0] for row in db.execute(query['sql'], query['bindings'])]

verify(found('zero') == [1,2], 'zero as input was not actually searched')
verify(found('percent') == [1], 'percent matched another title or private category')
verify(found('underscore') == [3], 'underscore matched another title')
verify(found('escape') == [5], 'escape marker lost its literal meaning')
verify(found('body') == [7], 'body search missing / private body leak')
# Default SQLite LIKE is ASCII-case-insensitive, not Unicode case-folding.
verify(found('unicode') == [9], 'Unicode query changed or failed')
verify(found('injection') == [], 'SQL injection-like search returned rows')
verify(all(8 not in found(term) for term in queries), 'private thread appeared in search')
print(f'PASS: {checks} real Laravel-compiled thread search SQLite assertions (Python sqlite3; NOT PHP PDO/HTTP)')
