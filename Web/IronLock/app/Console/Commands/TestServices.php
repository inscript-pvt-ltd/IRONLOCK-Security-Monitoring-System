<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Kreait\Firebase\Factory;

class TestServices extends Command
{
    protected $signature = 'test:services';
    protected $description = 'Test MySQL + FCM connectivity';

    public function handle()
    {
        // MySQL
        try {
            DB::connection()->getPdo();
            $count = DB::table('admins')->count();
            $this->info("✅ MySQL connected (admins: {$count})");
        } catch (\Throwable $e) {
            $this->error('❌ MySQL failed: ' . $e->getMessage());
        }

        // Firebase Cloud Messaging (push only)
        try {
            $firebase = (new Factory)
                ->withServiceAccount(storage_path('app/firebase/ironlock-firebase-adminsdk.json'));

            $firebase->createMessaging();
            $this->info('✅ Firebase Cloud Messaging ready');
        } catch (\Throwable $e) {
            $this->error('❌ FCM failed: ' . $e->getMessage());
        }
    }
}
