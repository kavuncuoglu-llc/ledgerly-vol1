<?php

namespace App\Ai\Retrieval;

enum RetrievalMode: string
{
    case Hybrid = 'hybrid';
    case Vector = 'vector';
    case Keyword = 'keyword';
}
