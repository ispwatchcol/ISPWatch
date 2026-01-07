<?php

namespace App\Mail;

use App\Models\SupportTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SendTicketNotification extends Mailable
{
    use Queueable, SerializesModels;

    public $ticket;
    public $type;

    /**
     * Create a new message instance.
     *
     * @param  SupportTicket  $ticket
     * @param  string  $type  'created', 'updated', 'message'
     */
    public function __construct(SupportTicket $ticket, $type)
    {
        $this->ticket = $ticket;
        $this->type = $type;
    }

    /**
     * Build the message.
     */
    public function build()
    {
        $subject = "Ticket #{$this->ticket->id}: {$this->ticket->subject}";

        if ($this->type === 'created') {
            $subject = "[Nuevo] " . $subject;
        } elseif ($this->type === 'updated') {
            $subject = "[Actualizado] " . $subject;
        } elseif ($this->type === 'message') {
            $subject = "[Mensaje] " . $subject;
        }

        return $this->subject($subject)
            ->view('emails.ticket_notification');
    }
}
