#!/usr/bin/env python3
"""Mirror mailbox SQL predicates on Python SQLite; NOT PHP PDO/Laravel HTTP tests.

The two visibility flags are independent: hiding the sender copy cannot
hide the recipient copy, and hidden received messages are not unread.
"""
import sqlite3

db = sqlite3.connect(':memory:')
db.execute('''CREATE TABLE private_messages (
 id INTEGER PRIMARY KEY, sender_id INTEGER NOT NULL, recipient_id INTEGER NOT NULL,
 body TEXT NOT NULL, read_at TEXT, sender_hidden_at TEXT, recipient_hidden_at TEXT,
 updated_at TEXT)''')
db.executemany('''INSERT INTO private_messages(id, sender_id, recipient_id, body)
 VALUES(?, ?, ?, ?)''', [(1, 2, 3, 'one'), (2, 3, 2, 'two'), (3, 2, 4, 'outside')])

def inbox(viewer):
    return [row[0] for row in db.execute('''SELECT id FROM private_messages
    WHERE (sender_id = ? AND sender_hidden_at IS NULL)
       OR (recipient_id = ? AND recipient_hidden_at IS NULL)
    ORDER BY id DESC''', (viewer, viewer))]

def unread(viewer):
    return db.execute('''SELECT COUNT(*) FROM private_messages
    WHERE ((sender_id = ? AND sender_hidden_at IS NULL)
        OR (recipient_id = ? AND recipient_hidden_at IS NULL))
    AND recipient_id = ? AND read_at IS NULL''', (viewer, viewer, viewer)).fetchone()[0]

checks = 0

def check(got, want, label):
    global checks
    assert got == want, (label, got, want)
    checks += 1

check(inbox(2), [3, 2, 1], 'sender default inbox')
check(inbox(3), [2, 1], 'recipient default inbox')
check(unread(3), 1, 'unread received')
check(db.execute('''UPDATE private_messages SET sender_hidden_at = 'now'
    WHERE id = 1 AND sender_id = 2 AND sender_hidden_at IS NULL''').rowcount, 1, 'sender hides')
check(inbox(2), [3, 2], 'sender no longer sees')
check(inbox(3), [2, 1], 'recipient remains visible')
check(unread(3), 1, 'recipient unread preserved')
check(db.execute('''UPDATE private_messages SET sender_hidden_at = 'now'
    WHERE id = 1 AND sender_id = 2 AND sender_hidden_at IS NULL''').rowcount, 0, 'repeat hide fails closed')
check(db.execute('''UPDATE private_messages SET recipient_hidden_at = 'now'
    WHERE id = 1 AND recipient_id = 3 AND recipient_hidden_at IS NULL''').rowcount, 1, 'recipient hides')
check(inbox(3), [2], 'recipient message removed')
check(unread(3), 0, 'hidden received message no longer unread')
check(db.execute('SELECT body FROM private_messages WHERE id=1').fetchone()[0], 'one', 'body retained')
check(db.execute('''UPDATE private_messages SET sender_hidden_at = 'now'
    WHERE id = 2 AND sender_id = 2 AND sender_hidden_at IS NULL''').rowcount, 0, 'other sender cannot hide')
check(inbox(2), [3, 2], 'other participant cannot affect visible copy')
print(f'PASS: {checks} Python SQLite SQL-predicate assertions (not PHP PDO/HTTP)')
