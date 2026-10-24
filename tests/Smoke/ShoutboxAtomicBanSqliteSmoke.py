#!/usr/bin/env python3
"""Execute the actual PHP/Laravel-compiled INSERT..SELECT in Python SQLite.

The server has no PHP pdo_sqlite; this is not a Laravel HTTP/transaction test.
Tests the temporal boundary: a user who is banned after the request loaded
its User object cannot persist a new shout once the ban is committed.
"""
from pathlib import Path
import json
import sqlite3
import subprocess

root = Path(__file__).resolve().parents[2]
run = subprocess.run(['php', str(root/'tests/Smoke/ShoutboxInsertSqlCompile.php')], cwd=root,
                     capture_output=True, text=True)
assert run.returncode == 0, f'Laravel PHP compiler failed: {run.stderr}'
queries = json.loads(run.stdout)
db = sqlite3.connect(':memory:')
db.executescript('''
CREATE TABLE users (id INTEGER PRIMARY KEY, is_banned INTEGER NOT NULL DEFAULT 0);
CREATE TABLE plugin_states(key TEXT PRIMARY KEY, enabled INTEGER NOT NULL);
INSERT INTO plugin_states(key,enabled) VALUES('shoutbox',1);
CREATE TABLE shouts (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
    body TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
    FOREIGN KEY(user_id) REFERENCES users(id));
INSERT INTO users(id, is_banned) VALUES (10,0),(11,0),(12,1);
''')
checks = 0

def assert_equal(actual, expected, message):
    global checks
    assert actual == expected, f'{message}: {actual!r} != {expected!r}'
    checks += 1

def write(user_id):
    query = queries[str(user_id)]
    return db.execute(query['sql'], query['bindings']).rowcount

assert_equal(write(10), 1, 'active account can publish')
assert_equal(db.execute('SELECT body FROM shouts').fetchone()[0], 'Guarded post 10', 'body binds correctly')
# The user object was already loaded by session auth with is_banned=False;
# the authoritative DB row changes before the write statement.
db.execute('UPDATE users SET is_banned=1 WHERE id=11')
assert_equal(write(11), 0, 'newly banned account cannot publish with stale session')
assert_equal(write(12), 0, 'already banned account cannot publish')
assert_equal(write(999), 0, 'deleted/non-existent account cannot publish')
assert_equal(db.execute('SELECT COUNT(*) FROM shouts').fetchone()[0], 1, 'rejected writes leave no rows')
db.execute('UPDATE users SET is_banned=0 WHERE id=11')
assert_equal(write(11), 1, 'unbanned account can publish again')
assert_equal(db.execute('SELECT user_id, body FROM shouts ORDER BY id').fetchall(),
             [(10,'Guarded post 10'),(11,'Guarded post 11')], 'writes preserve author and body')
assert_equal(db.execute('SELECT COUNT(*) FROM shouts').fetchone()[0], 2, 'only eligible rows persisted')
print(f'PASS: {checks} actual Laravel-compiled SQL assertions using Python sqlite3 (NOT PHP PDO/HTTP)')
