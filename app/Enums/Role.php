<?php

namespace App\Enums;

enum Role:string {
     case SuperAdmin = 'super_admin';
     case ClinicManager = 'clinic_manager';
     case Doctor = 'doctor';
     case Receptionist = 'receptionist';
     case Patient = 'patient';

     public static function staff(): array {
          return [self::Doctor, self::Receptionist, self::ClinicManager];
     }

     public static function staffValues(): array {
          return array_map(fn (self $role) => $role->value, self::staff());
     }
}