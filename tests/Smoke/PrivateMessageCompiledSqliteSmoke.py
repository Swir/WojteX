#!/usr/bin/env python3
"""Execute real Laravel Query Builder SQLite SQL using Python sqlite3 (NOT PHP PDO)."""
import json
from pathlib import Path
import sqlite3
import subprocess

root = Path(__file__).resolve().parents[2]
proc = subprocess.run(['php', str(root / 'tests/Smoke/PrivateMessageSqlCompile.php')],
    cwd=root, env={'APP_ENV':'testing', 'DB_CONNECTION':'sqlite', 'DB_DATABASE':':memory:', 'PATH':'/usr/bin:/bin'},
    text=True, capture_output=True)
assert proc.returncode == 0, proc.stderr
queries = json.loads(proc.stdout)
assert set(queries) == {'insert_forward','insert_reverse','search_alice','search_bob'}

db = sqlite3.connect(':memory:')
db.execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, is_banned INTEGER NOT NULL DEFAULT 0, allow_private_messages INTEGER NOT NULL DEFAULT 1)')
db.executemany('INSERT INTO users VALUES (?,?,?,?)', [(1,'Alice',0,1),(2,'Bob',0,1),(3,'Banned',1,1)])
db.execute('CREATE TABLE private_message_blocks (blocker_id INTEGER, blocked_id INTEGER, PRIMARY KEY(blocker_id,blocked_id))')
db.execute('CREATE TABLE private_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, sender_id INTEGER, recipient_id INTEGER, body TEXT, read_at TEXT, created_at TEXT, updated_at TEXT)')
checks = 0

def verify(actual, expected, label):
    global checks
    assert actual == expected, (label, actual, expected)
    checks += 1

def execute(key):
    q = queries[key]
    return db.execute(q['sql'], q['bindings'])

verify(execute('search_alice').fetchall(), [(2,)], 'search initially finds Bob')
verify(execute('search_bob').fetchall(), [(1,)], 'search initially finds Alice')
verify(execute('insert_forward').rowcount, 1, 'forward unblocked insert')
verify(execute('insert_reverse').rowcount, 1, 'reverse unblocked insert')
db.execute('INSERT INTO private_message_blocks VALUES (2,1)')
verify(execute('search_alice').fetchall(), [], 'search excludes someone blocking viewer')
verify(execute('search_bob').fetchall(), [], 'search excludes blocked person')
verify(execute('insert_forward').rowcount, 0, 'forward insert blocked')
verify(execute('insert_reverse').rowcount, 0, 'reverse insert blocked')
verify(db.execute('SELECT COUNT(*) FROM private_messages').fetchone()[0], 2, 'blocked sends not stored')
db.execute('DELETE FROM private_message_blocks WHERE blocker_id=2 AND blocked_id=1')
verify(execute('insert_forward').rowcount, 1, 'after unblock insert works')
verify(execute('insert_reverse').rowcount, 1, 'reverse after unblock works')
verify(db.execute('SELECT COUNT(*) FROM private_messages').fetchone()[0], 4, 'only allowed messages saved')
print(f'PASS: {checks} assertions executing Laravel-compiled SQLite queries via Python (not PHP PDO/HTTP)')
