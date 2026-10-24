#!/usr/bin/env python3
"""Run production Laravel-compiled rankings query against Python sqlite3; not PDO/HTTP."""
import json
import os
from pathlib import Path
import sqlite3
import subprocess
root=Path(__file__).resolve().parents[2]
env=os.environ.copy();env.update(APP_ENV='testing',DB_CONNECTION='sqlite',DB_DATABASE=':memory:')
p=subprocess.run(['php',str(root/'tests/Smoke/RankingsReadSqlCompile.php')],cwd=root,env=env,capture_output=True,text=True)
assert p.returncode==0,p.stderr
q=json.loads(p.stdout)
conn=sqlite3.connect(':memory:')
conn.executescript('''
CREATE TABLE plugin_states(key TEXT PRIMARY KEY, enabled INTEGER NOT NULL);
CREATE TABLE users(id INTEGER PRIMARY KEY,name TEXT NOT NULL,is_banned INTEGER NOT NULL);
CREATE TABLE threads(id INTEGER PRIMARY KEY,category_id INTEGER NOT NULL);
CREATE TABLE posts(id INTEGER PRIMARY KEY,thread_id INTEGER NOT NULL,user_id INTEGER NOT NULL);
CREATE TABLE reactions(id INTEGER PRIMARY KEY,post_id INTEGER NOT NULL);
INSERT INTO plugin_states VALUES('rankings',1),('shoutbox',1);
INSERT INTO users VALUES(1,'Alpha',0),(2,'Beta',0),(3,'Banned',1);
INSERT INTO threads VALUES(11,3),(12,9);
INSERT INTO posts VALUES(21,11,1),(22,11,2),(23,12,1),(24,11,3);
INSERT INTO reactions VALUES(31,21),(32,23),(33,24);
''')
checks=0
def eq(got,expected,label):
    global checks
    assert got==expected,f'{label}: got {got!r} expected {expected!r}'
    checks+=1
def results():
    return [(r[0],r[3],r[4],r[5]) for r in conn.execute(q['sql'],q['bindings'])]
eq(results(),[(1,1,1,12),(2,1,0,2)],'enabled scores only from visible categories and active users')
conn.execute("UPDATE plugin_states SET enabled=0 WHERE key='rankings'")
eq(results(),[],'off after preflight does not return rankings')
conn.execute("UPDATE plugin_states SET enabled=1 WHERE key='rankings'")
eq(results(),[(1,1,1,12),(2,1,0,2)],'reenabling restores bounded rankings')
conn.execute("DELETE FROM plugin_states WHERE key='rankings'")
eq(results(),[],'missing ranking state denies data')
conn.execute("UPDATE plugin_states SET enabled=0 WHERE key='shoutbox'")
eq(results(),[],'other plugin state does not enable rankings')
conn.execute("INSERT INTO plugin_states VALUES('rankings',0)")
eq(results(),[],'disabled ranking with separate enabled states')
conn.execute("UPDATE plugin_states SET enabled=1 WHERE key='rankings'")
eq(results(),[(1,1,1,12),(2,1,0,2)],'only true ranking state enables scoring')
print(f'PASS: {checks} compiled rankings SQL checks via Python sqlite3, no PHP PDO/HTTP')
