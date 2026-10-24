#!/usr/bin/env python3
"""Actual Laravel Query Builder SQL via Python sqlite3, NOT PHP PDO / HTTP."""
import json
from pathlib import Path
import sqlite3
import subprocess
import os

root = Path(__file__).resolve().parents[2]
env = dict(os.environ, APP_ENV='testing', DB_CONNECTION='sqlite', DB_DATABASE=':memory:')
proc = subprocess.run(['php', str(root/'tests/Smoke/GroupMemberLookupSqlCompile.php')], cwd=root,
                      env=env, capture_output=True, text=True)
assert proc.returncode == 0, proc.stderr
q = json.loads(proc.stdout)
db = sqlite3.connect(':memory:')
db.execute('CREATE TABLE users(id INTEGER PRIMARY KEY, name TEXT NOT NULL, is_banned INTEGER DEFAULT 0)')
db.execute('CREATE TABLE user_group_members(group_id INTEGER, user_id INTEGER, UNIQUE(group_id,user_id))')
# User 1 cannot appear in the old newest-100 list, but must be manageable.
accounts = [(1, 'Veteran Player', 0), (2, 'Echo', 0), (3, 'Echo', 0),
            (4, '100% match', 0), (5, 'nick_ever', 0), (6, 'hello!world', 0),
            (7, '1000 match', 0), (8, 'nickXever', 0), (9, 'helloxworld', 0)]
accounts += [(i, 'Filler ' + str(i), 0) for i in range(10, 115)]
db.executemany('INSERT INTO users VALUES(?,?,?)', accounts)
db.executemany('INSERT INTO user_group_members VALUES (?,?)', [(1,1),(1,2),(1,114),(2,1)])
checks = 0

def verify(actual, expected, label):
    global checks
    assert actual == expected, (label, actual, expected)
    checks += 1

def search(key):
    return db.execute(q[key]['sql'], q[key]['bindings']).fetchall()

verify([r[0] for r in search('old')], [1], 'older than 100 recent still searchable')
verify([r[0] for r in search('duplicate')], [2,3], 'same display name stays distinct IDs')
verify([r[0] for r in search('exact_id')], [1], 'explicit #1 stable lookup')
verify([r[0] for r in search('percent')], [4], 'percent is not wildcard')
verify([r[0] for r in search('underscore')], [5], 'underscore is not wildcard')
verify([r[0] for r in search('marker')], [6], 'escape marker is literal')
verify(len(search('wide')), 20, 'large search is bounded to 20 rows')
verify(dict(db.execute(q['member_counts']['sql'],q['member_counts']['bindings']).fetchall()),
       {1:3,2:1}, 'full group member counts not newest-100 subset')
verify(len(db.execute(q['old']['sql'],q['old']['bindings']).description), 3, 'only id/name/ban selected')
verify(len(accounts) > 100, True, 'test includes more than 100 accounts')
print(f'PASS: {checks} group-account Laravel SQLite SQL assertions (Python sqlite3, not PHP PDO/HTTP)')
