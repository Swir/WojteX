#!/usr/bin/env python3
"""Exercise real exporter SQL with SQLite to prove source thread/post consistency.

This is a cross-database SQL smoke: it does NOT execute PHP PDO/MySQL or
claim to test the actual MyBB exporter against a production database.
"""
import json
import sqlite3
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
PHP_QUERY_CLASS = ROOT / 'tools/lib/MybbExportSelection.php'


def compiled_queries():
    php = r'''require $argv[1]; echo json_encode([
        'threads'=>MybbExportSelection::threads('mybb_'),
        'posts'=>MybbExportSelection::posts('mybb_'),
        'candidate_count'=>MybbExportSelection::candidateThreadCount('mybb_'),
        'rejected_prefix'=>tryBadPrefix()
    ], JSON_THROW_ON_ERROR);
    function tryBadPrefix() { try { MybbExportSelection::threads('bad`x'); return false; }
        catch (InvalidArgumentException $e) { return true; } }'''
    result = subprocess.run(
        ['php', '-r', php, str(PHP_QUERY_CLASS)],
        capture_output=True, text=True, check=True,
    )
    return json.loads(result.stdout)


def fixture():
    db = sqlite3.connect(':memory:')
    db.row_factory = sqlite3.Row
    db.execute('''CREATE TABLE mybb_threads (
        tid INTEGER PRIMARY KEY, fid INTEGER, uid INTEGER, username TEXT, subject TEXT,
        dateline INTEGER, lastpost INTEGER, sticky INTEGER, closed TEXT, visible INTEGER,
        views INTEGER, firstpost INTEGER)''')
    db.execute('''CREATE TABLE mybb_posts (
        pid INTEGER PRIMARY KEY, tid INTEGER, fid INTEGER, uid INTEGER, username TEXT,
        message TEXT, dateline INTEGER, visible INTEGER, edittime INTEGER)''')
    def thread(tid, firstpost, closed='0', visible=1):
        db.execute('INSERT INTO mybb_threads VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                   (tid, 3, 2, 'Starter', 'Thread ' + str(tid), 1, 2, 0, closed, visible, 0, firstpost))
    def post(pid, tid, visible=1):
        db.execute('INSERT INTO mybb_posts VALUES (?,?,?,?,?,?,?,?,?)',
                   (pid, tid, 3, 2, 'Starter', 'body ' + str(pid), 1, visible, 0))
    # Valid thread + reply, despite reversed numeric/lexical post ID order.
    thread(10, 2); post(10, 10); post(2, 10)
    # All starter content hidden: previously included the topic or lone reply.
    thread(20, 20); post(20, 20, visible=0)
    thread(30, 30); post(30, 30, visible=0); post(31, 30)
    # Redirect/delete and hidden topics are not importable.
    thread(40, 40, closed='moved|123'); post(40, 40)
    thread(50, 50, visible=0); post(50, 50)
    # Pointer to a *different* topic's visible post must not count as starter.
    thread(60, 2); post(61, 60)
    # Corrupt/missing opening post must not leave an orphan reply.
    thread(70, 999); post(71, 70)
    # Second healthy topic (closed but no redirect) should be retained.
    thread(80, 80, closed='1'); post(80, 80); post(81, 80, visible=0)
    return db


def main():
    queries = compiled_queries()
    db = fixture()
    assertions = 0
    def check(actual, expected, label):
        nonlocal assertions
        assertions += 1
        assert actual == expected, f'{label}: actual={actual!r}, expected={expected!r}'

    rows_t = db.execute(queries['threads']).fetchall()
    rows_p = db.execute(queries['posts']).fetchall()
    tids = [r['tid'] for r in rows_t]
    pids = [r['pid'] for r in rows_p]
    check(tids, [10, 80], 'only eligible topics with approved starter')
    check(pids, [2, 10, 80], 'no orphans or silently promoted replies')
    check([r['tid'] for r in rows_p], [10, 10, 80], 'post->thread intact')
    check({r['tid'] for r in rows_p}, set(tids), 'every exported post belongs to exported thread')
    check(db.execute(queries['candidate_count']).fetchone()[0], 6,
          'candidate thread count (before opening-post integrity)')
    check(len(db.execute('SELECT * FROM mybb_threads').fetchall()) - 6, 2,
          'unpublished or redirect omissions')
    check(6 - len(tids), 4, 'missing/unapproved opening-post omissions')
    check(len(db.execute('SELECT * FROM mybb_posts').fetchall()) - len(pids), 8,
          'omitted post count is consistent with old report formula')
    check(list(rows_t[0].keys()), ['tid','fid','uid','username','subject','dateline','lastpost','sticky','closed','visible','views'],
          'thread export JSON columns unchanged')
    check(list(rows_p[0].keys()), ['pid','tid','fid','uid','username','message','dateline','visible','edittime'],
          'post export JSON columns unchanged')
    check(queries['rejected_prefix'], True, 'SQL identifiers fail closed for invalid prefix')
    for topic in rows_t:
        starter = db.execute('SELECT tid,visible FROM mybb_posts WHERE pid=?', (db.execute(
            'SELECT firstpost FROM mybb_threads WHERE tid=?', (topic['tid'],)).fetchone()[0],)).fetchone()
        check(starter['tid'], topic['tid'], 'starter same topic')
        check(starter['visible'], 1, 'starter visible')
    print(f'MybbExportSelection SQLite cross-database smoke PASS: {assertions} assertions (NOT PHP PDO/MySQL)')


if __name__ == '__main__':
    main()
