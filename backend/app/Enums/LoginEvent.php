<?php

namespace App\Enums;

enum LoginEvent: string
{
    case Success = 'login_success';
    case Failed = 'login_failed';
    case LockedOut = 'locked_out';
    case Blocked = 'blocked_status';
    case TwoFactorChallenged = 'two_factor_challenged';
    case TwoFactorFailed = 'two_factor_failed';
    case Logout = 'logout';
    case LogoutOthers = 'logout_other_devices';
    case PasswordReset = 'password_reset';
    case PasswordChanged = 'password_changed';
}
