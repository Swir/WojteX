#!/usr/bin/env python3
"""Execute production Laravel-compiled conditional read-receipt SQL via PYTHON SQLite.
No PHP PDO, PHPUnit HTTP, MySQL execution or concurrent transaction qualification.
"""
import json
import os
from pathlib import Path
import sqlite3
import subprocess

root=Path(__file__).resolve().parents[2]
run=subprocess.run(['php',str(root/'tests/Smoke/PrivateMessageReadReceiptSqlCompile.php')],
    cwd=root,env={**os.environ,'APP_ENV':'testing','DB_CONNECTION':'sqlite','DB_DATABASE':':memory:'},
    capture_output=True,text=True)
assert run.returncode==0, run.stdout+run.stderr
compiled=json.loads(run.stdout)
checks=0

def check(actual,expected,label):
    global checks
    assert actual==expected,f'{label}: {actual!r} != {expected!r}'
    checks+=1

for driver in ('sqlite','mysql','mariadb'):
    for viewer in ('1','2','3'):
        sql=compiled[driver][viewer]['sql'].lower()
        check('live_reader' in sql,True,f'{driver}/{viewer} has live recipient')
        check('is_banned' in sql,True,f'{driver}/{viewer} checks ban')
        check('recipient_hidden_at' in sql,True,f'{driver}/{viewer} checks hidden copy')
        check('read_at' in sql,True,f'{driver}/{viewer} checks already read')
        check(len(compiled[driver][viewer]['bindings']),5,f'{driver}/{viewer} bound parameters')

con=sqlite3.connect(':memory:')
con.executescript('''
CREATE TABLE users (id INTEGER PRIMARY KEY, is_banned INTEGER NOT NULL DEFAULT 0);
CREATE TABLE private_messages (id INTEGER PRIMARY KEY, sender_id INTEGER NOT NULL,
  recipient_id INTEGER NOT NULL, body TEXT NOT NULL,
  sender_hidden_at TEXT, recipient_hidden_at TEXT, read_at TEXT, updated_at TEXT);
''')

def reset():
    con.execute('DELETE FROM private_messages')
    con.execute('DELETE FROM users')
    con.executemany('INSERT INTO users (id,is_banned) VALUES (?,?)',[(1,0),(2,0),(3,0)])
    con.execute("INSERT INTO private_messages VALUES (100,1,2,'retain private text',NULL,NULL,NULL,NULL)")

def mark(viewer):
    q=compiled['sqlite'][str(viewer)]
    return con.execute(q['sql'],q['bindings']).rowcount

def message():
    return con.execute('SELECT sender_hidden_at,recipient_hidden_at,read_at,body FROM private_messages WHERE id=100').fetchone()

reset();check(mark(2),1,'active recipient reads')
check(message()[2],'2026-10-24 06:40:00','read timestamp created')
check(message()[:2],(None,None),'sender and receiver visibility preserved')
check(message()[3],'retain private text','content preserved')
check(mark(2),0,'repeat read does not touch timestamp')
check(message()[2],'2026-10-24 06:40:00','first timestamp survives repeat')
reset();check(mark(1),0,'sender cannot mark receipt')
check(mark(3),0,'stranger cannot mark receipt')
check(message()[2],None,'not recipient remains unread')
reset();con.execute('UPDATE users SET is_banned=1 WHERE id=2')
check(mark(2),0,'live banned recipient blocked');check(message()[2],None,'ban leaves unread')
reset();con.execute('DELETE FROM users WHERE id=2')
check(mark(2),0,'deleted recipient blocked');check(message()[2],None,'delete leaves unread')
reset();con.execute("UPDATE private_messages SET recipient_hidden_at='hidden' WHERE id=100")
check(mark(2),0,'hidden copy blocked');check(message()[2],None,'hide leaves unread')
reset();con.execute("UPDATE private_messages SET read_at='original' WHERE id=100")
check(mark(2),0,'already read is write-noop');check(message()[2],'original','original read time retained')
reset();con.execute('UPDATE private_messages SET recipient_id=3 WHERE id=100')
check(mark(2),0,'old recipient after reassignment blocked')
check(mark(3),1,'new recipient can mark their copy')
reset();con.execute('DELETE FROM private_messages WHERE id=100')
check(mark(2),0,'removed PM blocked')
print(f'PASS: {checks} real Laravel SQL / Python SQLite checks (NOT PHP PDO/HTTP/MySQL)')
