<?php

namespace App\Enums;

enum Role:string {
     case SuperAdmin = 'super_admin';
     case ClinicManager = 'clinic_manager';
     case Doctor = 'doctor';
     case Receptionist = 'receptionist';
     case Patient = 'patient';
}