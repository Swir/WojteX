#!/usr/bin/env python3
"""Executes actual Laravel-generated PM block SQL in Python sqlite3, NOT PHP PDO/HTTP."""
import json
import sqlite3
import subprocess
from pathlib import Path
root=Path(__file__).resolve().parents[2]
p=subprocess.run(['php',str(root/'tests/Smoke/PrivateMessageBlockSqlCompile.php')],cwd=root,text=True,capture_output=True)
assert p.returncode==0, p.stderr
q=json.loads(p.stdout)
assert q['insert']['sql'].lower().startswith('insert or ignore into "private_message_blocks"')
assert q['delete']['sql'].lower().startswith('delete from "private_message_blocks"')
db=sqlite3.connect(':memory:')
db.execute('PRAGMA foreign_keys=ON')
db.executescript('''
CREATE TABLE users(id INTEGER PRIMARY KEY,is_banned INTEGER NOT NULL);
CREATE TABLE private_message_blocks (
 blocker_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
 blocked_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
 created_at TEXT NOT NULL,updated_at TEXT NOT NULL,
 UNIQUE(blocker_id,blocked_id));
INSERT INTO users VALUES(7,0),(11,0),(12,0);
''')
checks=0
def eq(x,y,note):
 global checks
 assert x==y,f'{note}: {x!r} != {y!r}'
 checks+=1

def mutate(kind):
 sql=q[kind]
 return db.execute(sql['sql'],sql['bindings']).rowcount

def rows():
 return db.execute('SELECT blocker_id,blocked_id FROM private_message_blocks ORDER BY 1,2').fetchall()

eq(mutate('insert'),1,'active owner creates block')
eq(rows(),[(7,11)],'block exists')
eq(mutate('insert'),0,'duplicate unchanged')
eq(rows(),[(7,11)],'one row only')
eq(mutate('delete'),1,'active owner unblocks')
eq(rows(),[],'unblock removed pair')
db.execute('UPDATE users SET is_banned=1 WHERE id=7')
eq(mutate('insert'),0,'ban after session preflight blocks insert')
eq(rows(),[],'no block from banned actor')
db.execute('UPDATE users SET is_banned=0 WHERE id=7')
eq(mutate('insert'),1,'unbanned actor can block')
db.execute('UPDATE users SET is_banned=1 WHERE id=7')
eq(mutate('delete'),0,'banned actor cannot unblock')
eq(rows(),[(7,11)],'block retained after ban')
db.execute('UPDATE users SET is_banned=0 WHERE id=7')
eq(mutate('delete'),1,'unbanned owner can unblock')
eq(mutate('delete'),0,'absent block cannot be deleted')
db.execute('DELETE FROM users WHERE id=11')
eq(mutate('insert'),0,'deleted target cannot be blocked')
eq(rows(),[],'no orphan target pair')
db.execute('INSERT INTO users VALUES(11,1)')
eq(mutate('insert'),1,'blocking a banned target remains permitted')
db.execute('DELETE FROM users WHERE id=7')
eq(mutate('insert'),0,'deleted actor cannot block')
eq(mutate('delete'),0,'deleted actor cannot unblock')
eq(rows(),[],'actor cascade retains no orphan pairs')
print(f'PASS: {checks} Laravel-compiled PM-block SQL assertions in Python sqlite3 (NOT PHP PDO/HTTP)')
