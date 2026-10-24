#!/usr/bin/env python3
"""Regression for opt-out and ban checks in REAL Laravel-compiled SQL.

Uses Python sqlite3 because this host has no PHP PDO SQLite. Does NOT qualify HTTP.
"""
import json
from pathlib import Path
import sqlite3
import subprocess

root = Path(__file__).resolve().parents[2]
proc = subprocess.run(['php', str(root / 'tests/Smoke/PrivateMessageSqlCompile.php')],
                      cwd=root, capture_output=True, text=True)
assert proc.returncode == 0, proc.stderr
queries = json.loads(proc.stdout)
db = sqlite3.connect(':memory:')
db.executescript('''
CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL,
    is_banned INTEGER NOT NULL DEFAULT 0,
    allow_private_messages INTEGER NOT NULL DEFAULT 1);
CREATE TABLE private_message_blocks (blocker_id INTEGER, blocked_id INTEGER,
    PRIMARY KEY (blocker_id, blocked_id));
CREATE TABLE private_messages (id INTEGER PRIMARY KEY AUTOINCREMENT,
    sender_id INTEGER, recipient_id INTEGER, body TEXT, read_at TEXT,
    created_at TEXT, updated_at TEXT);
''')
db.executemany('INSERT INTO users VALUES (?,?,?,?)', [(1, 'Alice', 0, 1),
    (2, 'Bob', 0, 1), (3, 'Blocked User', 1, 1)])
checks = 0

def verify(actual, expected, what):
    global checks
    assert actual == expected, f'{what}: {actual!r} != {expected!r}'
    checks += 1

def execute(name):
    query = queries[name]
    return db.execute(query['sql'], query['bindings'])

verify(execute('search_alice').fetchall(), [(2,)], 'enabled Bob found')
verify(execute('insert_forward').rowcount, 1, 'enabled Bob receives PM')
verify(execute('insert_reverse').rowcount, 1, 'enabled Alice receives PM')
db.execute('UPDATE users SET allow_private_messages=0 WHERE id=2')
verify(execute('search_alice').fetchall(), [], 'opted-out Bob hidden from search')
verify(execute('insert_forward').rowcount, 0, 'opted-out Bob cannot receive PM')
verify(execute('insert_reverse').rowcount, 1, 'opted-out Bob may send to consenting Alice')
db.execute('UPDATE users SET allow_private_messages=1 WHERE id=2')
verify(execute('search_alice').fetchall(), [(2,)], 're-enabled Bob found')
verify(execute('insert_forward').rowcount, 1, 're-enabled Bob receives PM')
db.execute('UPDATE users SET is_banned=1 WHERE id=2')
verify(execute('insert_forward').rowcount, 0, 'newly banned recipient cannot receive in guarded SQL')
verify(execute('insert_reverse').rowcount, 0, 'newly banned sender cannot send in guarded SQL')
db.execute('UPDATE users SET is_banned=0 WHERE id=2')
db.execute('UPDATE users SET is_banned=1 WHERE id=1')
verify(execute('insert_forward').rowcount, 0, 'newly banned sender Alice cannot send in guarded SQL')
verify(execute('insert_reverse').rowcount, 0, 'newly banned recipient Alice cannot receive')
db.execute('UPDATE users SET is_banned=0 WHERE id=1')
verify(execute('insert_forward').rowcount, 1, 'unbanned sender can resume')
verify(db.execute('SELECT COUNT(*) FROM private_messages').fetchone()[0], 5,
       'ineligible sends never committed')
print(f'PASS: {checks} opt-out/ban assertions on Laravel-compiled SQL via Python SQLite (not PHP PDO)')
