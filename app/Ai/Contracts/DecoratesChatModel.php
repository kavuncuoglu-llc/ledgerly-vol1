<?php

namespace App\Ai\Contracts;

interface DecoratesChatModel extends ChatModel
{
    public function inner(): ChatModel;

    public function withInner(ChatModel $inner): static;
}
