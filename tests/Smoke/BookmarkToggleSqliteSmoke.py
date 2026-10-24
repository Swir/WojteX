#!/usr/bin/env python3
"""Execute Laravel's production bookmark SQL in Python sqlite3, NOT PHP PDO."""
import json
import sqlite3
import subprocess
from pathlib import Path

root=Path(__file__).resolve().parents[2]
r=subprocess.run(['php',str(root/'tests/Smoke/BookmarkToggleSqlCompile.php')],cwd=root,
                 stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
assert r.returncode==0, r.stderr
queries=json.loads(r.stdout)
assert queries['insert']['sql'].lower().startswith('insert or ignore into "bookmarks"')
assert queries['delete']['sql'].lower().startswith('delete from "bookmarks"')

db=sqlite3.connect(':memory:')
db.execute('PRAGMA foreign_keys=ON')
db.executescript('''
CREATE TABLE users(id INTEGER PRIMARY KEY,is_banned INT NOT NULL);
CREATE TABLE threads(id INTEGER PRIMARY KEY);
CREATE TABLE bookmarks(id INTEGER PRIMARY KEY,thread_id INTEGER REFERENCES threads(id) ON DELETE CASCADE,
 user_id INTEGER REFERENCES users(id) ON DELETE CASCADE,created_at TEXT,updated_at TEXT,
 UNIQUE(thread_id,user_id));
INSERT INTO users VALUES (7,0);
INSERT INTO threads VALUES (44);
''')
checks=0

def eq(value, expected, note):
    global checks
    assert value==expected, f'{note}: {value!r} != {expected!r}'
    checks+=1

def mutation(kind):
    q=queries[kind]
    return db.execute(q['sql'],q['bindings']).rowcount

def count():
    return db.execute('SELECT count(*) FROM bookmarks').fetchone()[0]

eq(mutation('insert'),1,'live user bookmark insert')
eq(count(),1,'bookmark stored')
eq(mutation('insert'),0,'duplicate pair prevented')
eq(count(),1,'no duplicate')
eq(mutation('delete'),1,'live user bookmark removal')
eq(count(),0,'removed')
db.execute('UPDATE users SET is_banned=1 WHERE id=7')
eq(mutation('insert'),0,'ban between preflight and insert')
eq(count(),0,'no banned insert')
db.execute('UPDATE users SET is_banned=0 WHERE id=7')
eq(mutation('insert'),1,'unban permits new bookmark')
db.execute('UPDATE users SET is_banned=1 WHERE id=7')
eq(mutation('delete'),0,'ban between preflight and deletion')
eq(count(),1,'ban does not remove bookmark')
db.execute('UPDATE users SET is_banned=0 WHERE id=7')
db.execute('DELETE FROM threads WHERE id=44')
eq(mutation('insert'),0,'missing thread blocks insertion')
eq(count(),0,'no orphan after cascade')
db.execute('INSERT INTO threads VALUES (44)')
eq(mutation('insert'),1,'restored thread permits bookmark')
db.execute('DELETE FROM users WHERE id=7')
eq(mutation('insert'),0,'deleted account blocks insertion')
eq(count(),0,'cascade removed deleted actor bookmark')
eq(mutation('delete'),0,'deleted account blocks removal')
print(f'PASS: {checks} Laravel-compiled bookmark SQL assertions in Python sqlite3 (NOT PHP PDO/HTTP)')
