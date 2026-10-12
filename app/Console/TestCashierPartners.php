<?php

namespace App\Console;

final class TestCashierPartners
{
    /**
     * @return list<array{code: string, users: list<array{id: string, name: string, email: string, mobile: string}>}>
     */
    public static function all(): array
    {
        return [
            [
                'code' => 'ECLINIC',
                'users' => [
                    ['id' => 'e01', 'name' => 'Aarav Sharma', 'email' => 'aarav.sharma@eclinicpro.com', 'mobile' => '9811101001'],
                    ['id' => 'e02', 'name' => 'Diya Patel', 'email' => 'diya.patel@eclinicpro.com', 'mobile' => '9811101002'],
                    ['id' => 'e03', 'name' => 'Kabir Singh', 'email' => 'kabir.singh@eclinicpro.com', 'mobile' => '9811101003'],
                    ['id' => 'e04', 'name' => 'Ananya Iyer', 'email' => 'ananya.iyer@eclinicpro.com', 'mobile' => '9811101004'],
                    ['id' => 'e05', 'name' => 'Vihaan Gupta', 'email' => 'vihaan.gupta@eclinicpro.com', 'mobile' => '9811101005'],
                    ['id' => 'e06', 'name' => 'Meera Nair', 'email' => 'meera.nair@eclinicpro.com', 'mobile' => '9811101006'],
                    ['id' => 'e07', 'name' => 'Arjun Reddy', 'email' => 'arjun.reddy@eclinicpro.com', 'mobile' => '9811101007'],
                    ['id' => 'e08', 'name' => 'Ishita Kapoor', 'email' => 'ishita.kapoor@eclinicpro.com', 'mobile' => '9811101008'],
                    ['id' => 'e09', 'name' => 'Rohan Desai', 'email' => 'rohan.desai@eclinicpro.com', 'mobile' => '9811101009'],
                    ['id' => 'e10', 'name' => 'Sara Khan', 'email' => 'sara.khan@eclinicpro.com', 'mobile' => '9811101010'],
                ],
            ],
            [
                'code' => 'TRILOK',
                'users' => [
                    ['id' => 't01', 'name' => 'Dev Malhotra', 'email' => 'dev.malhotra@trilokexport.com', 'mobile' => '9811102001'],
                    ['id' => 't02', 'name' => 'Pooja Joshi', 'email' => 'pooja.joshi@trilokexport.com', 'mobile' => '9811102002'],
                    ['id' => 't03', 'name' => 'Nikhil Bansal', 'email' => 'nikhil.bansal@trilokexport.com', 'mobile' => '9811102003'],
                    ['id' => 't04', 'name' => 'Kavya Menon', 'email' => 'kavya.menon@trilokexport.com', 'mobile' => '9811102004'],
                    ['id' => 't05', 'name' => 'Aditya Rao', 'email' => 'aditya.rao@trilokexport.com', 'mobile' => '9811102005'],
                    ['id' => 't06', 'name' => 'Neha Chawla', 'email' => 'neha.chawla@trilokexport.com', 'mobile' => '9811102006'],
                    ['id' => 't07', 'name' => 'Sanjay Pillai', 'email' => 'sanjay.pillai@trilokexport.com', 'mobile' => '9811102007'],
                    ['id' => 't08', 'name' => 'Riya Bhatt', 'email' => 'riya.bhatt@trilokexport.com', 'mobile' => '9811102008'],
                    ['id' => 't09', 'name' => 'Manoj Verma', 'email' => 'manoj.verma@trilokexport.com', 'mobile' => '9811102009'],
                    ['id' => 't10', 'name' => 'Aisha Qureshi', 'email' => 'aisha.qureshi@trilokexport.com', 'mobile' => '9811102010'],
                ],
            ],
            [
                'code' => 'MENET',
                'users' => [
                    ['id' => 'm01', 'name' => 'Harsh Jain', 'email' => 'harsh.jain@menetzero.com', 'mobile' => '9811103001'],
                    ['id' => 'm02', 'name' => 'Sneha Kulkarni', 'email' => 'sneha.kulkarni@menetzero.com', 'mobile' => '9811103002'],
                    ['id' => 'm03', 'name' => 'Vikram Sethi', 'email' => 'vikram.sethi@menetzero.com', 'mobile' => '9811103003'],
                    ['id' => 'm04', 'name' => 'Tanvi Shah', 'email' => 'tanvi.shah@menetzero.com', 'mobile' => '9811103004'],
                    ['id' => 'm05', 'name' => 'Rahul Mehta', 'email' => 'rahul.mehta@menetzero.com', 'mobile' => '9811103005'],
                    ['id' => 'm06', 'name' => 'Divya Bose', 'email' => 'divya.bose@menetzero.com', 'mobile' => '9811103006'],
                    ['id' => 'm07', 'name' => 'Karan Gill', 'email' => 'karan.gill@menetzero.com', 'mobile' => '9811103007'],
                    ['id' => 'm08', 'name' => 'Anjali Dutta', 'email' => 'anjali.dutta@menetzero.com', 'mobile' => '9811103008'],
                    ['id' => 'm09', 'name' => 'Suresh Yadav', 'email' => 'suresh.yadav@menetzero.com', 'mobile' => '9811103009'],
                    ['id' => 'm10', 'name' => 'Priya Nambiar', 'email' => 'priya.nambiar@menetzero.com', 'mobile' => '9811103010'],
                ],
            ],
            [
                'code' => 'SWBUZZIN',
                'users' => [
                    ['id' => 'i01', 'name' => 'Omkar Pawar', 'email' => 'omkar.pawar@silverwebbuzz.in', 'mobile' => '9811104001'],
                    ['id' => 'i02', 'name' => 'Lakshmi Krishnan', 'email' => 'lakshmi.krishnan@silverwebbuzz.in', 'mobile' => '9811104002'],
                    ['id' => 'i03', 'name' => 'Farhan Ali', 'email' => 'farhan.ali@silverwebbuzz.in', 'mobile' => '9811104003'],
                    ['id' => 'i04', 'name' => 'Nisha Agarwal', 'email' => 'nisha.agarwal@silverwebbuzz.in', 'mobile' => '9811104004'],
                    ['id' => 'i05', 'name' => 'Deepak Chauhan', 'email' => 'deepak.chauhan@silverwebbuzz.in', 'mobile' => '9811104005'],
                    ['id' => 'i06', 'name' => 'Shreya Mukherjee', 'email' => 'shreya.mukherjee@silverwebbuzz.in', 'mobile' => '9811104006'],
                    ['id' => 'i07', 'name' => 'Amit Trivedi', 'email' => 'amit.trivedi@silverwebbuzz.in', 'mobile' => '9811104007'],
                    ['id' => 'i08', 'name' => 'Pallavi Hegde', 'email' => 'pallavi.hegde@silverwebbuzz.in', 'mobile' => '9811104008'],
                    ['id' => 'i09', 'name' => 'Imran Shaikh', 'email' => 'imran.shaikh@silverwebbuzz.in', 'mobile' => '9811104009'],
                    ['id' => 'i10', 'name' => 'Ritu Bhardwaj', 'email' => 'ritu.bhardwaj@silverwebbuzz.in', 'mobile' => '9811104010'],
                ],
            ],
            [
                'code' => 'SWBUZZ',
                'users' => [
                    ['id' => 'c01', 'name' => 'Yash Oberoi', 'email' => 'yash.oberoi@silverwebbuzz.com', 'mobile' => '9811105001'],
                    ['id' => 'c02', 'name' => 'Fatima Syed', 'email' => 'fatima.syed@silverwebbuzz.com', 'mobile' => '9811105002'],
                    ['id' => 'c03', 'name' => 'Gaurav Tiwari', 'email' => 'gaurav.tiwari@silverwebbuzz.com', 'mobile' => '9811105003'],
                    ['id' => 'c04', 'name' => 'Anushka Rao', 'email' => 'anushka.rao@silverwebbuzz.com', 'mobile' => '9811105004'],
                    ['id' => 'c05', 'name' => 'Vivek Nanda', 'email' => 'vivek.nanda@silverwebbuzz.com', 'mobile' => '9811105005'],
                    ['id' => 'c06', 'name' => 'Sana Mirza', 'email' => 'sana.mirza@silverwebbuzz.com', 'mobile' => '9811105006'],
                    ['id' => 'c07', 'name' => 'Harish Patil', 'email' => 'harish.patil@silverwebbuzz.com', 'mobile' => '9811105007'],
                    ['id' => 'c08', 'name' => 'Kritika Sen', 'email' => 'kritika.sen@silverwebbuzz.com', 'mobile' => '9811105008'],
                    ['id' => 'c09', 'name' => 'Naveen Lal', 'email' => 'naveen.lal@silverwebbuzz.com', 'mobile' => '9811105009'],
                    ['id' => 'c10', 'name' => 'Zoya Merchant', 'email' => 'zoya.merchant@silverwebbuzz.com', 'mobile' => '9811105010'],
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
