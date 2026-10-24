#!/usr/bin/env python3
"""Actual Laravel-compiled SQLite UPDATE executed via Python sqlite3 (NOT PHP PDO/HTTP).

Also inspects MySQL/MariaDB compiled JOIN grammar without connecting to those databases.
"""
import json
import os
from pathlib import Path
import sqlite3
import subprocess

root = Path(__file__).resolve().parents[2]
run = subprocess.run(['php', str(root/'tests/Smoke/AdminBanSqlCompile.php')], cwd=root,
    env={**os.environ, 'APP_ENV':'testing', 'DB_CONNECTION':'sqlite', 'DB_DATABASE':':memory:'},
    text=True, capture_output=True)
assert run.returncode == 0, run.stdout+run.stderr
compiled = json.loads(run.stdout)
checks=0

def eq(actual, wanted, label):
    global checks
    assert actual == wanted, f'{label}: {actual!r} != {wanted!r}'
    checks+=1

for driver in ('mysql','mariadb'):
    sql = compiled[driver]['2:1']['sql'].lower()
    eq(' join ' in sql and 'current_admin' in sql, True, f'{driver} joined live admin account')
    eq('set `target`.`is_banned` = ?' in sql, True, f'{driver} qualified SET column')
    eq('where `target`.`id`' in sql and '`target`.`role` != ?' in sql,True,f'{driver} protects target account')
    eq('exists (select' not in sql,True,f'{driver} avoids self-select update restriction')

con=sqlite3.connect(':memory:')
con.executescript('''
CREATE TABLE users (id INTEGER PRIMARY KEY, role TEXT NOT NULL, is_banned INTEGER NOT NULL, updated_at TEXT);
INSERT INTO users VALUES (1,'admin',0,'old'),(2,'member',0,'old'),(3,'moderator',0,'old'),(4,'member',1,'old');
''')

def reset():
    con.execute("DELETE FROM users")
    con.executemany('INSERT INTO users VALUES (?,?,?,?)',[
        (1,'admin',0,'old'),(2,'member',0,'old'),(3,'moderator',0,'old'),(4,'member',1,'old')])

def mutate(target=2, actor=1):
    stmt=compiled['sqlite'][f'{target}:{actor}']
    return con.execute(stmt['sql'],stmt['bindings']).rowcount

def banned(id):
    row=con.execute('SELECT is_banned FROM users WHERE id=?',(id,)).fetchone()
    return None if row is None else row[0]

reset();con.execute("UPDATE users SET role='admin' WHERE id=2")
eq(mutate(),0,'promoted target cannot be banned');eq(banned(2),0,'promoted target unchanged')
reset();con.execute("UPDATE users SET role='member' WHERE id=1")
eq(mutate(),0,'demoted former admin denied');eq(banned(2),0,'no write by demoted admin')
reset();con.execute("UPDATE users SET is_banned=1 WHERE id=1")
eq(mutate(),0,'banned admin denied');eq(banned(2),0,'no write by banned admin')
reset();con.execute('DELETE FROM users WHERE id=1')
eq(mutate(),0,'removed admin denied');eq(banned(2),0,'removed admin leaves member')
reset();eq(mutate(1,1),0,'self ban blocked');eq(banned(1),0,'self stays active')
reset();eq(mutate(2,3),0,'moderator cannot ban');eq(banned(2),0,'member unchanged by moderator')
reset();eq(mutate(),1,'active admin bans member');eq(banned(2),1,'ban stored')
reset();eq(mutate(3,1),1,'active admin may ban moderator');eq(banned(3),1,'moderator banned')
reset();eq(mutate(404,1),0,'missing target not created')
reset();eq(banned(4),1,'unrelated account unchanged')
# Both no-op eligibility and the UPDATE must fail closed after demotion.
reset()
s=compiled['sqlite']['2:1'];eq(con.execute(s['guard_sql'],s['guard_bindings']).fetchone() is not None,False,'guard query has no rows initially: is_banned=false')
con.execute('UPDATE users SET is_banned=1 WHERE id=2')
eq(con.execute(s['guard_sql'],s['guard_bindings']).fetchone() is not None,True,'already-banned eligible account visible')
con.execute("UPDATE users SET role='member' WHERE id=1")
eq(con.execute(s['guard_sql'],s['guard_bindings']).fetchone() is None,True,'demoted admin cannot be treated as authorized no-op')
con.execute("UPDATE users SET role='admin' WHERE id=1")
con.execute("UPDATE users SET role='admin' WHERE id=2")
eq(con.execute(s['guard_sql'],s['guard_bindings']).fetchone() is None,True,'promoted target cannot be treated as authorized no-op')
print(f'PASS: {checks} SQLite execution / MySQL+MariaDB compilation assertions (NOT PHP PDO/HTTP/live MySQL)')
