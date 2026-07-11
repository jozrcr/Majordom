<?php

namespace App\Agents\Harness;

interface Harness
{
    public function runTask(HarnessRequest $request): HarnessResult;
}
