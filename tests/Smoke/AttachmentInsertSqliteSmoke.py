#!/usr/bin/env python3
"""Laravel-generated SQL run through Python sqlite3; NOT PDO/HTTP/native concurrency."""
import json
import sqlite3
import subprocess
from pathlib import Path
root=Path(__file__).resolve().parents[2]
res=subprocess.run(['php',str(root/'tests/Smoke/AttachmentInsertSqlCompile.php')],cwd=root,capture_output=True,text=True)
assert res.returncode==0,res.stdout+res.stderr
q=json.loads(res.stdout)
assert q['sql'].lower().startswith('insert into "attachments"'),q
con=sqlite3.connect(':memory:')
con.execute('PRAGMA foreign_keys=ON')
con.executescript('''
CREATE TABLE users(id INTEGER PRIMARY KEY, role TEXT NOT NULL, is_banned INT NOT NULL);
CREATE TABLE threads(id INTEGER PRIMARY KEY, is_locked INT NOT NULL);
CREATE TABLE posts(id INTEGER PRIMARY KEY,thread_id INT REFERENCES threads(id),user_id INT REFERENCES users(id));
CREATE TABLE attachments(id INTEGER PRIMARY KEY,post_id INT REFERENCES posts(id),user_id INT REFERENCES users(id),path TEXT,name TEXT,mime TEXT,size INT,created_at TEXT,updated_at TEXT);
INSERT INTO users VALUES(7,'member',0),(8,'member',0);
INSERT INTO threads VALUES(42,0);
INSERT INTO posts VALUES(5,42,7);
''')
checks=0
def eq(got,want,label):
 global checks
 assert got==want,f'{label}: {got!r} != {want!r}'
 checks+=1
def write():return con.execute(q['sql'],q['bindings']).rowcount
con.execute('UPDATE users SET is_banned=1 WHERE id=7')
eq(write(),0,'ban committed after preflight')
eq(con.execute('SELECT COUNT(*) FROM attachments').fetchone()[0],0,'no banned insert')
con.execute('UPDATE users SET is_banned=0 WHERE id=7')
con.execute('UPDATE threads SET is_locked=1 WHERE id=42')
eq(write(),0,'lock committed after preflight')
con.execute("UPDATE users SET role='moderator' WHERE id=7")
eq(write(),1,'active moderator may attach while locked')
con.execute("UPDATE users SET role='member' WHERE id=7")
eq(write(),0,'demoted moderator may not upload to locked thread')
con.execute('UPDATE threads SET is_locked=0 WHERE id=42')
eq(write(),1,'owner in unlocked thread may upload')
con.execute('UPDATE posts SET user_id=8 WHERE id=5')
eq(write(),0,'ownership changed after preflight')
con.execute("UPDATE users SET role='admin' WHERE id=7")
eq(write(),1,'live admin may attach to another post')
con.execute("UPDATE users SET role='member' WHERE id=7")
con.execute('UPDATE posts SET user_id=7 WHERE id=5')
eq(write(),1,'owner reaches attachment cap')
eq(con.execute('SELECT COUNT(*) FROM attachments WHERE post_id=5').fetchone()[0],4,'pre-cap')
eq(write(),1,'fifth accepted')
eq(con.execute('SELECT COUNT(*) FROM attachments WHERE post_id=5').fetchone()[0],5,'cap reached')
eq(write(),0,'sixth must be rejected regardless of stale preflight count')
row=con.execute('SELECT post_id,user_id,path,name,mime,size,created_at,updated_at FROM attachments WHERE id=1').fetchone()
eq(row,(5,7,'attachments/test.txt','test.txt','text/plain',42,'2026-10-23 20:00:00','2026-10-23 20:00:00'),'preserved fields')
con.execute('DELETE FROM attachments')
con.execute('DELETE FROM posts WHERE id=5')
eq(write(),0,'post removed while file staged')
con.execute('INSERT INTO threads VALUES(43,0)')
con.execute('INSERT INTO posts VALUES(5,43,7)')
eq(write(),0,'post moved to other thread id')
print(f'PASS: {checks} Laravel-compiled INSERT guard SQL assertions via Python sqlite3 (NOT PHP PDO/HTTP)')
