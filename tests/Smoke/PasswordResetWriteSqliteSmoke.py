#!/usr/bin/env python3
"""Execute actual Laravel-compiled reset UPDATE in Python sqlite3, NOT PHP PDO/HTTP."""
from pathlib import Path
import json
import sqlite3
import subprocess

root=Path(__file__).resolve().parents[2]
run=subprocess.run(['php',str(root/'tests/Smoke/PasswordResetWriteSqlCompile.php')],cwd=root,capture_output=True,text=True)
assert run.returncode == 0, run.stdout+run.stderr
scripts=json.loads(run.stdout)
checks=0

def eq(actual,expected,label):
    global checks
    assert actual==expected, f'{label}: {actual!r} != {expected!r}'
    checks+=1

for grammar in ('sqlite','mysql','mariadb'):
    sql=scripts[grammar]['sql'].lower()
    bindings=scripts[grammar]['bindings']
    eq(sql.lstrip().startswith('update '),True,f'{grammar} update SQL')
    eq('is_banned' in sql and 'id' in sql,True,f'{grammar} live account guard')
    eq(len(bindings),sql.count('?'),f'{grammar} placeholders')
    eq('legacy_password_hash' in sql and 'legacy_password_salt' in sql and 'remember_token' in sql,
       True,f'{grammar} legacy and session revocation')
    eq(7 in bindings and False in bindings,True,f'{grammar} user id and ban bound')

conn=sqlite3.connect(':memory:')
conn.execute('''CREATE TABLE users(id INTEGER PRIMARY KEY, password TEXT NOT NULL,
 legacy_password_hash TEXT, legacy_password_salt TEXT, remember_token TEXT,
 is_banned INTEGER NOT NULL, updated_at TEXT NOT NULL)''')
conn.executemany('INSERT INTO users VALUES (?,?,?,?,?,?,?)',[
 (7,'old-hash','old-md5','old-salt','old-token',0,'2026-10-22 12:00:00'),
 (8,'other-hash','other-md5','other-salt','other-token',0,'2026-10-22 12:00:00'),
])
stmt=scripts['sqlite']

def write(): return conn.execute(stmt['sql'],stmt['bindings']).rowcount

def row(id): return conn.execute('SELECT password,legacy_password_hash,legacy_password_salt,remember_token,is_banned FROM users WHERE id=?',(id,)).fetchone()

# Live unbanned token owner can rotate credentials; nobody else's account changes.
eq(write(),1,'valid user reset persists')
updated=row(7)
eq(updated[0],stmt['bindings'][0],'new password hash persisted')
eq(updated[1:4],(None,None,'new-remember-token'),'legacy hashes cleared and remember rotated')
eq(row(8),('other-hash','other-md5','other-salt','other-token',0),'other account preserved')

# A ban committed AFTER the broker's initial user read but BEFORE UPDATE must win.
conn.execute("UPDATE users SET password='newer-old-hash',legacy_password_hash='retained',legacy_password_salt='retained',remember_token='retained-token',is_banned=1 WHERE id=7")
eq(write(),0,'banned between broker validation and update')
eq(row(7),('newer-old-hash','retained','retained','retained-token',1),'ban preserves all secrets')
conn.execute('DELETE FROM users WHERE id=7')
eq(write(),0,'deleted account remains absent')
eq(row(8),('other-hash','other-md5','other-salt','other-token',0),'no lateral reset')
print(f'PASS: {checks} Laravel-compiled reset UPDATE assertions via Python sqlite3 (NOT PDO/HTTP/SMTP)')
