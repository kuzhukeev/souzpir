<?php

namespace App\Shell\Brands\Actions;

use RuntimeException;

/** The vision_policy_change_guard trigger blocks knowledge_public changes while a grant is active. */
final class BrandAiPolicyLocked extends RuntimeException {}
