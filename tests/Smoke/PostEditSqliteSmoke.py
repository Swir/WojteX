#!/usr/bin/env python3
"""Execute production Laravel-compiled UPDATE in Python sqlite3 (not PHP PDO/HTTP)."""
import json
import sqlite3
import subprocess
from pathlib import Path

root = Path(__file__).resolve().parents[2]
run = subprocess.run(['php',str(root/'tests/Smoke/PostEditSqlCompile.php')],
                     cwd=root,text=True,capture_output=True)
assert run.returncode == 0, run.stdout+run.stderr
statements = json.loads(run.stdout)
con = sqlite3.connect(':memory:')
con.executescript('''
CREATE TABLE users(id INTEGER PRIMARY KEY,role TEXT NOT NULL,is_banned INTEGER NOT NULL);
CREATE TABLE threads(id INTEGER PRIMARY KEY,is_locked INTEGER NOT NULL);
CREATE TABLE posts(id INTEGER PRIMARY KEY,thread_id INTEGER NOT NULL,user_id INTEGER NOT NULL,body TEXT NOT NULL,updated_at TEXT NOT NULL);
INSERT INTO users VALUES (7,'member',0),(8,'member',0),(9,'admin',0),(10,'moderator',0);
INSERT INTO threads VALUES (33,0),(34,0);
INSERT INTO posts VALUES (88,33,7,'Original owner post','2026-10-22 09:00:00'),
                         (89,33,8,'Original other post','2026-10-22 09:00:00');
''')
checks=0

def eq(got,expected,label):
    global checks
    assert got == expected, f'{label}: {got!r} != {expected!r}'
    checks += 1

def edit(post,actor):
    statement=statements[f'{post}:{actor}']
    eq(statement['sql'].lower().lstrip().startswith('update '), True, 'real SQL UPDATE')
    return con.execute(statement['sql'],statement['bindings']).rowcount

def text(post):
    return con.execute('SELECT body FROM posts WHERE id=?',(post,)).fetchone()[0]

# Healthy owner path, then live account ban after a cached permission check.
eq(edit(88,7),1,'member can edit owned unlocked post')
eq(text(88),'Updated body 2026','authorized edit persisted')
con.execute("UPDATE users SET is_banned=1 WHERE id=7")
con.execute("UPDATE posts SET body='Marker after ban' WHERE id=88")
eq(edit(88,7),0,'banned owner cannot edit')
eq(text(88),'Marker after ban','blocked edit unchanged')
con.execute('UPDATE users SET is_banned=0 WHERE id=7')
con.execute('UPDATE threads SET is_locked=1 WHERE id=33')
eq(edit(88,7),0,'owner cannot edit newly locked topic')
eq(text(88),'Marker after ban','lock preserves old content')
# Current staff permissions, not a role from the request snapshot.
eq(edit(89,9),1,'admin can edit locked other-user post')
con.execute("UPDATE users SET role='member' WHERE id=10")
con.execute("UPDATE posts SET body='Before demotion check' WHERE id=89")
eq(edit(89,10),0,'demoted moderator cannot edit other post')
eq(text(89),'Before demotion check','demotion preserves post')
con.execute("UPDATE users SET role='moderator' WHERE id=10")
eq(edit(89,10),1,'active moderator can edit locked topic')
con.execute('UPDATE threads SET is_locked=0 WHERE id=33')
eq(edit(89,7),0,'ordinary user cannot edit others post')
eq(edit(88,404),0,'missing actor cannot edit')
con.execute('UPDATE users SET is_banned=1 WHERE id=9')
eq(edit(89,9),0,'banned admin cannot edit')
con.execute('UPDATE users SET is_banned=0 WHERE id=9')
con.execute('DELETE FROM threads WHERE id=33')
eq(edit(88,7),0,'orphaned post without thread cannot be edited')
con.execute('DELETE FROM posts WHERE id=88')
eq(edit(88,7),0,'deleted post cannot be edited')
eq(edit(123,7),0,'unknown post cannot be edited')
print(f'PASS: {checks} Laravel-compiled SQL assertions executed by Python sqlite3 (NOT PDO/HTTP)')
