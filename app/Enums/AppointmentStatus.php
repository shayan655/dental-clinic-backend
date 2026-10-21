<?php

namespace App\Enums;

enum AppointmentStatus: string {
     case Pending = 'pending';
     case Confirmed = 'confirmed';     
     case InProgress = 'inProgress';     
     case Completed = 'completed';     
     case Cancelled = 'cancelled';

     public function isLocked(): bool {
          return in_array($this, [self::Completed, self::Cancelled], true);
     }
}