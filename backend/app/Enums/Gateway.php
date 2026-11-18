<?php

namespace App\Enums;

enum Gateway: string
{
    case Stripe = 'stripe';
    case Razorpay = 'razorpay';
    case Manual = 'manual'; // complimentary / admin-granted
}
