#!/usr/bin/env python3
"""Laravel-compiled guarded thread DELETE executed in Python sqlite3, NOT PDO/HTTP."""
import json
import sqlite3
import subprocess
from pathlib import Path

root = Path(__file__).resolve().parents[2]
compiled = subprocess.run(['php', str(root / 'tests/Smoke/ThreadDeleteSqlCompile.php')],
                          cwd=root, capture_output=True, text=True)
assert compiled.returncode == 0, compiled.stderr + compiled.stdout
statements = json.loads(compiled.stdout)
con=sqlite3.connect(':memory:')
con.executescript('''
CREATE TABLE users(id INTEGER PRIMARY KEY,role TEXT NOT NULL,is_banned INTEGER NOT NULL);
CREATE TABLE threads(id INTEGER PRIMARY KEY,category_id INTEGER NOT NULL,title TEXT NOT NULL);
CREATE TABLE posts(id INTEGER PRIMARY KEY, thread_id INTEGER REFERENCES threads(id) ON DELETE CASCADE);
INSERT INTO users VALUES (1,'member',0),(2,'moderator',0),(3,'admin',0),(4,'member',0);
INSERT INTO threads VALUES (30, 10, 'Original topic'), (31, 10, 'Second topic');
''')
checks=0
def eq(got, expected, description):
    global checks
    assert got == expected, f'{description}: {got!r} != {expected!r}'
    checks+=1

def delete(thread, actor):
    stmt=statements[f'{thread}:{actor}']
    assert stmt['sql'].lower().lstrip().startswith('delete from'), stmt['sql']
    return con.execute(stmt['sql'], stmt['bindings']).rowcount

def restore():
    con.execute("INSERT OR IGNORE INTO threads VALUES (30,10,'Original topic')")

# Demotion and banning happen AFTER a cached preflight, BEFORE the real SQL.
con.execute("UPDATE users SET role='member' WHERE id=2")
eq(delete(30,2),0,'demoted moderator cannot delete')
eq(con.execute('SELECT COUNT(*) FROM threads WHERE id=30').fetchone()[0],1,'topic survives demotion')
con.execute("UPDATE users SET role='moderator', is_banned=1 WHERE id=2")
eq(delete(30,2),0,'banned moderator cannot delete')
con.execute("UPDATE users SET is_banned=0 WHERE id=2")
eq(delete(30,2),1,'active moderator can delete')
restore()
con.execute('UPDATE users SET is_banned=1 WHERE id=3')
eq(delete(30,3),0,'banned admin cannot delete')
eq(con.execute('SELECT COUNT(*) FROM threads WHERE id=30').fetchone()[0],1,'topic survives admin ban')
con.execute('UPDATE users SET is_banned=0 WHERE id=3')
eq(delete(30,3),1,'active admin can delete')
restore()
eq(delete(30,1),0,'normal member cannot delete')
eq(delete(30,4),0,'other member cannot delete')
eq(delete(31,2),1,'staff can delete chosen thread')
eq(con.execute('SELECT COUNT(*) FROM threads WHERE id=30').fetchone()[0],1,'other topic not deleted')
con.execute('DELETE FROM users WHERE id=2')
eq(delete(30,2),0,'deleted moderator account cannot delete')
eq(con.execute('SELECT COUNT(*) FROM threads WHERE id=30').fetchone()[0],1,'deleted account preserves topic')
print(f'PASS: {checks} assertions with real Laravel-compiled SQL executed by Python sqlite3 (NOT PHP PDO/HTTP)')
