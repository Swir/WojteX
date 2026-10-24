#!/usr/bin/env python3
"""Regression for ban-after-preflight: real Laravel SQL, Python sqlite3 (NOT PHP PDO/HTTP)."""
import json
import os
from pathlib import Path
import sqlite3
import subprocess

root = Path(__file__).resolve().parents[2]
env = os.environ.copy()
env.update(APP_ENV='testing', DB_CONNECTION='sqlite', DB_DATABASE=':memory:')
p = subprocess.run(['php', str(root/'tests/Smoke/PrivateMessageReportSqlCompile.php')],
                   cwd=root, env=env, capture_output=True, text=True)
assert p.returncode == 0, p.stderr
queries = json.loads(p.stdout)
assert set(queries) == {'recipient', 'sender', 'stranger'}
conn = sqlite3.connect(':memory:')
conn.execute('PRAGMA foreign_keys=ON')
conn.executescript('''
CREATE TABLE users(id INTEGER PRIMARY KEY, is_banned INTEGER NOT NULL DEFAULT 0);
CREATE TABLE private_messages(id INTEGER PRIMARY KEY, sender_id INTEGER NOT NULL REFERENCES users(id),
    recipient_id INTEGER NOT NULL REFERENCES users(id), recipient_hidden_at TEXT);
CREATE TABLE private_message_reports(id INTEGER PRIMARY KEY,
    message_id INTEGER NOT NULL REFERENCES private_messages(id) ON DELETE CASCADE,
    reporter_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    reason TEXT, details TEXT, status TEXT, reviewed_by INTEGER, reviewed_at TEXT,
    created_at TEXT, updated_at TEXT, UNIQUE(message_id,reporter_id));
INSERT INTO users(id,is_banned) VALUES(1,0),(2,0),(3,0);
INSERT INTO private_messages VALUES(100,1,2,NULL);
''')
checks = 0

def eq(actual, expected, label):
    global checks
    assert actual == expected, f'{label}: got {actual!r}, expected {expected!r}'
    checks += 1

def report(key):
    return conn.execute(queries[key]['sql'], queries[key]['bindings']).rowcount

# The application previously saw the live recipient. A ban now commits BEFORE the actual SQL.
conn.execute('UPDATE users SET is_banned=1 WHERE id=2')
eq(report('recipient'), 0, 'ban after preflight must prevent new report SQL')
eq(conn.execute('SELECT COUNT(*) FROM private_message_reports').fetchone()[0], 0,
   'banned recipient must not leave evidence reports')
conn.execute('UPDATE users SET is_banned=0 WHERE id=2')
eq(report('recipient'), 1, 'active recipient may report')
eq(report('recipient'), 0, 'unique idempotent duplicate')
eq(report('sender'), 0, 'sender never reports own outgoing')
eq(report('stranger'), 0, 'stranger never reports')
conn.execute('DELETE FROM private_message_reports')
conn.execute('UPDATE private_messages SET recipient_hidden_at=? WHERE id=100', ('hidden',))
eq(report('recipient'), 0, 'hidden copy may not be reported')
conn.execute('UPDATE private_messages SET recipient_hidden_at=NULL WHERE id=100')
conn.execute('DELETE FROM private_messages WHERE id=100')
conn.execute('DELETE FROM users WHERE id=2')
eq(report('recipient'), 0, 'deleted reporter cannot submit')
eq(conn.execute('SELECT COUNT(*) FROM private_message_reports').fetchone()[0], 0,
   'no new report after account deletion')
print(f'PASS: {checks} Laravel-compiled PM-report SQL assertions through Python sqlite3 (NOT PHP PDO/HTTP)')
