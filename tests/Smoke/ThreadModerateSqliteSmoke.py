#!/usr/bin/env python3
"""Execute actual Laravel-compiled conditional UPDATE via Python sqlite3, NOT PHP PDO/HTTP."""
import json
import sqlite3
import subprocess
from pathlib import Path

root=Path(__file__).resolve().parents[2]
run=subprocess.run(['php', str(root/'tests/Smoke/ThreadModerateSqlCompile.php')],
                   cwd=root, text=True, capture_output=True)
assert run.returncode == 0, run.stdout+run.stderr
compiled=json.loads(run.stdout)
con=sqlite3.connect(':memory:')
con.executescript('''
CREATE TABLE users (id INTEGER PRIMARY KEY,role TEXT NOT NULL,is_banned INTEGER NOT NULL);
CREATE TABLE threads (id INTEGER PRIMARY KEY,is_locked INTEGER NOT NULL,is_pinned INTEGER NOT NULL);
INSERT INTO users VALUES(7,'moderator',0),(8,'member',0),(9,'admin',0);
INSERT INTO threads VALUES(44,0,0),(45,0,0);
''')
checks=0

def eq(actual, expected, label):
    global checks
    assert actual == expected, f'{label}: actual={actual!r}, expected={expected!r}'
    checks+=1

def moderate(thread, actor):
    statement=compiled[f'{thread}:{actor}']
    eq(statement['sql'].lower().lstrip().startswith('update '),True,'Laravel conditional UPDATE grammar')
    return con.execute(statement['sql'], statement['bindings']).rowcount

def state(thread):
    return con.execute('SELECT is_locked,is_pinned FROM threads WHERE id=?',(thread,)).fetchone()

con.execute("UPDATE users SET role='member' WHERE id=7")
eq(moderate(44,7),0,'demoted moderator denied')
eq(state(44),(0,0),'no mutation after demotion')
con.execute("UPDATE users SET role='moderator',is_banned=1 WHERE id=7")
eq(moderate(44,7),0,'banned moderator denied')
eq(state(44),(0,0),'no mutation after ban')
con.execute("UPDATE users SET is_banned=0 WHERE id=7")
eq(moderate(44,7),1,'active moderator authorized')
eq(state(44),(1,1),'two status columns changed')
con.execute("UPDATE threads SET is_locked=0,is_pinned=0 WHERE id=44")
con.execute("UPDATE users SET is_banned=1 WHERE id=9")
eq(moderate(44,9),0,'banned admin denied')
eq(state(44),(0,0),'no mutation after admin ban')
con.execute("UPDATE users SET is_banned=0 WHERE id=9")
eq(moderate(44,9),1,'active admin authorized')
eq(state(44),(1,1),'admin changes target')
con.execute("UPDATE threads SET is_locked=0,is_pinned=0 WHERE id=44")
eq(moderate(44,8),0,'regular member denied')
eq(state(44),(0,0),'member cannot mutate target')
con.execute("DELETE FROM users WHERE id=7")
eq(moderate(44,7),0,'removed account denied')
eq(state(44),(0,0),'removed account preserves state')
eq(moderate(999,7),0,'unknown thread denied')
eq(moderate(45,9),1,'admin moderates other thread')
eq(state(44),(0,0),'other thread preserved')
eq(state(45),(1,1),'correct thread updated')
# This is independent sequential state mutation, not simultaneous-transaction testing.
print(f'PASS: {checks} Laravel-compiled SQL assertions in Python sqlite3 (NOT PHP PDO/HTTP)')
