<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class AnnouncementNotification extends Notification
{
    // Hapus ShouldQueue dan Queueable agar sinkron (tanpa queue work)

    protected $title;
    protected $message;
    protected $announcementId;
    protected $authorName;

    public function __construct($title, $message, $announcementId, $authorName = 'Admin')
    {
        $this->title = $title;
        $this->message = $message;
        $this->announcementId = $announcementId;
        $this->authorName = $authorName;
    }

    public function via($notifiable)
    {
        return ['database'];
    }

    public function toArray($notifiable)
    {
        return [
            'title'   => $this->title,
            'message' => $this->message,
            'link'    => "/announcements/{$this->announcementId}",
            'type'    => 'announcement',
            'author'  => $this->authorName,
            'icon'    => 'megaphone',
            'created_at' => now(),
        ];
    }
}