<?php

namespace App\Enums;

enum TicketCategory: string
{
    case Howto = 'howto';
    case Billing = 'billing';
    case Bug = 'bug';
    case Account = 'account';
    case Other = 'other';
}
