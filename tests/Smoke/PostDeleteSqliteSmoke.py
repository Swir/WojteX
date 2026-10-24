#!/usr/bin/env python3
"""Execute real Laravel SQLite DELETE SQL using Python sqlite3, not PDO/HTTP."""
import json
import sqlite3
import subprocess
from pathlib import Path

root=Path(__file__).resolve().parents[2]
p=subprocess.run(['php',str(root/'tests/Smoke/PostDeleteSqlCompile.php')],cwd=root,text=True,capture_output=True)
assert p.returncode==0,p.stdout+p.stderr
statements=json.loads(p.stdout)
con=sqlite3.connect(':memory:')
con.executescript('''
CREATE TABLE users(id INTEGER PRIMARY KEY,role TEXT NOT NULL,is_banned INTEGER NOT NULL);
CREATE TABLE threads(id INTEGER PRIMARY KEY,is_locked INTEGER NOT NULL);
CREATE TABLE posts(id INTEGER PRIMARY KEY,thread_id INTEGER NOT NULL,user_id INTEGER NOT NULL,body TEXT NOT NULL);
INSERT INTO users VALUES (7,'member',0),(8,'member',0),(9,'admin',0),(10,'moderator',0);
INSERT INTO threads VALUES (33,0),(34,1);
INSERT INTO posts VALUES (87,33,7,'original'),(88,33,7,'reply'),(89,33,8,'other'),(90,34,7,'first locked');
''')
checks=0
def eq(got,expected,label):
    global checks
    assert got==expected,f'{label}: {got!r} != {expected!r}'
    checks+=1

def remove(post,actor,thread=33,first=87):
    stmt=statements[f'{post}:{actor}:{thread}:{first}']
    eq(stmt['sql'].lower().lstrip().startswith('delete from'),True,'SQLite DELETE grammar')
    eq(stmt['mysql_sql'].lower().lstrip().startswith('delete from'),True,'MySQL SQL compilation only')
    return con.execute(stmt['sql'],stmt['bindings']).rowcount

def alive(post):
    return con.execute('SELECT COUNT(*) FROM posts WHERE id=?',(post,)).fetchone()[0]

def restore(post=88,owner=7,thread=33):
    con.execute('INSERT OR IGNORE INTO posts VALUES (?,?,?,?)',(post,thread,owner,'restored'))

eq(remove(88,7),1,'active owner deletes own reply')
eq(alive(88),0,'owner reply gone')
restore()
con.execute('UPDATE users SET is_banned=1 WHERE id=7')
eq(remove(88,7),0,'ban after preflight blocks owner')
eq(alive(88),1,'ban preserves reply')
con.execute('UPDATE users SET is_banned=0 WHERE id=7')
con.execute('UPDATE threads SET is_locked=1 WHERE id=33')
eq(remove(88,7),0,'lock after preflight blocks owner')
eq(alive(88),1,'lock preserves reply')
eq(remove(88,9),1,'active admin removes reply on locked thread')
restore()
con.execute('UPDATE users SET is_banned=1 WHERE id=9')
eq(remove(88,9),0,'ban after preflight blocks admin')
eq(alive(88),1,'banned admin preserves reply')
con.execute('UPDATE users SET is_banned=0 WHERE id=9')
con.execute('UPDATE users SET role="member" WHERE id=10')
eq(remove(88,10),0,'demoted moderator cannot delete')
eq(alive(88),1,'demotion preserves reply')
con.execute('UPDATE users SET role="moderator" WHERE id=10')
eq(remove(88,10),1,'current moderator can delete')
restore()
eq(remove(88,8),0,'other member cannot delete')
eq(remove(88,404),0,'missing account cannot delete')
eq(remove(87,9),0,'original first post cannot be deleted by admin')
eq(alive(87),1,'original remains')
eq(remove(88,9,33,88),0,'first-ID guard blocks selected post')
eq(alive(88),1,'first-ID guard preserves target')
eq(remove(90,7,34,90),0,'single first post in locked topic cannot be deleted')
eq(remove(123,7),0,'missing target cannot be deleted')
con.execute('DELETE FROM threads WHERE id=33')
eq(remove(88,7),0,'orphaned post lacks live thread')
eq(alive(88),1,'orphaned post not deleted')
print(f'PASS: {checks} assertions of production Laravel-compiled SQLite SQL executed in Python sqlite3 (NOT PHP PDO/HTTP); MySQL grammar compile only')
