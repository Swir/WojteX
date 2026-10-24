#!/usr/bin/env python3
"""Execute real Laravel-generated moderation SQL in Python sqlite3; NOT PHP PDO / HTTP."""
import json
import os
from pathlib import Path
import sqlite3
import subprocess

root = Path(__file__).resolve().parents[2]
result = subprocess.run(['php', str(root / 'tests/Smoke/AdminUserLookupSqlCompile.php')], cwd=root,
    env={**os.environ, 'APP_ENV':'testing', 'DB_CONNECTION':'sqlite', 'DB_DATABASE':':memory:'},
    capture_output=True, text=True)
assert result.returncode == 0, result.stderr + result.stdout
assert result.stdout.lstrip().startswith('{'), 'SQL compiler did not return JSON: '+result.stdout[:500]
queries = json.loads(result.stdout)
connection = sqlite3.connect(':memory:')
connection.execute('CREATE TABLE users(id INTEGER PRIMARY KEY, name TEXT, email TEXT, role TEXT, is_banned INTEGER, created_at TEXT)')
rows = [
    (1, 'Veteran member', 'veteran@archived.example', 'member', 1, '2020-01-01'),
    (2, 'Echo', 'echo1@example.test', 'member', 0, '2020-01-02'),
    (3, 'Echo', 'echo2@example.test', 'moderator', 0, '2020-01-03'),
    (4, '100% match', 'percent@example.test', 'member', 0, '2020-01-04'),
    (5, 'nick_ever', 'underscore@example.test', 'member', 0, '2020-01-05'),
    (6, 'hello!world', 'escape@example.test', 'member', 0, '2020-01-06'),
    (7, '1000 match', 'notpercent@example.test', 'member', 0, '2020-01-07'),
    (8, 'nickXever', 'notunderscore@example.test', 'member', 0, '2020-01-08'),
    (9, 'helloxworld', 'notescape@example.test', 'member', 0, '2020-01-09'),
] + [(i, f'Filler {i}', f'fill{i}@example.test', 'member', 0, '2020-01-10') for i in range(10,115)]
connection.executemany('INSERT INTO users VALUES (?,?,?,?,?,?)', rows)
checks=0

def verify(condition, desc):
    global checks
    assert condition, desc
    checks += 1

def find(label):
    query = queries[label]
    return connection.execute(query['sql'], query['bindings']).fetchall()

verify(len(rows)>100, 'fixture represents more than 100 accounts')
verify([row[0] for row in find('older')] == [1], 'older-than-100 account found by name')
verify([row[0] for row in find('email')] == [1], 'older-than-100 account found by email')
verify([row[0] for row in find('duplicates')] == [3,2], 'same name differentiates stable IDs')
verify([row[0] for row in find('exact_id')] == [1], 'explicit ID lookup')
verify([row[0] for row in find('percent')] == [4], 'percent literal not SQL wildcard')
verify([row[0] for row in find('underscore')] == [5], 'underscore literal not SQL wildcard')
verify([row[0] for row in find('escape')] == [6], 'escape marker literal')
verify(len(find('many')) == 20, 'bounded rows regardless of database size')
verify(all(len(row)==6 for row in find('many')), 'only six approved columns')
verify(find('older')[0][4] == 1, 'banned flag included for unblocking')
verify(find('duplicates')[0][3] == 'moderator', 'role retained for protected account')
print(f'PASS: {checks} Laravel Query Builder moderation lookup assertions in Python sqlite3 (not PHP PDO/HTTP)')
