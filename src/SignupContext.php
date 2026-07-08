<?php

declare(strict_types=1);

namespace JamesGifford\Hold;

/**
 * Which kind of hold captured a signup.
 *
 * Determines which announcement an address later receives: a prelaunch signup
 * gets the "we've launched" message; a maintenance signup gets "we're back".
 */
enum SignupContext: string
{
    case Prelaunch = 'prelaunch';
    case Maintenance = 'maintenance';
}
