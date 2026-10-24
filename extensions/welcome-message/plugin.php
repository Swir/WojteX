<?php
// Trusted server-side extension. Enabled explicitly in the administration panel.
return static function(): void {
    \Illuminate\Support\Facades\Event::listen('veyra.user.registered',function(int $userId): void {
        if(!\App\Services\ExtensionRegistry::enabled('shoutbox'))return;
        $user=\Illuminate\Support\Facades\DB::table('users')->find($userId);
        if(!$user)return;
        \Illuminate\Support\Facades\DB::table('shouts')->insert([
            'user_id'=>$userId,'body'=>__('extension.welcome-message.message'),
            'created_at'=>now(),'updated_at'=>now(),
        ]);
    });
};
