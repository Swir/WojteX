#!/usr/bin/env python3
"""Real Laravel-compiled reaction SQL in Python sqlite3; NOT PHP PDO/HTTP."""
import json
import sqlite3
import subprocess
from pathlib import Path

root = Path(__file__).resolve().parents[2]
compile_process = subprocess.run(
    ['php', str(root / 'tests/Smoke/ReactionToggleSqlCompile.php')],
    cwd=root, capture_output=True, text=True,
)
assert compile_process.returncode == 0, compile_process.stdout + compile_process.stderr
queries = json.loads(compile_process.stdout)
assert queries['insert']['sql'].lower().startswith('insert or ignore into "reactions"')
assert queries['delete']['sql'].lower().startswith('delete from "reactions"')

db = sqlite3.connect(':memory:')
db.execute('PRAGMA foreign_keys = ON')
db.executescript('''
CREATE TABLE users(id INTEGER PRIMARY KEY, role TEXT NOT NULL, is_banned INTEGER NOT NULL);
CREATE TABLE threads(id INTEGER PRIMARY KEY);
CREATE TABLE posts(id INTEGER PRIMARY KEY, thread_id INTEGER NOT NULL REFERENCES threads(id),
                   user_id INTEGER NOT NULL REFERENCES users(id));
CREATE TABLE reactions(id INTEGER PRIMARY KEY, post_id INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
                       user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                       created_at TEXT, updated_at TEXT, UNIQUE(post_id,user_id));
INSERT INTO users VALUES (7,'member',0),(8,'member',0);
INSERT INTO threads VALUES (19);
INSERT INTO posts VALUES (44,19,8);
''')
checks = 0

def eq(actual, expected, context):
    global checks
    assert actual == expected, f'{context}: {actual!r} vs {expected!r}'
    checks += 1

def mutate(kind):
    sql = queries[kind]
    return db.execute(sql['sql'], sql['bindings']).rowcount

def count():
    return db.execute('SELECT COUNT(*) FROM reactions').fetchone()[0]

eq(mutate('insert'), 1, 'active member reacts')
eq(count(), 1, 'persisted one reaction')
eq(mutate('insert'), 0, 'unique constraint does not duplicate a reaction')
eq(mutate('delete'), 1, 'active member may remove own reaction')
eq(count(), 0, 'one reaction removed')

# Live moderation occurs AFTER the HTTP preflight, BEFORE the SQL mutation.
db.execute('UPDATE users SET is_banned=1 WHERE id=7')
eq(mutate('insert'), 0, 'ban blocks a delayed INSERT')
eq(count(), 0, 'ban must not create reaction')
db.execute('UPDATE users SET is_banned=0 WHERE id=7')
eq(mutate('insert'), 1, 'unbanned reader may react again')
db.execute('UPDATE users SET is_banned=1 WHERE id=7')
eq(mutate('delete'), 0, 'ban blocks a delayed reaction DELETE')
eq(count(), 1, 'denied removal preserves existing reaction')

db.execute('UPDATE users SET is_banned=0 WHERE id=7')
db.execute('UPDATE posts SET user_id=7 WHERE id=44')
eq(mutate('delete'), 0, 'ownership transition blocks removal')
eq(count(), 1, 'denied ownership transition preserves existing row')
db.execute('DELETE FROM reactions')
eq(mutate('insert'), 0, 'author cannot react to own post after preflight')
eq(count(), 0, 'no self-reaction row')

db.execute('UPDATE posts SET user_id=8 WHERE id=44')
db.execute('DELETE FROM posts WHERE id=44')
eq(mutate('insert'), 0, 'removed post cannot receive a reaction')
db.execute('INSERT INTO posts VALUES (44,19,8)')
eq(mutate('insert'), 1, 'restored post accepts an eligible reaction')
db.execute('DELETE FROM users WHERE id=7')
eq(mutate('insert'), 0, 'removed account cannot write')
eq(count(), 0, 'cascade removed the deleted account reaction')
print(f'PASS: {checks} production Laravel SQL checks in Python sqlite3 (NOT PHP PDO/HTTP)')
