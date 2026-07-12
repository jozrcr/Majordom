<?php

namespace App\Agents\Providers;

interface Provider
{
    public function chat(ProviderRequest $request): ProviderResponse;
}
