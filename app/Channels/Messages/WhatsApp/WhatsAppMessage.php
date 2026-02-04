<?php

namespace App\Channels\Messages\WhatsApp;

abstract class WhatsAppMessage
{
    abstract public function toArray(): array;
}