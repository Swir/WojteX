#!/usr/bin/env python3
"""Offline SQL semantics test using PHP-produced LIKE patterns and Python SQLite.
Not a Laravel HTTP test or proof of PDO SQLite in PHP runtime.
"""
import json
from pathlib import Path
import sqlite3
import subprocess

root = Path(__file__).resolve().parents[2]
terms = ['Ann%', 'Ann_', 'Ann!', "Robert' OR 1=1 --", 'Ann']
php = r'''
require "app/Services/PrivateMessageRecipientSearch.php";
$terms = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
echo json_encode(array_map([App\Services\PrivateMessageRecipientSearch::class, 'likePattern'], $terms), JSON_THROW_ON_ERROR);
'''
patterns = json.loads(subprocess.check_output(['php', '-r', php, json.dumps(terms)], cwd=root))
db = sqlite3.connect(':memory:')
db.execute('CREATE TABLE users (name TEXT NOT NULL)')
db.executemany('INSERT INTO users(name) VALUES(?)', [(x,) for x in ['Ann%', 'Ann_', 'Ann!', 'AnnX', "Robert' OR 1=1 --", 'Alice']])
expected = [ ['Ann%'], ['Ann_'], ['Ann!'], ["Robert' OR 1=1 --"], ['Ann!', 'Ann%', 'AnnX', 'Ann_'] ]
for term, pattern, want in zip(terms, patterns, expected, strict=True):
    actual = [r[0] for r in db.execute("SELECT name FROM users WHERE name LIKE ? ESCAPE '!' ORDER BY name", (pattern,))]
    assert actual == want, (term, actual, want)
print(f'PASS: {len(terms)} PHP-produced patterns executed against SQLite (no Laravel/PDO HTTP)')
