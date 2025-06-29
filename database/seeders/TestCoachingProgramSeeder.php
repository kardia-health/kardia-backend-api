<?php

namespace Database\Seeders;

use App\Models\CoachingMessage;
use App\Models\CoachingProgram;
use App\Models\CoachingTask;
use App\Models\CoachingThread;
use App\Models\CoachingWeek;
use App\Models\RiskAssessment;
use App\Models\User;
use App\Models\UserProfile;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class TestCoachingProgramSeeder extends Seeder
{
    public function run()
    {
        $this->command->info('🚀 Creating test data for coaching program completion...');

        // 1. Buat Users dan Profiles
        $users = $this->createTestUsers();
        
        // 2. Buat Risk Assessments
        $riskAssessments = $this->createRiskAssessments($users);
        
        // 3. Buat Coaching Programs dengan berbagai skenario
        $programs = $this->createTestPrograms($users, $riskAssessments);
        
        // 4. Buat Weeks, Tasks, dan Threads untuk setiap program
        foreach ($programs as $program) {
            $this->createProgramContent($program);
        }

        $this->displayTestSummary($programs);
    }

    private function createTestUsers()
    {
        $users = collect();

        // User 1: Program yang sudah 28 hari
        $user1 = User::create([
            'email' => 'test-user-28days@example.com',
            'password' => bcrypt('password'),
            'role' => 'user',
            'email_verified_at' => now(),
        ]);

        $profile1 = UserProfile::create([
            'user_id' => $user1->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'date_of_birth' => '1985-05-15',
            'sex' => 'male',
            'country_of_residence' => 'Indonesia',
            'language' => 'id',
        ]);

        $users->push(['user' => $user1, 'profile' => $profile1]);

        // User 2: Program yang sudah 30 hari
        $user2 = User::create([
            'email' => 'test-user-30days@example.com',
            'password' => bcrypt('password'),
            'role' => 'user',
            'email_verified_at' => now(),
        ]);

        $profile2 = UserProfile::create([
            'user_id' => $user2->id,
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'date_of_birth' => '1990-08-20',
            'sex' => 'female',
            'country_of_residence' => 'Indonesia',
            'language' => 'id',
        ]);

        $users->push(['user' => $user2, 'profile' => $profile2]);

        // User 3: Program yang baru 20 hari
        $user3 = User::create([
            'email' => 'test-user-20days@example.com',
            'password' => bcrypt('password'),
            'role' => 'user',
            'email_verified_at' => now(),
        ]);

        $profile3 = UserProfile::create([
            'user_id' => $user3->id,
            'first_name' => 'Bob',
            'last_name' => 'Wilson',
            'date_of_birth' => '1982-12-10',
            'sex' => 'male',
            'country_of_residence' => 'Indonesia',
            'language' => 'id',
        ]);

        $users->push(['user' => $user3, 'profile' => $profile3]);

        // User 4: Program yang sudah completed
        $user4 = User::create([
            'email' => 'test-user-completed@example.com',
            'password' => bcrypt('password'),
            'role' => 'user',
            'email_verified_at' => now(),
        ]);

        $profile4 = UserProfile::create([
            'user_id' => $user4->id,
            'first_name' => 'Alice',
            'last_name' => 'Johnson',
            'date_of_birth' => '1988-03-25',
            'sex' => 'female',
            'country_of_residence' => 'Indonesia',
            'language' => 'id',
        ]);

        $users->push(['user' => $user4, 'profile' => $profile4]);

        return $users;
    }

    private function createRiskAssessments($users)
    {
        $riskAssessments = collect();

        foreach ($users as $index => $userData) {
            $riskAssessment = RiskAssessment::create([
                'user_profile_id' => $userData['profile']->id,
                'model_used' => 'SCORE2-Diabetes',
                'final_risk_percentage' => rand(15, 85) + (rand(0, 99) / 100), // Random 15.xx - 85.xx%
                'inputs' => [
                    'age' => rand(30, 65),
                    'smoking' => rand(0, 1) ? 'yes' : 'no',
                    'systolic_bp' => rand(120, 180),
                    'cholesterol' => rand(150, 280),
                    'diabetes' => rand(0, 1) ? 'yes' : 'no',
                ],
                'generated_values' => [
                    'bmi' => rand(20, 35) + (rand(0, 9) / 10),
                    'hdl_cholesterol' => rand(30, 80),
                    'risk_factors_count' => rand(2, 5),
                ],
                'result_details' => [
                    'risk_category' => 'moderate',
                    'recommendations' => ['diet_improvement', 'regular_exercise', 'stress_management'],
                ],
                'slug' => 'risk-assessment-' . Str::random(10),
            ]);

            $riskAssessments->push($riskAssessment);
        }

        return $riskAssessments;
    }

    private function createTestPrograms($users, $riskAssessments)
    {
        $programs = collect();

        // Program 1: 28 hari yang lalu (HARUS di-complete)
        $program1 = CoachingProgram::create([
            'user_profile_id' => $users[0]['profile']->id,
            'risk_assessment_id' => $riskAssessments[0]->id,
            'slug' => 'test-program-expired-28days',
            'title' => 'Program Hidup Sehat - 28 Hari',
            'description' => 'Program coaching untuk meningkatkan gaya hidup sehat dalam 28 hari.',
            'status' => 'active',
            'difficulty' => 'Standar & Konsisten',
            'start_date' => Carbon::now()->subDays(28)->format('Y-m-d'),
            'end_date' => Carbon::now()->format('Y-m-d'),
            'created_at' => Carbon::now()->subDays(28),
            'updated_at' => Carbon::now()->subDays(28),
        ]);
        $programs->push($program1);

        // Program 2: 30 hari yang lalu (HARUS di-complete)
        $program2 = CoachingProgram::create([
            'user_profile_id' => $users[1]['profile']->id,
            'risk_assessment_id' => $riskAssessments[1]->id,
            'slug' => 'test-program-expired-30days',
            'title' => 'Program Kardiovaskular - 30 Hari',
            'description' => 'Program khusus untuk kesehatan jantung dan pembuluh darah.',
            'status' => 'active',
            'difficulty' => 'Intensif & Menantang',
            'start_date' => Carbon::now()->subDays(30)->format('Y-m-d'),
            'end_date' => Carbon::now()->subDays(2)->format('Y-m-d'),
            'created_at' => Carbon::now()->subDays(30),
            'updated_at' => Carbon::now()->subDays(30),
        ]);
        $programs->push($program2);

        // Program 3: 20 hari yang lalu (TIDAK boleh di-complete)
        $program3 = CoachingProgram::create([
            'user_profile_id' => $users[2]['profile']->id,
            'risk_assessment_id' => $riskAssessments[2]->id,
            'slug' => 'test-program-active-20days',
            'title' => 'Program Diet Seimbang - Aktif',
            'description' => 'Program diet seimbang yang masih berjalan.',
            'status' => 'active',
            'difficulty' => 'Santai & Bertahap',
            'start_date' => Carbon::now()->subDays(20)->format('Y-m-d'),
            'end_date' => Carbon::now()->addDays(8)->format('Y-m-d'),
            'created_at' => Carbon::now()->subDays(20),
            'updated_at' => Carbon::now()->subDays(20),
        ]);
        $programs->push($program3);

        // Program 4: 35 hari yang lalu tapi sudah completed (DIABAIKAN)
        $program4 = CoachingProgram::create([
            'user_profile_id' => $users[3]['profile']->id,
            'risk_assessment_id' => $riskAssessments[3]->id,
            'slug' => 'test-program-already-completed',
            'title' => 'Program Selesai - Sudah Completed',
            'description' => 'Program yang sudah selesai sebelumnya.',
            'status' => 'completed',
            'difficulty' => 'Standar & Konsisten',
            'start_date' => Carbon::now()->subDays(35)->format('Y-m-d'),
            'end_date' => Carbon::now()->subDays(7)->format('Y-m-d'),
            'graduation_report' => [
                'completion_rate' => 85.5,
                'summary' => 'Program berhasil diselesaikan dengan baik.',
                'achievements' => ['Weight loss achieved', 'BP improved', 'Consistency maintained'],
            ],
            'created_at' => Carbon::now()->subDays(35),
            'updated_at' => Carbon::now()->subDays(7),
        ]);
        $programs->push($program4);

        return $programs;
    }

    private function createProgramContent($program)
    {
        // Buat 4 minggu untuk setiap program
        for ($weekNum = 1; $weekNum <= 4; $weekNum++) {
            $week = CoachingWeek::create([
                'coaching_program_id' => $program->id,
                'week_number' => $weekNum,
                'title' => "Minggu {$weekNum}: " . $this->getWeekTitle($weekNum),
                'description' => $this->getWeekDescription($weekNum),
            ]);

            // Buat 7 tasks untuk setiap minggu (1 task per hari)
            for ($day = 1; $day <= 7; $day++) {
                $taskDate = Carbon::parse($program->start_date)->addDays(($weekNum - 1) * 7 + ($day - 1));
                
                // Main mission
                CoachingTask::create([
                    'id' => Str::uuid(),
                    'coaching_week_id' => $week->id,
                    'task_date' => $taskDate->format('Y-m-d'),
                    'task_type' => 'main_mission',
                    'title' => "Misi Utama Hari {$day}",
                    'description' => $this->getTaskDescription('main_mission', $weekNum, $day),
                    'is_completed' => $program->status === 'completed' ? true : (rand(0, 100) < 70), // 70% completion rate
                ]);

                // Bonus challenge (random, tidak setiap hari)
                if (rand(0, 100) < 40) { // 40% chance ada bonus challenge
                    CoachingTask::create([
                        'id' => Str::uuid(),
                        'coaching_week_id' => $week->id,
                        'task_date' => $taskDate->format('Y-m-d'),
                        'task_type' => 'bonus_challenge',
                        'title' => "Tantangan Bonus Hari {$day}",
                        'description' => $this->getTaskDescription('bonus_challenge', $weekNum, $day),
                        'is_completed' => $program->status === 'completed' ? true : (rand(0, 100) < 30), // 30% completion rate
                    ]);
                }
            }
        }

        // Buat Coaching Thread dan beberapa messages
        $thread = CoachingThread::create([
            'coaching_program_id' => $program->id,
            'slug' => $program->slug . '-thread',
            'title' => 'Diskusi Program: ' . $program->title,
        ]);

        // Buat beberapa sample messages
        $messages = [
            ['role' => 'model', 'content' => ['text' => 'Selamat datang di program coaching Anda! Saya akan membantu Anda mencapai tujuan kesehatan.']],
            ['role' => 'user', 'content' => ['text' => 'Terima kasih! Saya siap memulai program ini.']],
            ['role' => 'model', 'content' => ['text' => 'Bagus! Mari kita mulai dengan mengatur rutinitas harian Anda.']],
        ];

        foreach ($messages as $msgData) {
            CoachingMessage::create([
                'coaching_thread_id' => $thread->id,
                'role' => $msgData['role'],
                'content' => $msgData['content'],
            ]);
        }
    }

    private function getWeekTitle($weekNum)
    {
        $titles = [
            1 => 'Fondasi & Pembiasaan',
            2 => 'Konsistensi & Rutinitas',
            3 => 'Peningkatan & Tantangan',
            4 => 'Konsolidasi & Evaluasi',
        ];

        return $titles[$weekNum] ?? "Minggu {$weekNum}";
    }

    private function getWeekDescription($weekNum)
    {
        $descriptions = [
            1 => 'Membangun fondasi kebiasaan sehat dan memahami pola hidup saat ini.',
            2 => 'Mempertahankan konsistensi dan membentuk rutinitas yang sustainable.',
            3 => 'Meningkatkan intensitas dan menghadapi tantangan yang lebih besar.',
            4 => 'Konsolidasi pembelajaran dan evaluasi kemajuan yang dicapai.',
        ];

        return $descriptions[$weekNum] ?? "Deskripsi untuk minggu {$weekNum}";
    }

    private function getTaskDescription($type, $weekNum, $day)
    {
        if ($type === 'main_mission') {
            $tasks = [
                'Lakukan olahraga ringan selama 30 menit',
                'Konsumsi minimal 8 gelas air putih',
                'Makan 5 porsi buah dan sayuran',
                'Tidur minimal 7 jam malam hari',
                'Lakukan meditasi atau relaksasi 10 menit',
                'Berjalan kaki minimal 5000 langkah',
                'Hindari makanan olahan dan fast food',
            ];
        } else {
            $tasks = [
                'Coba resep makanan sehat baru',
                'Ajak teman untuk berolahraga bersama',
                'Tulis jurnal refleksi harian',
                'Lakukan yoga atau stretching ekstra',
                'Eksplorasi aktivitas outdoor baru',
                'Buat meal prep untuk esok hari',
                'Praktikkan teknik pernapasan baru',
            ];
        }

        return $tasks[($weekNum + $day - 2) % count($tasks)];
    }

    private function displayTestSummary($programs)
    {
        $this->command->info('');
        $this->command->info('✅ Test data berhasil dibuat!');
        $this->command->info('');
        $this->command->table(
            ['Program Slug', 'Status', 'Created Days Ago', 'Expected Action'],
            $programs->map(function ($program) {
                $daysAgo = $program->created_at->diffInDays(Carbon::now());
                $expectedAction = $this->getExpectedAction($program, $daysAgo);
                
                return [
                    $program->slug,
                    $program->status,
                    $daysAgo,
                    $expectedAction
                ];
            })
        );

        $this->command->info('');
        $this->command->info('🧪 Testing Commands:');
        $this->command->info('1. php artisan programs:complete-expired --dry-run');
        $this->command->info('2. php artisan programs:complete-expired --force');
        $this->command->info('');
        $this->command->info('📊 Validate Results:');
        $this->command->info('php artisan tinker --execute="App\Models\CoachingProgram::where(\'slug\', \'like\', \'test-program%\')->get()->each(fn(\$p) => echo \"\$p->slug: \$p->status\\n\");"');
    }

    private function getExpectedAction($program, $daysAgo)
    {
        if ($program->status === 'completed') {
            return '❌ DIABAIKAN (sudah completed)';
        }
        
        if ($daysAgo >= 28) {
            return '✅ HARUS DI-COMPLETE';
        }
        
        return '⏳ TETAP ACTIVE (belum 28 hari)';
    }
}