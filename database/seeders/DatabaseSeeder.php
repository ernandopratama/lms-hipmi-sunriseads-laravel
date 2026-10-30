<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\{Artisan, DB, Hash};
use Illuminate\Support\Str;
use App\Models\{Organization, School, User, Course, CourseModule, Lesson, Assignment};

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (!app()->environment('local', 'testing')) {
            throw new \RuntimeException('Seed contoh hanya untuk local/testing.');
        }
        $password = env('DEMO_PASSWORD');
        if (!$password || (strlen($password) < 12 || strlen($password) > 72)) {
            throw new \RuntimeException('Atur DEMO_PASSWORD 12–72 byte sebelum seed.');
        }
        DB::transaction(function () use ($password) {
            $org = Organization::firstOrCreate(['name' => 'Sunrise Academy Demo']);
            $school = School::firstOrCreate(['organization_id' => $org->id, 'name' => 'SMK Contoh Nusantara']);
            Artisan::call('sunrise:seed-baseline', ['--organization' => $org->id]);
            // One login per baseline role; users.role is the legacy column, the source role lives in user_roles.
            $legacy = ['super-admin' => 'admin', 'school-admin' => 'admin', 'teacher' => 'teacher', 'student' => 'student'];
            $ids = [];
            foreach (DB::table('roles')->where('organization_id', $org->id)->get() as $role) {
                $u = User::firstOrNew(['email' => $role->slug . '@sunrise.example']);
                $u->forceFill(['name' => $role->name . ' Demo', 'password' => Hash::make($password), 'organization_id' => $org->id, 'school_id' => $school->id, 'role' => $legacy[$role->slug] ?? 'unmapped', 'status' => 'active', 'plan_rank' => 0])->save();
                $ids[$role->slug] = $u->id;
                if (!DB::table('user_roles')->where(['user_id' => $u->id, 'role_id' => $role->id, 'school_id' => $school->id])->exists()) DB::table('user_roles')->insert(['id' => (string)Str::ulid(), 'user_id' => $u->id, 'role_id' => $role->id, 'school_id' => $school->id, 'created_at' => now()]);
            }
            $now = now();
            if (isset($ids['student'])) DB::table('students')->updateOrInsert(['user_id' => $ids['student']], ['id' => DB::table('students')->where('user_id', $ids['student'])->value('id') ?? (string)Str::ulid(), 'school_id' => $school->id, 'student_number' => 'DEMO-001', 'full_name' => 'Siswa Demo', 'status' => 'ACTIVE', 'activated_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
            if (isset($ids['teacher'])) DB::table('teachers')->updateOrInsert(['user_id' => $ids['teacher']], ['id' => DB::table('teachers')->where('user_id', $ids['teacher'])->value('id') ?? (string)Str::ulid(), 'school_id' => $school->id, 'full_name' => 'Guru Demo', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
            if (isset($ids['parent'])) {
                DB::table('parents')->updateOrInsert(['user_id' => $ids['parent']], ['id' => DB::table('parents')->where('user_id', $ids['parent'])->value('id') ?? (string)Str::ulid(), 'full_name' => 'Orang Tua Demo', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
                if (isset($ids['student'])) DB::table('student_parent_links')->updateOrInsert(['student_id' => DB::table('students')->where('user_id', $ids['student'])->value('id'), 'parent_id' => DB::table('parents')->where('user_id', $ids['parent'])->value('id')], ['relationship' => 'WALI', 'verification_status' => 'VERIFIED', 'verified_at' => $now, 'created_at' => $now]);
            }
            $course = Course::firstOrCreate(['organization_id' => $org->id, 'title' => 'Memulai Bisnis Digital'], ['description' => 'Latihan mengenali pelanggan, menyusun penawaran, dan merencanakan toko digital. Konten contoh untuk pengujian build awal.', 'status' => 'published']);
            $topics = [['Mengenal pelanggan', 'Wawancarai tiga calon pelanggan. Catat masalah yang paling sering mereka alami, produk yang digunakan, dan alasan pembelian. Ringkas satu kebutuhan yang dapat Anda bantu.'], ['Menyusun penawaran', 'Pilih satu produk. Tuliskan manfaat utama, bukti manfaat, harga perkiraan, serta alasan pelanggan mempercayai penawaran Anda. Hindari klaim yang tidak bisa dibuktikan.'], ['Merencanakan toko digital', 'Buat rancangan nama toko, deskripsi, tiga produk awal, foto yang dibutuhkan, serta langkah pelayanan pelanggan. Jangan menyerahkan password atau OTP akun Anda.']];
            foreach ($topics as $i => $topic) {
                $module = CourseModule::firstOrCreate(['course_id' => $course->id, 'position' => $i + 1], ['title' => $topic[0], 'minimum_rank' => 0]);
                Lesson::firstOrCreate(['course_module_id' => $module->id, 'position' => 1], ['title' => $topic[0], 'content' => $topic[1], 'status' => 'published']);
            }
            Assignment::firstOrCreate(['organization_id' => $org->id, 'course_id' => $course->id, 'title' => 'Rancang penawaran produk pertama'], ['instructions' => 'Tuliskan target pelanggan, masalahnya, produk yang ditawarkan, manfaat, harga perkiraan, dan rencana validasi kepada tiga calon pelanggan. Ini tugas contoh build awal.', 'status' => 'published']);
        });
    }
}
