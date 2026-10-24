<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DemoSeeder
{
    public function seed(int $owner): void
    {
        $users = [$owner];
        foreach ([['Maja K.', 'cyan'], ['PixelPilot', 'amber'], ['CodeRaven', 'rose'], ['Oskar', 'green'], ['Nova', 'blue']] as $i => $profile) {
            $users[] = DB::table('users')->insertGetId([
                'name' => $profile[0],
                'email' => 'demo-'.($i + 1).'@example.invalid',
                'password' => Hash::make(Str::random(48)),
                'role' => 'member',
                'color' => $profile[1],
                'bio' => __('demo.profile_bio'),
                'created_at' => now()->subDays(20 - $i),
                'updated_at' => now(),
            ]);
        }

        $categories = DB::table('categories')->orderBy('id')->pluck('id')->all();
        $topics = [
            [0, 0, 'welcome', true],
            [1, 2, 'projects', false],
            [2, 1, 'setups', false],
            [3, 3, 'git', false],
            [1, 4, 'learning', false],
            [0, 5, 'wins', false],
            [3, 1, 'asking', false],
            [2, 2, 'weekend', false],
        ];
        foreach ($topics as $i => [$category, $author, $key, $pinned]) {
            $at = now()->subHours(28 - $i * 3);
            $title = __('demo.topics.'.$key.'.title');
            $threadId = DB::table('threads')->insertGetId([
                'category_id' => $categories[$category],
                'user_id' => $users[$author],
                'title' => $title,
                'slug' => Str::slug($title),
                'is_pinned' => $pinned,
                'views' => 0,
                'created_at' => $at,
                'updated_at' => $at,
            ]);
            $postId = DB::table('posts')->insertGetId([
                'thread_id' => $threadId,
                'user_id' => $users[$author],
                'body' => __('demo.topics.'.$key.'.body'),
                'body_format' => 'markdown',
                'created_at' => $at,
                'updated_at' => $at,
            ]);
            if ($i < 6) {
                foreach ([__('demo.replies.first'), __('demo.replies.second')] as $n => $reply) {
                    $time = $at->copy()->addMinutes(20 + $n * 35);
                    DB::table('posts')->insert([
                        'thread_id' => $threadId,
                        'user_id' => $users[($i + $n + 1) % count($users)],
                        'body' => $reply,
                        'body_format' => 'markdown',
                        'created_at' => $time,
                        'updated_at' => $time,
                    ]);
                    DB::table('threads')->where('id', $threadId)->update(['updated_at' => $time]);
                }
            }
            foreach ($users as $n => $userId) {
                if ($userId !== $users[$author] && $n % 2 === $i % 2) {
                    DB::table('reactions')->insert([
                        'post_id' => $postId, 'user_id' => $userId, 'created_at' => $at, 'updated_at' => $at,
                    ]);
                }
            }
        }
        foreach ([[1, 'hello'], [2, 'new_forum'], [3, 'guide'], [0, 'demo_notice']] as $i => [$author, $key]) {
            DB::table('shouts')->insert([
                'user_id' => $users[$author],
                'body' => __('demo.shouts.'.$key),
                'created_at' => now()->subMinutes(10 - $i * 2),
                'updated_at' => now(),
            ]);
        }
        ForumService::setSetting('demo_content', true);
    }
}
