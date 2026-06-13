<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    /**
     * Seed the initial admin user.
     *
     * ⚠️ IMPORTANT: Change the default password immediately after first login!
     */
    public function run(): void
    {
        DB::table('admins')->insert([
            'id' => '550e8400-e29b-41d4-a716-446655440000',
            'name' => 'System Administrator',
            'email' => 'admin@ironlock.co.uk',
            'password' => Hash::make('password'), // ⚠️ Change this immediately!
            'status' => 'active',
            'role' => 'admin',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
