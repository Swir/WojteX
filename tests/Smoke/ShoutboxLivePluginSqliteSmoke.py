#!/usr/bin/env python3
"""Reproduce a live shoutbox disable between preflight and INSERT/DELETE.

Execute real Laravel-compiled production SQL in Python sqlite3. Not PHP PDO,
HTTP, MySQL, or proof of concurrent requests.
"""
import json
import sqlite3
import subprocess
import sys
from pathlib import Path

root = Path(__file__).resolve().parents[2]

def compiled(name):
    cmd = subprocess.run(['php', str(root / 'tests/Smoke' / name)], cwd=root,
                         capture_output=True, text=True)
    assert cmd.returncode == 0, f'{name}: {cmd.stderr}'
    return json.loads(cmd.stdout)

insert = compiled('ShoutboxInsertSqlCompile.php')
delete = compiled('ShoutboxDeleteSqlCompile.php')
con = sqlite3.connect(':memory:')
con.executescript('''
PRAGMA foreign_keys=ON;
CREATE TABLE users(id INTEGER PRIMARY KEY, role TEXT NOT NULL, is_banned INTEGER NOT NULL);
CREATE TABLE plugin_states(key TEXT PRIMARY KEY, enabled INTEGER NOT NULL);
CREATE TABLE shouts(id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, body TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT '', updated_at TEXT NOT NULL DEFAULT '',
    FOREIGN KEY(user_id) REFERENCES users(id));
INSERT INTO users VALUES (1, 'member', 0), (2, 'member', 0),
    (3, 'moderator', 0), (4, 'admin', 0),
    (10, 'member', 0), (11, 'member', 0), (12, 'member', 1);
INSERT INTO plugin_states VALUES ('shoutbox', 1);
INSERT INTO shouts(id,user_id,body) VALUES(20,1,'Keep an existing shout');
''')
checks = 0

def eq(actual, expected, label):
    global checks
    assert actual == expected, f'{label}: got {actual!r}, expected {expected!r}'
    checks += 1

def toggle(enabled):
    con.execute('UPDATE plugin_states SET enabled=? WHERE key=?', (int(enabled),'shoutbox'))

def write(actor):
    q = insert[str(actor)]
    return con.execute(q['sql'], q['bindings']).rowcount

def remove(actor):
    q = delete[str(actor)]
    return con.execute(q['sql'], q['bindings']).rowcount

# A request saw enabled=true. Administrator turns the plugin OFF just before
# the final SQL, without modifying the old request's cached state.
toggle(False)
if '--only-delete' not in sys.argv:
    eq(write(10), 0, 'disabled between preflight and INSERT refuses the message')
    eq(con.execute('SELECT COUNT(*) FROM shouts').fetchone()[0], 1, 'no disabled writes')
if '--only-insert' not in sys.argv:
    eq(remove(1), 0, 'disabled between preflight and DELETE preserves the shout')
    eq(con.execute('SELECT body FROM shouts WHERE id=20').fetchone()[0],
       'Keep an existing shout', 'no disabled deletion')

# Even a privileged current moderator must not mutate a disabled shoutbox.
if '--only-insert' not in sys.argv:
    eq(remove(3), 0, 'moderator cannot bypass disabled plugin')

# The enabled path stays functional; the actor's live ban still blocks both.
toggle(True)
if '--only-delete' not in sys.argv:
    eq(write(10), 1, 're-enabled module allows current active poster')
    con.execute('UPDATE users SET is_banned=1 WHERE id=11')
    eq(write(11), 0, 'banned poster still forbidden')
if '--only-insert' not in sys.argv:
    eq(remove(2), 0, 'another member cannot delete')
    eq(remove(3), 1, 'enabled module allows current moderator')
    eq(con.execute('SELECT COUNT(*) FROM shouts WHERE id=20').fetchone()[0],0,'moderator deleted once')

# Missing plugin row means feature disabled and must be enforced by mutation.
con.execute("DELETE FROM plugin_states WHERE key='shoutbox'")
if '--only-delete' not in sys.argv:
    eq(write(10),0,'missing module state rejects an INSERT')
if '--only-insert' not in sys.argv:
    con.execute("INSERT INTO shouts(id,user_id,body) VALUES(20,1,'Restored only for test')")
    eq(remove(1),0,'missing module state rejects a DELETE')
print(f'PASS: {checks} Laravel-compiled SQL assertions in Python sqlite3 (NOT PHP PDO/HTTP)')
