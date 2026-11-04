<?php

// Mailable para enviar por correo el archivo de backup (SQL o JSON) generado por la aplicacion.

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class BackupReady extends Mailable
{
    use Queueable, SerializesModels;

    public string $mode;      // 'sql' o 'json'
    public string $filename;  // nombre del adjunto

    // Almacena el modo y nombre del archivo de backup.
    public function __construct(string $mode, string $filename)
    {
        $this->mode = $mode;
        $this->filename = $filename;
    }

    // Construye el mensaje de correo con el asunto, la vista y el archivo adjunto.
    public function build()
    {
        $app = config('app.name', 'Aplicación');
        $subject = "Backup {$this->mode} — {$app}";

        return $this->subject($subject)
            ->view('emails.backup-ready')
            ->with(['mode'=>$this->mode,'app'=>$app])
            ->attach(storage_path('app/backups/' . $this->filename));
    }
}
