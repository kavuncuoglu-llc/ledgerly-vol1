<?php

namespace App\Ai\Retrieval;

interface ConfigurableRetriever extends Retriever
{
    public function using(RetrievalSettings $settings): static;

    public function settings(): RetrievalSettings;
}
