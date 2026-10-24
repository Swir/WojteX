#!/usr/bin/env python3
"""Execute real Laravel-compiled DELETE in Python sqlite3, NOT PHP PDO/HTTP.

The authenticated user/role is a stale request snapshot: change the current
DB state after preflight and before executing the SQL statement.
"""
import json
import sqlite3
import subprocess
from pathlib import Path

root = Path(__file__).resolve().parents[2]
compiled = subprocess.run(['php', str(root / 'tests/Smoke/ShoutboxDeleteSqlCompile.php')],
                          cwd=root, capture_output=True, text=True)
assert compiled.returncode == 0, compiled.stderr
statements = json.loads(compiled.stdout)
conn = sqlite3.connect(':memory:')
conn.executescript('''
CREATE TABLE users (id INTEGER PRIMARY KEY, role TEXT NOT NULL, is_banned INTEGER NOT NULL);
CREATE TABLE plugin_states(key TEXT PRIMARY KEY, enabled INTEGER NOT NULL);
INSERT INTO plugin_states(key,enabled) VALUES('shoutbox',1);
CREATE TABLE shouts (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, body TEXT NOT NULL);
INSERT INTO users VALUES (1, 'member', 0), (2, 'member', 0),
                        (3, 'moderator', 0), (4, 'admin', 0);
INSERT INTO shouts VALUES (20, 1, 'Protected post');
''')
checks = 0

def eq(actual, expected, context):
    global checks
    assert actual == expected, f'{context}: got {actual!r}, expected {expected!r}'
    checks += 1

def restore():
    conn.execute('INSERT OR IGNORE INTO shouts VALUES (20, 1, ?)', ('Protected post',))

def delete_as(actor):
    stmt = statements[str(actor)]
    assert stmt['sql'].lower().lstrip().startswith('delete from'), stmt['sql']
    return conn.execute(stmt['sql'], stmt['bindings']).rowcount

# Stale account snapshot says owner is active. Moderator ban was committed
# AFTER find()/isModerator() and BEFORE this SQL statement.
conn.execute('UPDATE users SET is_banned=1 WHERE id=1')
eq(delete_as(1), 0, 'banned owner cannot delete after stale preflight')
eq(conn.execute('SELECT COUNT(*) FROM shouts').fetchone()[0], 1, 'preserve owner shout')
conn.execute('UPDATE users SET is_banned=0 WHERE id=1')
eq(delete_as(1), 1, 'active owner can delete')
restore()
eq(delete_as(2), 0, 'member cannot delete other user shout')
eq(delete_as(3), 1, 'current moderator can delete for moderation')
restore()
conn.execute("UPDATE users SET role='member' WHERE id=3")
eq(delete_as(3), 0, 'demoted moderator with stale staff session cannot delete')
eq(conn.execute('SELECT COUNT(*) FROM shouts').fetchone()[0], 1, 'demotion preserves shout')
conn.execute("UPDATE users SET role='moderator', is_banned=1 WHERE id=3")
eq(delete_as(3), 0, 'banned moderator with stale session cannot delete')
conn.execute("UPDATE users SET is_banned=1 WHERE id=4")
eq(delete_as(4), 0, 'banned admin cannot delete with stale session')
conn.execute("UPDATE users SET is_banned=0 WHERE id=4")
eq(delete_as(4), 1, 'current admin can delete')
restore()
eq(delete_as(99), 0, 'deleted or missing account cannot delete')
eq(conn.execute('SELECT COUNT(*) FROM shouts').fetchone()[0], 1, 'missing actor leaves shout unchanged')
eq(conn.execute('SELECT body FROM shouts WHERE id=20').fetchone()[0], 'Protected post', 'body remains intact')
print(f'PASS: {checks} guarded Laravel-compiled DELETE assertions with Python sqlite3 (NOT PHP PDO/HTTP)')
