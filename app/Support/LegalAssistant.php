<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Support;

use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Attributes\MaxTokens;

#[MaxTokens(4000)]
final class LegalAssistant extends AnonymousAgent {}
