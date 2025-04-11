<?php

namespace App\Console\Commands;

use App\Mail\OrderEmail;
use App\Models\Order;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Exception;

class SendOrderEmail extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:send-order-email {email? : The email address to send order details to}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Envía un correo electrónico con los detalles de la orden a un usuario específico';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $email = $this->argument('email') ?? 'contact_convenio_consolidado@example.com';
        
        $this->info("Buscando usuario con email: {$email}");
        
        try {
            // Buscar el usuario
            $user = User::where('email', $email)->first();
            
            if (!$user) {
                $this->error("No se encontró ningún usuario con el email {$email}");
                return Command::FAILURE;
            }
            
            // Buscar la primera orden del usuario
            $order = Order::where('user_id', $user->id)->first();
            
            if (!$order) {
                $this->error("No se encontró ninguna orden para el usuario {$user->name}");
                return Command::FAILURE;
            }
            
            $this->info("Enviando correo con detalles de la orden #{$order->order_number} a {$email}");
            
            // Enviar el correo
            Mail::to($email)->send(new OrderEmail($order));
            
            $this->info("Correo enviado exitosamente");
            Log::info("Correo de orden enviado", [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'recipient' => $email
            ]);
            
            return Command::SUCCESS;
            
        } catch (Exception $e) {
            $this->error("Error al enviar el correo: " . $e->getMessage());
            Log::error("Error al enviar correo de orden", [
                'error' => $e->getMessage(),
                'recipient' => $email,
                'trace' => $e->getTraceAsString()
            ]);
            
            return Command::FAILURE;
        }
    }
}