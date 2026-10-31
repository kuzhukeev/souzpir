<?php

namespace App\Seller\Conversations\Data;

enum ReviewType: string
{
    case Unclear = 'unclear';
    case Bureaucratic = 'bureaucratic';
    case TooLong = 'too_long';
    case WrongFact = 'wrong_fact';
    case WrongTone = 'wrong_tone';
    case Other = 'other';
}
