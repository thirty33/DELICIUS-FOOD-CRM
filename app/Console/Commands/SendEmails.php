<?php

namespace App\Console\Commands;

use App\Mail\UserCredentialsEmail;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Exception;

class SendEmails extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:send-emails {email? : The email address to send credentials to}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send credentials email to a user';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $email = $this->argument('email') ?? 'contact_convenio_consolidado@example.com';
        
        $this->info("Starting to send credentials email to {$email}...");
        
        try {
            // Find the user by email
            $user = User::where('email', $email)->first();
            
            if (!$user) {
                $this->error("User with email {$email} not found.");
                return Command::FAILURE;
            }
            
            // Send the credentials email
            Mail::to($user->email)->send(new UserCredentialsEmail($user));
            
            $this->info("Credentials email sent successfully to {$email}");
            Log::info("Credentials email sent successfully to {$email}");
            
        } catch (Exception $e) {
            $this->error('Failed to send email: ' . $e->getMessage());
            Log::error('Email sending failed', [
                'error' => $e->getMessage(),
                'recipient' => $email,
                'trace' => $e->getTraceAsString()
            ]);
            
            return Command::FAILURE;
        }
        
        return Command::SUCCESS;
    }
}