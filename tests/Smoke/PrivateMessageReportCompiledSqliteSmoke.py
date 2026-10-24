#!/usr/bin/env python3
"""Real Laravel-generated report INSERT with Python sqlite3; NOT PHP PDO or HTTP."""
import json
from pathlib import Path
import sqlite3
import subprocess

root = Path(__file__).resolve().parents[2]
proc = subprocess.run(['php', str(root / 'tests/Smoke/PrivateMessageReportSqlCompile.php')],
    cwd=root, env={'APP_ENV':'testing', 'DB_CONNECTION':'sqlite', 'DB_DATABASE':':memory:', 'PATH':'/usr/bin:/bin'},
    text=True, capture_output=True)
assert proc.returncode == 0, proc.stderr
q = json.loads(proc.stdout)
assert set(q) == {'recipient', 'sender', 'stranger'}
db = sqlite3.connect(':memory:')
db.execute('PRAGMA foreign_keys=ON')
db.execute('CREATE TABLE users (id INTEGER PRIMARY KEY, is_banned INTEGER NOT NULL DEFAULT 0)')
db.execute('CREATE TABLE private_messages (id INTEGER PRIMARY KEY, sender_id INTEGER NOT NULL REFERENCES users(id), recipient_id INTEGER NOT NULL REFERENCES users(id), recipient_hidden_at TEXT)')
db.execute('''CREATE TABLE private_message_reports (
    id INTEGER PRIMARY KEY, message_id INTEGER NOT NULL REFERENCES private_messages(id) ON DELETE CASCADE,
    reporter_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    reason TEXT NOT NULL, details TEXT, status TEXT NOT NULL, reviewed_by INTEGER,
    reviewed_at TEXT, created_at TEXT, updated_at TEXT, UNIQUE(message_id,reporter_id))''')
db.executemany('INSERT INTO users(id) VALUES (?)', [(1,), (2,), (3,)])
db.execute('INSERT INTO private_messages VALUES (100,1,2,NULL)')
checks = 0

def verify(actual, expected, label):
    global checks
    assert actual == expected, (label, actual, expected)
    checks += 1

def execute(key):
    return db.execute(q[key]['sql'], q[key]['bindings']).rowcount

verify(execute('sender'), 0, 'sender cannot report outgoing PM')
verify(execute('stranger'), 0, 'stranger cannot report someone else PM')
verify(execute('recipient'), 1, 'visible recipient reports PM')
verify(execute('recipient'), 0, 'duplicate report ignored')
verify(db.execute('SELECT COUNT(*) FROM private_message_reports').fetchone()[0], 1, 'one report per recipient/message')
verify(db.execute('SELECT reporter_id, reason, status FROM private_message_reports').fetchone(), (2, 'spam', 'open'), 'reason and reporter pinned')
db.execute('UPDATE private_messages SET recipient_hidden_at=? WHERE id=100', ('2026-10-22',))
verify(execute('recipient'), 0, 'hidden PM cannot be newly reported')
db.execute('DELETE FROM private_message_reports')
verify(execute('recipient'), 0, 'hidden recipient cannot report after prior report removed')
db.execute('UPDATE private_messages SET recipient_hidden_at=NULL WHERE id=100')
verify(execute('recipient'), 1, 'still visible PM can be reported again')
db.execute('DELETE FROM private_messages WHERE id=100')
verify(db.execute('SELECT COUNT(*) FROM private_message_reports').fetchone()[0], 0, 'deleting PM cascades to reports')
print(f'PASS: {checks} Laravel-compiled report SQL assertions via Python sqlite3 (not PHP PDO/HTTP)')
