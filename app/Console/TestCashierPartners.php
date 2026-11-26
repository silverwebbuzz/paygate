<?php

namespace App\Console;

final class TestCashierPartners
{
    /**
     * @return list<array{code: string, users: list<array{id: string, name: string, mobile: string}>}>
     */
    public static function all(): array
    {
        return [
            [
                'code' => 'ECLINIC',
                'users' => [
                    ['id' => 'e01', 'name' => 'Aarav Sharma', 'mobile' => '9811101001'],
                    ['id' => 'e02', 'name' => 'Diya Patel', 'mobile' => '9811101002'],
                    ['id' => 'e03', 'name' => 'Kabir Singh', 'mobile' => '9811101003'],
                    ['id' => 'e04', 'name' => 'Ananya Iyer', 'mobile' => '9811101004'],
                    ['id' => 'e05', 'name' => 'Vihaan Gupta', 'mobile' => '9811101005'],
                    ['id' => 'e06', 'name' => 'Meera Nair', 'mobile' => '9811101006'],
                    ['id' => 'e07', 'name' => 'Arjun Reddy', 'mobile' => '9811101007'],
                    ['id' => 'e08', 'name' => 'Ishita Kapoor', 'mobile' => '9811101008'],
                    ['id' => 'e09', 'name' => 'Rohan Desai', 'mobile' => '9811101009'],
                    ['id' => 'e10', 'name' => 'Sara Khan', 'mobile' => '9811101010'],
                ],
            ],
            [
                'code' => 'TRILOK',
                'users' => [
                    ['id' => 't01', 'name' => 'Dev Malhotra', 'mobile' => '9811102001'],
                    ['id' => 't02', 'name' => 'Pooja Joshi', 'mobile' => '9811102002'],
                    ['id' => 't03', 'name' => 'Nikhil Bansal', 'mobile' => '9811102003'],
                    ['id' => 't04', 'name' => 'Kavya Menon', 'mobile' => '9811102004'],
                    ['id' => 't05', 'name' => 'Aditya Rao', 'mobile' => '9811102005'],
                    ['id' => 't06', 'name' => 'Neha Chawla', 'mobile' => '9811102006'],
                    ['id' => 't07', 'name' => 'Sanjay Pillai', 'mobile' => '9811102007'],
                    ['id' => 't08', 'name' => 'Riya Bhatt', 'mobile' => '9811102008'],
                    ['id' => 't09', 'name' => 'Manoj Verma', 'mobile' => '9811102009'],
                    ['id' => 't10', 'name' => 'Aisha Qureshi', 'mobile' => '9811102010'],
                ],
            ],
            [
                'code' => 'MENET',
                'users' => [
                    ['id' => 'm01', 'name' => 'Harsh Jain', 'mobile' => '9811103001'],
                    ['id' => 'm02', 'name' => 'Sneha Kulkarni', 'mobile' => '9811103002'],
                    ['id' => 'm03', 'name' => 'Vikram Sethi', 'mobile' => '9811103003'],
                    ['id' => 'm04', 'name' => 'Tanvi Shah', 'mobile' => '9811103004'],
                    ['id' => 'm05', 'name' => 'Rahul Mehta', 'mobile' => '9811103005'],
                    ['id' => 'm06', 'name' => 'Divya Bose', 'mobile' => '9811103006'],
                    ['id' => 'm07', 'name' => 'Karan Gill', 'mobile' => '9811103007'],
                    ['id' => 'm08', 'name' => 'Anjali Dutta', 'mobile' => '9811103008'],
                    ['id' => 'm09', 'name' => 'Suresh Yadav', 'mobile' => '9811103009'],
                    ['id' => 'm10', 'name' => 'Priya Nambiar', 'mobile' => '9811103010'],
                ],
            ],
            [
                'code' => 'SWBUZZIN',
                'users' => [
                    ['id' => 'i01', 'name' => 'Omkar Pawar', 'mobile' => '9811104001'],
                    ['id' => 'i02', 'name' => 'Lakshmi Krishnan', 'mobile' => '9811104002'],
                    ['id' => 'i03', 'name' => 'Farhan Ali', 'mobile' => '9811104003'],
                    ['id' => 'i04', 'name' => 'Nisha Agarwal', 'mobile' => '9811104004'],
                    ['id' => 'i05', 'name' => 'Deepak Chauhan', 'mobile' => '9811104005'],
                    ['id' => 'i06', 'name' => 'Shreya Mukherjee', 'mobile' => '9811104006'],
                    ['id' => 'i07', 'name' => 'Amit Trivedi', 'mobile' => '9811104007'],
                    ['id' => 'i08', 'name' => 'Pallavi Hegde', 'mobile' => '9811104008'],
                    ['id' => 'i09', 'name' => 'Imran Shaikh', 'mobile' => '9811104009'],
                    ['id' => 'i10', 'name' => 'Ritu Bhardwaj', 'mobile' => '9811104010'],
                ],
            ],
            [
                'code' => 'SWBUZZ',
                'users' => [
                    ['id' => 'c01', 'name' => 'Yash Oberoi', 'mobile' => '9811105001'],
                    ['id' => 'c02', 'name' => 'Fatima Syed', 'mobile' => '9811105002'],
                    ['id' => 'c03', 'name' => 'Gaurav Tiwari', 'mobile' => '9811105003'],
                    ['id' => 'c04', 'name' => 'Anushka Rao', 'mobile' => '9811105004'],
                    ['id' => 'c05', 'name' => 'Vivek Nanda', 'mobile' => '9811105005'],
                    ['id' => 'c06', 'name' => 'Sana Mirza', 'mobile' => '9811105006'],
                    ['id' => 'c07', 'name' => 'Harish Patil', 'mobile' => '9811105007'],
                    ['id' => 'c08', 'name' => 'Kritika Sen', 'mobile' => '9811105008'],
                    ['id' => 'c09', 'name' => 'Naveen Lal', 'mobile' => '9811105009'],
                    ['id' => 'c10', 'name' => 'Zoya Merchant', 'mobile' => '9811105010'],
                ],
            ],
        ];
    }

    public static function bankAccount(string $userId): string
    {
        $digits = preg_replace('/\D/', '', $userId) ?: '1';

        return '5010'.str_pad(substr($digits, 0, 6), 6, '0', STR_PAD_LEFT).'000001';
    }

    public static function loosenRedis(): ?string
    {
        if (class_exists(\Redis::class)) {
            return null;
        }

        if (config('queue.default') === 'redis') {
            config(['queue.default' => 'database']);
        }

        if (config('cache.default') === 'redis') {
            config(['cache.default' => 'file']);
        }

        foreach (['queue', 'queue.connection', 'cache', 'cache.store'] as $abstract) {
            if (app()->resolved($abstract)) {
                app()->forgetInstance($abstract);
            }
        }

        return 'This PHP has no Redis extension, so webhooks and alerts are written to the database queue instead. Install the redis extension for the PHP that runs artisan, then restart Horizon, and those will send.';
    }
}
