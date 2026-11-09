<?php

namespace App\Ai\Contracts;

enum EmbedKind: string
{
    case Query = 'query';
    case Document = 'document';
}
