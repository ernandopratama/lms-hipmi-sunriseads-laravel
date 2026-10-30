<?php

namespace App\Console\Commands;

use App\Models\{Organization, School, User};
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{Cache, DB, Validator};

class InstallAdmin extends Command
{
    protected $signature = 'sunrise:install-admin {--name=} {--email=} {--organization=} {--school=}';
    protected $description = 'Create the first administrator only; never overwrite existing accounts';

    public function handle(): int
    {
        // No password arguments: an interactive secret prompt or a private .env.
        $data = [
            'name' => $this->option('name') ?: config('setup.name'),
            'email' => $this->option('email') ?: config('setup.email'),
            'organization' => $this->option('organization') ?: config('setup.organization'),
            'school' => $this->option('school') ?: config('setup.school'),
        ];
        foreach ($data as $field => $value) {
            if (!$value && $this->input->isInteractive()) $data[$field] = $this->ask(ucfirst($field));
        }
        $data['password'] = $this->input->isInteractive() ? $this->secret('Password admin (12–72 byte)') : config('setup.password');
        $validator = Validator::make($data, [
            'name'=>'required|string|max:150', 'email'=>'required|email|max:255',
            'organization'=>'required|string|max:200','school'=>'required|string|max:200',
            'password'=>['required','string','min:12',function ($attribute, $value, $fail) { if (strlen($value)>72) $fail('Password maksimal 72 byte.'); }],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) $this->error($message);
            return self::FAILURE;
        }
        return Cache::lock('first-admin-install', 60)->block(5, function () use ($data) {
            return DB::transaction(function () use ($data) {
                if (User::exists()) {
                    $this->error('Setup ditolak: pengguna sudah tersedia. Gunakan menu Pengguna melalui admin yang ada.');
                    return self::FAILURE;
                }
                $organization = Organization::create(['name'=>$data['organization']]);
                $school = School::create(['organization_id'=>$organization->id,'name'=>$data['school']]);
                $admin = User::create([
                    'name'=>$data['name'],'email'=>strtolower($data['email']),'password'=>$data['password'],
                    'organization_id'=>$organization->id,'school_id'=>$school->id,'role'=>'admin','status'=>'active',
                ]);
                // A fresh database has no roles or permissions; without them the original screens would answer 403.
                $this->callSilently('sunrise:seed-baseline', ['--organization' => $organization->id]);
                $this->callSilently('sunrise:install-materials');
                DB::table('user_roles')->insert(['id' => (string) \Illuminate\Support\Str::ulid(), 'user_id' => $admin->id, 'school_id' => $school->id, 'created_at' => now(),
                    'role_id' => DB::table('roles')->where('organization_id', $organization->id)->where('slug', 'super-admin')->value('id')]);
                $this->info('Admin pertama dibuat. Hapus SETUP_* dari .env dan hapus cron setup satu kali.');
                return self::SUCCESS;
            });
        });
    }
}
