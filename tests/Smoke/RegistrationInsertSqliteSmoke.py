#!/usr/bin/env python3
"""Execute genuine Laravel-generated INSERT...SELECT with Python sqlite3, not PHP PDO/HTTP."""
import json
import sqlite3
import subprocess
from pathlib import Path

root = Path(__file__).resolve().parents[2]
p = subprocess.run(['php', str(root/'tests/Smoke/RegistrationInsertSqlCompile.php')],
                   cwd=root, capture_output=True, text=True)
assert p.returncode == 0, p.stdout + p.stderr
compiled = json.loads(p.stdout)
checks = 0

def eq(got, want, label):
    global checks
    assert got == want, f'{label}: got {got!r}, expected {want!r}'
    checks += 1

for driver in ('sqlite', 'mysql', 'mariadb'):
    stmt = compiled[driver]
    eq(stmt['sql'].lower().lstrip().startswith('insert into'), True, f'{driver} compiled insert')
    eq('new@example.test' in stmt['sql'], False, f'{driver} SQL never interpolates private data')
    eq(stmt['bindings'][1], 'new@example.test', f'{driver} email uses binding')
    eq(stmt['bindings'][2], '$2y$test-password-hash-only', f'{driver} hashed value uses binding')

sql = compiled['sqlite']['sql']
params = compiled['sqlite']['bindings']

def scenario(label, installed=None, registration=None, expected=0):
    conn = sqlite3.connect(':memory:')
    conn.executescript('''
    CREATE TABLE settings(key TEXT PRIMARY KEY, value TEXT NOT NULL);
    CREATE TABLE users(id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL, email TEXT NOT NULL UNIQUE, password TEXT NOT NULL,
        role TEXT NOT NULL, color TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL);
    ''')
    if installed is not None:
        conn.execute('INSERT INTO settings(key,value) VALUES (?,?)', ('setup_complete',installed))
    if registration is not None:
        conn.execute('INSERT INTO settings(key,value) VALUES (?,?)', ('registration_open',registration))
    n = conn.execute(sql, params).rowcount
    eq(n, expected, label + ' affected rows')
    eq(conn.execute('SELECT COUNT(*) FROM users').fetchone()[0], expected, label + ' persisted rows')
    if expected:
        row=conn.execute('SELECT name,email,password,role,color FROM users').fetchone()
        eq(row, ('New member','new@example.test','$2y$test-password-hash-only','member','violet'), label+' fields')
        # Statement-level closure after HTTP validation must decline the very same user.
        conn.execute("INSERT OR REPLACE INTO settings(key,value) VALUES ('registration_open','false')")
        eq(conn.execute(sql, params).rowcount, 0, label+' denied after closure')
    conn.close()

scenario('installed with default open', installed='true', registration=None, expected=1)
scenario('installed with explicit open', installed='true', registration='true', expected=1)
scenario('closed after initial GET', installed='true', registration='false', expected=0)
scenario('string false is invalid', installed='true', registration='"false"', expected=0)
scenario('null is invalid', installed='true', registration='null', expected=0)
scenario('missing setup marker', installed=None, registration='true', expected=0)
scenario('incomplete setup marker', installed='false', registration='true', expected=0)
scenario('corrupted setup marker', installed='"true"', registration='true', expected=0)
scenario('unexpected setting value is denied', installed='true', registration='1', expected=0)
print(f'PASS: {checks} Laravel-compiled registration INSERT SQL checks with Python sqlite3 (NOT PHP PDO/HTTP)')
