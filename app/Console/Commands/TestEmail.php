<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class TestEmail extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:test-email {email?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test email sending configuration';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $email = $this->argument('email') ?? 'test@example.com';

        $this->info('Testing email configuration...');
        $this->info('MAIL_FROM_ADDRESS: ' . config('mail.from.address'));
        $this->info('MAIL_HOST: ' . config('mail.mailers.smtp.host'));
        $this->info('MAIL_PORT: ' . config('mail.mailers.smtp.port'));
        $this->info('MAIL_USERNAME: ' . config('mail.mailers.smtp.username'));
        $this->info('MAIL_ENCRYPTION: ' . config('mail.mailers.smtp.encryption'));

        try {
            $this->info('Sending test email to: ' . $email);

            Mail::raw('Test email from Skate & Snow API. Time: ' . now(), function ($message) use ($email) {
                $message->to($email)
                        ->subject('Test Email - Skate & Snow');
            });

            $this->info('✅ Email sent successfully!');
            Log::info('Test email sent successfully to: ' . $email);

        } catch (\Exception $e) {
            $this->error('❌ Email sending failed: ' . $e->getMessage());
            Log::error('Test email failed: ' . $e->getMessage());
            $this->error('Stack trace: ' . $e->getTraceAsString());
        }
    }
}
