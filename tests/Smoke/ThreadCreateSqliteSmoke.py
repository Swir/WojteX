#!/usr/bin/env python3
"""Execute Laravel-compiled thread INSERT SQL in Python sqlite3 (NOT PHP PDO or HTTP)."""
import json
import sqlite3
import subprocess
from pathlib import Path

root = Path(__file__).resolve().parents[2]
proc = subprocess.run(['php',str(root/'tests/Smoke/ThreadCreateSqlCompile.php')],cwd=root,text=True,capture_output=True)
assert proc.returncode == 0, proc.stdout + proc.stderr
sqls = json.loads(proc.stdout)
con = sqlite3.connect(':memory:')
con.execute('PRAGMA foreign_keys=ON')
con.executescript('''
CREATE TABLE users(id INTEGER PRIMARY KEY,is_banned INTEGER NOT NULL);
CREATE TABLE categories(id INTEGER PRIMARY KEY);
CREATE TABLE threads(id INTEGER PRIMARY KEY, category_id INTEGER NOT NULL REFERENCES categories(id),
  user_id INTEGER NOT NULL REFERENCES users(id), title TEXT NOT NULL, slug TEXT NOT NULL,
  created_at TEXT NOT NULL, updated_at TEXT NOT NULL);
INSERT INTO users VALUES(7,0),(8,0),(9,0);
INSERT INTO categories VALUES (5);
''')
checks = 0

def eq(actual, expected, msg):
    global checks
    assert actual == expected, f'{msg}: {actual!r} vs {expected!r}'
    checks += 1

def insert(actor):
    statement=sqls[str(actor)]
    eq(statement['sql'].lower().lstrip().startswith('insert into'),True,'compiled real Laravel INSERT')
    return con.execute(statement['sql'], statement['bindings']).rowcount

con.execute('UPDATE users SET is_banned=1 WHERE id=7')  # ban between HTTP preflight and SQL
before=con.execute('SELECT COUNT(*) FROM threads').fetchone()[0]
eq(insert(7),0,'ban committed after stale session means no thread')
eq(con.execute('SELECT COUNT(*) FROM threads').fetchone()[0],before,'banned does not leak orphan thread')
eq(insert(8),1,'still-active user may create a thread')
row=con.execute('SELECT user_id,category_id,title,slug,created_at,updated_at FROM threads').fetchone()
eq(row,(8,5,'Literal thread 8','literal-thread-8','2026-10-23 14:35:00','2026-10-23 14:35:00'),'no field loss from INSERT SELECT')
con.execute('UPDATE users SET is_banned=1 WHERE id=8')
eq(insert(8),0,'account banned after original thread cannot create another')
con.execute('UPDATE users SET is_banned=0 WHERE id=7')
eq(insert(7),1,'unban re-enables normal creation')
con.execute('DELETE FROM users WHERE id=9')
eq(insert(9),0,'deleted account cannot create')
eq(insert(999),0,'nonexistent actor cannot create')
eq(con.execute('SELECT COUNT(*) FROM threads').fetchone()[0],2,'precisely two committed threads')
eq(con.execute('SELECT COUNT(*) FROM threads WHERE user_id=7').fetchone()[0],1,'actor identity not swapped')
print(f'PASS: {checks} assertions with Laravel-compiled SQL in Python sqlite3 (NOT PHP PDO/HTTP)')
