#!/usr/bin/env python3
"""Execute Laravel-compiled conditional PM-hide UPDATE with Python SQLite.

Not PHP PDO / Laravel HTTP / a physical concurrency or MySQL test.
"""
import json
import os
from pathlib import Path
import sqlite3
import subprocess

root=Path(__file__).resolve().parents[2]
result=subprocess.run(['php',str(root/'tests/Smoke/PrivateMessageHideSqlCompile.php')],
    cwd=root,env={**os.environ,'APP_ENV':'testing','DB_CONNECTION':'sqlite','DB_DATABASE':':memory:'},
    text=True,capture_output=True)
assert result.returncode==0, result.stdout+result.stderr
compiled=json.loads(result.stdout)
checks=0

def eq(value,expected,label):
    global checks
    assert value==expected,f'{label}: {value!r} != {expected!r}'
    checks+=1

for driver in ('mysql','mariadb'):
    for actor in ('sender','recipient'):
        sql=compiled[driver][actor]['1']['sql'].lower()
        eq('exists (select 1 from `users` as `live_hider`' in sql,True,f'{driver} {actor} current actor exists')
        eq('`live_hider`.`is_banned` = ?' in sql,True,f'{driver} {actor} active status')
        eq('`live_hider`.`id` = `private_messages`.' in sql,True,f'{driver} {actor} correlation')

con=sqlite3.connect(':memory:')
con.executescript('''
PRAGMA foreign_keys=ON;
CREATE TABLE users(id INTEGER PRIMARY KEY,is_banned INTEGER NOT NULL DEFAULT 0);
CREATE TABLE private_messages(id INTEGER PRIMARY KEY,
 sender_id INTEGER NOT NULL REFERENCES users(id),
 recipient_id INTEGER NOT NULL REFERENCES users(id),
 body TEXT NOT NULL,sender_hidden_at TEXT,recipient_hidden_at TEXT,updated_at TEXT);
INSERT INTO users VALUES (1,0),(2,0),(3,0);
INSERT INTO private_messages VALUES (100,1,2,'retain original body',NULL,NULL,NULL);
''')

def reset():
    con.execute('DELETE FROM private_messages')
    con.execute('DELETE FROM users')
    con.executemany('INSERT INTO users VALUES (?,?)',[(1,0),(2,0),(3,0)])
    con.execute('INSERT INTO private_messages VALUES (100,1,2,\'retain original body\',NULL,NULL,NULL)')

def hide(actor,viewer):
    query=compiled['sqlite'][actor][str(viewer)]
    return con.execute(query['sql'],query['bindings']).rowcount

def flags():
    return con.execute('SELECT sender_hidden_at,recipient_hidden_at,body FROM private_messages WHERE id=100').fetchone()

reset();eq(hide('sender',1),1,'sender can hide');eq(flags()[0] is not None,True,'sender hidden')
eq(flags()[1],None,'recipient copy still visible');eq(hide('sender',1),0,'double hide denied')
reset();eq(hide('recipient',2),1,'recipient can hide');eq(flags()[1] is not None,True,'recipient hidden')
eq(flags()[0],None,'sender remains visible')
reset();con.execute('UPDATE users SET is_banned=1 WHERE id=1')
eq(hide('sender',1),0,'sender banned after preflight denied');eq(flags()[0],None,'sender copy not hidden')
reset();con.execute('UPDATE users SET is_banned=1 WHERE id=2')
eq(hide('recipient',2),0,'recipient banned after preflight denied');eq(flags()[1],None,'recipient not hidden')
reset();con.execute('DELETE FROM private_messages');con.execute('DELETE FROM users WHERE id=1')
eq(hide('sender',1),0,'removed account cannot hide nonexistent PM')
reset();eq(hide('sender',3),0,'stranger cannot impersonate sender')
eq(hide('recipient',3),0,'stranger cannot impersonate recipient')
eq(flags()[:2],(None,None),'unauthorized hide unchanged')
reset();con.execute("UPDATE private_messages SET sender_hidden_at='already' WHERE id=100")
eq(hide('sender',1),0,'already hidden copy not changed')
eq(flags()[0],'already','original hidden timestamp retained')
reset();eq(hide('sender',2),0,'recipient cannot manipulate sender flag')
eq(hide('recipient',1),0,'sender cannot manipulate recipient flag')
eq(flags()[2],'retain original body','body preserved')
print(f'PASS: {checks} Laravel-compiled SQL assertions on Python SQLite (NOT PDO/HTTP/MySQL)')
