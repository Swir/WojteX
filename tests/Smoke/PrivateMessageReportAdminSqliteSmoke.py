#!/usr/bin/env python3
"""Actual Laravel SQL compiled by PHP and executed by Python sqlite3.

Not PDO, Laravel HTTP, MySQL execution or a concurrent live database test.
"""
import json
import os
from pathlib import Path
import sqlite3
import subprocess

root = Path(__file__).resolve().parents[2]
proc = subprocess.run(['php', 'tests/Smoke/PrivateMessageReportAdminSqlCompile.php'],
                      cwd=root, capture_output=True, text=True,
                      env={**os.environ, 'APP_ENV': 'testing', 'DB_CONNECTION': 'sqlite',
                           'DB_DATABASE': ':memory:'})
assert proc.returncode == 0, proc.stdout + proc.stderr
sql = json.loads(proc.stdout)
checks = 0

def check(value, expected, label):
    global checks
    assert value == expected, f'{label}: {value!r} != {expected!r}'
    checks += 1

for grammar in ('mysql', 'mariadb'):
    compiled = sql[grammar]['actor_1']
    for op in ('update_sql', 'list_sql', 'same_sql'):
        check('exists (select' in compiled[op].lower(), True,
              f'{grammar} {op} protects live admin')
        check('live_admin' in compiled[op].lower(), True,
              f'{grammar} {op} references live users')

con = sqlite3.connect(':memory:')
con.executescript('''
CREATE TABLE users (id INTEGER PRIMARY KEY, role TEXT NOT NULL, is_banned INTEGER NOT NULL);
CREATE TABLE private_message_reports (
 id INTEGER PRIMARY KEY, status TEXT NOT NULL, reviewed_by INTEGER,
 reviewed_at TEXT, updated_at TEXT
);
''')

def reset():
    con.execute('DELETE FROM private_message_reports')
    con.execute('DELETE FROM users')
    con.executemany('INSERT INTO users VALUES (?,?,?)', [
        (1, 'admin', 0), (2, 'member', 0), (3, 'admin', 1)])
    con.executemany('INSERT INTO private_message_reports VALUES (?,?,?,?,?)', [
        (42, 'open', None, None, 'yesterday'), (43, 'open', None, None, 'yesterday')])

def execute(actor=1, kind='update'):
    stmt = sql['sqlite'][f'actor_{actor}']
    return con.execute(stmt[kind+'_sql'], stmt[kind+'_bindings'])

def status():
    return con.execute('SELECT status,reviewed_by FROM private_message_reports WHERE id=42').fetchone()

reset()
check(execute().rowcount, 1, 'active admin resolves report')
check(status(), ('resolved', 1), 'status and reviewer persisted')
check(execute().rowcount, 0, 'repeat status is idempotent')
check(execute(kind='same').fetchone() is not None, True, 'same status read guarded for active admin')
check(con.execute('SELECT status FROM private_message_reports WHERE id=43').fetchone()[0],
      'open', 'other report unchanged')
reset(); con.execute("UPDATE users SET role='member' WHERE id=1")
check(execute().rowcount, 0, 'demoted admin cannot change review')
check(status(), ('open', None), 'demoted admin leaves report intact')
check(execute(kind='list').fetchall(), [], 'demoted admin cannot list reported messages')
reset(); con.execute('UPDATE users SET is_banned=1 WHERE id=1')
check(execute().rowcount, 0, 'banned admin cannot review')
check(execute(kind='list').fetchall(), [], 'banned admin cannot list')
reset(); con.execute('DELETE FROM users WHERE id=1')
check(execute().rowcount, 0, 'deleted admin cannot review')
check(execute(kind='list').fetchall(), [], 'deleted admin cannot list')
reset()
check(execute(2).rowcount, 0, 'non-admin cannot review')
check(execute(2, 'list').fetchall(), [], 'non-admin cannot list')
reset()
check(len(execute(kind='list').fetchall()), 2, 'active admin can see only reported rows')
check(execute().rowcount, 1, 'current admin review succeeds after resets')
check(execute(kind='list').fetchall() != [], True, 'open reports remain after one review')
print(f'PASS: {checks} Laravel-compiled SQL + Python sqlite3 checks (NOT PHP PDO/HTTP/live MySQL)')
