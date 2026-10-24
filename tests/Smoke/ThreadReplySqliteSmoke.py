#!/usr/bin/env python3
"""Use actual compiled Laravel INSERT..SELECT in Python sqlite3. NOT PHP PDO/HTTP."""
import json
import sqlite3
import subprocess
from pathlib import Path
root=Path(__file__).resolve().parents[2]
proc=subprocess.run(['php',str(root/'tests/Smoke/ThreadReplySqlCompile.php')],cwd=root,capture_output=True,text=True)
assert proc.returncode==0,proc.stdout+proc.stderr
q=json.loads(proc.stdout)
assert q['sql'].lower().lstrip().startswith('insert into'),q['sql']
con=sqlite3.connect(':memory:')
con.execute('PRAGMA foreign_keys=ON')
con.executescript('''
CREATE TABLE users(id INTEGER PRIMARY KEY, role TEXT NOT NULL, is_banned INTEGER NOT NULL);
CREATE TABLE threads(id INTEGER PRIMARY KEY, is_locked INTEGER NOT NULL);
CREATE TABLE posts(id INTEGER PRIMARY KEY,thread_id INTEGER NOT NULL REFERENCES threads(id),user_id INTEGER NOT NULL REFERENCES users(id),body TEXT NOT NULL,body_format TEXT NOT NULL,created_at TEXT,updated_at TEXT);
INSERT INTO users VALUES (7,'member',0);
INSERT INTO threads VALUES (42,0);
''')
checks=0
def eq(actual,expected,message):
 global checks
 assert actual==expected,f'{message}: {actual!r} vs {expected!r}'
 checks+=1

def write():
 return con.execute(q['sql'],q['bindings']).rowcount

# The DB is modified after the HTTP preflight and before evaluating INSERT.
con.execute('UPDATE users SET is_banned=1 WHERE id=7')
eq(write(),0,'ban after preflight')
eq(con.execute('SELECT COUNT(*) FROM posts').fetchone()[0],0,'no banned post')
con.execute('UPDATE users SET is_banned=0 WHERE id=7')
con.execute('UPDATE threads SET is_locked=1 WHERE id=42')
eq(write(),0,'locked after preflight')
con.execute("UPDATE users SET role='moderator' WHERE id=7")
eq(write(),1,'current moderator in locked thread')
con.execute("UPDATE users SET role='member' WHERE id=7")
eq(write(),0,'demoted moderator denied')
con.execute("UPDATE users SET role='admin' WHERE id=7")
eq(write(),1,'current admin in locked thread')
con.execute('UPDATE users SET is_banned=1 WHERE id=7')
eq(write(),0,'banned admin denied')
con.execute('UPDATE users SET is_banned=0 WHERE id=7')
con.execute('UPDATE threads SET is_locked=0 WHERE id=42')
con.execute("UPDATE users SET role='member' WHERE id=7")
eq(write(),1,'unlocked member permitted')
con.execute('DELETE FROM posts')
con.execute('DELETE FROM users WHERE id=7')
eq(write(),0,'deleted author denied')
con.execute("INSERT INTO users VALUES (7,'member',0)")
con.execute('DELETE FROM threads WHERE id=42')
eq(write(),0,'missing thread denied')
con.execute('INSERT INTO threads VALUES(42,0)')
eq(write(),1,'restored thread allowed')
row=con.execute('SELECT thread_id,user_id,body,body_format,created_at,updated_at FROM posts').fetchone()
eq(row,(42,7,'A real protected reply','markdown','2026-10-23 15:00:00','2026-10-23 15:00:00'),'all post columns preserved')
print(f'PASS: {checks} assertions executing Laravel-compiled SQL in Python sqlite3 (NOT PHP PDO/HTTP)')
