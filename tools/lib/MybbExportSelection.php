<?php

declare(strict_types=1);

/**
 * MyBB 1.8 exporter eligibility, shared by the thread and post queries.
 * An approved reply must never be migrated as a topic starter when the
 * original first post is absent, hidden, or belongs to a different topic.
 *
 * Input prefix is validated here as well as in the export CLI because it is
 * interpolated into SQL identifiers (identifiers cannot use PDO placeholders).
 */
final class MybbExportSelection
{
    private static function tablePrefix(string $prefix): string
    {
        if (!preg_match('/\A[a-zA-Z0-9_]*\z/D', $prefix)) {
            throw new InvalidArgumentException('Invalid MyBB table prefix.');
        }
        return $prefix;
    }

    private static function visibleThread(): string
    {
        return "t.visible=1 AND t.closed IN ('0','1')";
    }

    private static function eligibleThread(string $prefix): string
    {
        $prefix = self::tablePrefix($prefix);
        return self::visibleThread().' '
            ."AND EXISTS (SELECT 1 FROM `{$prefix}posts` AS starter "
            .'WHERE starter.pid=t.firstpost AND starter.tid=t.tid AND starter.visible=1)';
    }

    /** Count candidates before the opening-post integrity gate, for accurate omissions. */
    public static function candidateThreadCount(string $prefix): string
    {
        $prefix = self::tablePrefix($prefix);
        return "SELECT COUNT(*) FROM `{$prefix}threads` AS t WHERE ".self::visibleThread();
    }

    /** Select only topics with their original, approved opening post. */
    public static function threads(string $prefix): string
    {
        $prefix = self::tablePrefix($prefix);
        return "SELECT t.tid,t.fid,t.uid,t.username,t.subject,t.dateline,t.lastpost,t.sticky,t.closed,t.visible,t.views "
            ."FROM `{$prefix}threads` AS t WHERE ".self::eligibleThread($prefix).' ORDER BY t.tid';
    }

    /** Include no replies from a topic omitted by threads(). */
    public static function posts(string $prefix): string
    {
        $prefix = self::tablePrefix($prefix);
        return "SELECT p.pid,p.tid,p.fid,p.uid,p.username,p.message,p.dateline,p.visible,p.edittime "
            ."FROM `{$prefix}posts` AS p JOIN `{$prefix}threads` AS t ON t.tid=p.tid "
            .'WHERE p.visible=1 AND '.self::eligibleThread($prefix).' ORDER BY p.pid';
    }
}
