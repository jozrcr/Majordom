<?php

namespace App\Sandbox;

use RuntimeException;

/** Thrown when command execution is requested but no real sandbox backs it. */
class SandboxUnavailable extends RuntimeException {}
