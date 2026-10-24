#!/usr/bin/env python3
"""Actual SQLite constraints/predicates. Not a PHP PDO/Laravel HTTP execution."""
import sqlite3

db = sqlite3.connect(':memory:')
db.execute('PRAGMA foreign_keys = ON')
db.execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL, is_banned INTEGER NOT NULL DEFAULT 0)')
db.executemany('INSERT INTO users(id, name, is_banned) VALUES (?,?,?)', [(1, 'Alice', 0),(2, 'Bob', 0),(3, 'Carol', 0),(4, 'Banned', 1)])
db.execute('''CREATE TABLE private_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, sender_id INTEGER, recipient_id INTEGER, body TEXT, read_at TEXT, created_at TEXT, updated_at TEXT)''')
db.execute('''CREATE TABLE private_message_blocks (
 blocker_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
 blocked_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
 created_at TEXT, updated_at TEXT, PRIMARY KEY(blocker_id,blocked_id))''')

def insert_guarded(sender, recipient, body):
    # SQLite executes a conditional INSERT atomically: no stale read-before-write.
    return db.execute('''INSERT INTO private_messages(sender_id, recipient_id, body, read_at, created_at, updated_at)
    SELECT ?, ?, ?, NULL, 'now', 'now' FROM users WHERE users.id=?
    AND NOT EXISTS (SELECT 1 FROM private_message_blocks
      WHERE (blocker_id=? AND blocked_id=?) OR (blocker_id=? AND blocked_id=?))''',
      (sender, recipient, body, recipient, sender, recipient, recipient, sender)).rowcount

count = 0
def check(value, expected, label):
    global count
    assert value == expected, (label, value, expected)
    count += 1

def allowed(sender, recipient):
    # Equivalent to both directional checks used in the controller.
    return not db.execute('''SELECT 1 FROM private_message_blocks
    WHERE (blocker_id = ? AND blocked_id = ?)
       OR (blocker_id = ? AND blocked_id = ?) LIMIT 1''',
       (sender, recipient, recipient, sender)).fetchone()

def matches(viewer, term):
    return [row[0] for row in db.execute('''SELECT users.id FROM users
    WHERE is_banned=0 AND users.id!=? AND name LIKE ? ESCAPE '!'
    AND NOT EXISTS (SELECT 1 FROM private_message_blocks
      WHERE (blocker_id=? AND blocked_id=users.id)
         OR (blocked_id=? AND blocker_id=users.id)) ORDER BY name,id''',
      (viewer, '%' + term + '%', viewer, viewer))]

check(allowed(1, 2), True, 'before block')
check(insert_guarded(1, 2, 'before'), 1, 'unblocked INSERT')
check(matches(1, 'B'), [2], 'discoverable initially')
db.execute("INSERT INTO private_message_blocks VALUES (2,1,'now','now')")
check(allowed(1, 2), False, 'recipient blocked sender')
check(insert_guarded(1, 2, 'denied'), 0, 'atomic INSERT denied by recipient block')
check(insert_guarded(2, 1, 'denied reverse'), 0, 'atomic INSERT denied by sender block')
check(allowed(2, 1), False, 'blocker cannot send to their blocked contact')
check(matches(1, 'B'), [], 'other party blocks you: filtered')
check(matches(2, 'Ali'), [], 'your blocked list: filtered')
check(allowed(1, 3), True, 'other accounts unaffected')
try:
    db.execute("INSERT INTO private_message_blocks VALUES (2,1,'now','now')")
except sqlite3.IntegrityError:
    check(True, True, 'duplicate blocked by primary key')
else:
    raise AssertionError('duplicate block accepted')
try:
    db.execute("INSERT INTO private_message_blocks VALUES (9,1,'now','now')")
except sqlite3.IntegrityError:
    check(True, True, 'unknown blocker FK rejected')
else:
    raise AssertionError('unknown blocker accepted')
db.execute('DELETE FROM private_message_blocks WHERE blocker_id=2 AND blocked_id=1')
check(allowed(1, 2), True, 'owner unblocked pair')
check(insert_guarded(1, 2, 'after'), 1, 'atomic INSERT allowed after unblock')
check(db.execute('SELECT COUNT(*) FROM private_messages').fetchone()[0], 2, 'blocked inserts never stored')
check(matches(1, 'B'), [2], 'available again')
db.execute("INSERT INTO private_message_blocks VALUES (2,1,'now','now')")
db.execute('DELETE FROM users WHERE id=2')
check(db.execute('SELECT count(*) FROM private_message_blocks').fetchone()[0], 0, 'cascade removes block')
print(f'PASS: {count} SQLite block constraints and search assertions (not PHP HTTP/PDO)')
