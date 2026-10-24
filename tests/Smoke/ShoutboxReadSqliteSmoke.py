#!/usr/bin/env python3
"""Execute the unchanged production Laravel compiled SELECT in Python sqlite3.
This is not PHP PDO, native HTTP, MySQL execution or physical concurrency.
"""
import json
import sqlite3
import subprocess
from pathlib import Path
root=Path(__file__).resolve().parents[2]
r=subprocess.run(['php',str(root/'tests/Smoke/ShoutboxReadSqlCompile.php')],cwd=root,capture_output=True,text=True)
assert r.returncode==0,f'Laravel SQL compilation: {r.stderr}'
queries=json.loads(r.stdout)
con=sqlite3.connect(':memory:')
con.executescript('''
CREATE TABLE plugin_states(key TEXT PRIMARY KEY, enabled INTEGER NOT NULL);
CREATE TABLE shouts(id INTEGER PRIMARY KEY, user_id INTEGER, body TEXT);
INSERT INTO plugin_states VALUES ('shoutbox',1);
INSERT INTO shouts VALUES (40,1,'Old'),(41,1,'New'),(42,1,'Most recent');
''')
checks=0
def eq(got,expected,why):
    global checks
    assert got==expected,f'{why}: got={got!r} expected={expected!r}'
    checks+=1
def ids(which):
    query=queries[which]
    return [row[0] for row in con.execute(query['sql'],query['bindings'])]
eq(ids('initial'),[42,41,40],'initial history enabled')
eq(ids('after'),[41,42],'incremental history enabled')
con.execute("UPDATE plugin_states SET enabled=0 WHERE key='shoutbox'")
eq(ids('initial'),[],'initial revoked AFTER preflight')
eq(ids('after'),[],'incremental revoked AFTER preflight')
con.execute("UPDATE plugin_states SET enabled=1 WHERE key='shoutbox'")
eq(ids('initial'),[42,41,40],'reenabled initial history')
eq(ids('after'),[41,42],'reenabled incremental history')
con.execute("DELETE FROM plugin_states WHERE key='shoutbox'")
eq(ids('initial'),[],'missing state hides history')
eq(ids('after'),[],'missing state hides incremental history')
con.execute("INSERT INTO plugin_states VALUES ('shoutbox',0)")
con.execute("INSERT INTO plugin_states VALUES ('rankings',1)")
eq(ids('initial'),[],'unrelated enabled plugin cannot authorize shout history')
con.execute("UPDATE plugin_states SET enabled=1 WHERE key='shoutbox'")
eq(ids('after'),[41,42],'unrelated plugin has no influence')
print(f'PASS: {checks} Laravel-compiled SQL checks in Python sqlite3 (not PHP PDO/HTTP)')
