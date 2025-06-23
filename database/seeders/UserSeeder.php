<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Super Admin (1 user)
        User::create([
            'departemen_id' => 1,
            'role_id' => 1,
            'name' => 'Super Admin',
            'email' => 'superadmin@gmail.com',
            'password' => Hash::make('password'),
            'address' => 'Jl. Raya No. 1',
            'phone' => '62895353076420',
            'photo' => 'images/users/superadmin.png',
            'is_active' => true,
        ]);

        // HR GA (1 user)
        User::create([
            'departemen_id' => 2,
            'role_id' => 3,
            'name' => 'HR GA',
            'email' => 'hrga@gmail.com',
            'password' => Hash::make('password'),
            'phone' => '62895353076420',
            'photo' => 'images/users/hrga.png',
            'is_active' => true,
        ]);

        // Leader (2 user contoh lama)
        User::create([
            'departemen_id' => 3,
            'role_id' => 2,
            'name' => 'Leader Production',
            'email' => 'leaderproduction@gmail.com',
            'password' => Hash::make('password'),
            'phone' => '62895353076420',
            'photo' => 'images/users/leaderproduction.png',
            'is_active' => true,
        ]);
        User::create([
            'departemen_id' => 4,
            'role_id' => 2,
            'name' => 'Leader Finance',
            'email' => 'leaderfinance@gmail.com',
            'password' => Hash::make('password'),
            'phone' => '62895353076420',
            'photo' => 'images/users/leaderfinance.png',
            'is_active' => true,
        ]);

        // Tambahkan user lead untuk setiap departemen sesuai DepartemenSeeder
        $departemens = [
            // 1 => 'Admin',
            // 2 => 'Human Resources',
            3 => 'Production',
            4 => 'Finance',
            5 => 'Logistics',
            6 => 'Security',
            7 => 'IT',
            8 => 'Analytical Center',
            9 => 'Quality & Food Safety',
            10 => 'SHE',
            11 => 'Supply Chain',
            12 => 'Engineering',
            13 => 'Management System',
            14 => 'PPIC',
            15 => 'Procurement',
            16 => 'Warehouse',
            17 => 'Among Karya',
            18 => 'Nawakara',
            19 => 'Process Development',
        ];

        foreach ($departemens as $id => $name) {
            User::create([
                'departemen_id' => $id,
                'role_id' => 2, // 2 = Lead
                'name' => 'Lead ' . $name,
                'email' => 'lead' . strtolower(str_replace([' ', '&'], ['', ''], $name)) . '@gmail.com',
                'password' => Hash::make('password'),
                'phone' => '62895353076420',
                'photo' => 'images/users/lead' . strtolower(str_replace([' ', '&'], ['', ''], $name)) . '.png',
                'is_active' => true,
            ]);
        }

        // Checker (1 user)
        User::create([
            'departemen_id' => 5,
            'role_id' => 4,
            'name' => 'Checker',
            'email' => 'checker@gmail.com',
            'password' => Hash::make('password'),
            'phone' => '62895353076420',
            'photo' => 'images/users/checker.png',
            'is_active' => true,
        ]);

        // Head Unit (1 user)
        User::create([
            'departemen_id' => 5,
            'role_id' => 5,
            'name' => 'Head Unit',
            'email' => 'headunit@gmail.com',
            'password' => Hash::make('password'),
            'phone' => '62895353076420',
            'photo' => 'images/users/headunit.png',
            'is_active' => true,
        ]);

        // Security (1 user)
        User::create([
            'departemen_id' => 6,
            'role_id' => 6,
            'name' => 'Security',
            'email' => 'security@gmail.com',
            'password' => Hash::make('password'),
            'phone' => '62895353076420',
            'photo' => 'images/users/security.png',
            'is_active' => true,
        ]);
    }
}