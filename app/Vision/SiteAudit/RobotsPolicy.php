<?php

namespace App\Vision\SiteAudit;

/** RFC 9309 groups, longest path rule, wildcard and Allow precedence. */
final class RobotsPolicy
{
    public static function allowed(string $url, ?array $response, string $userAgent = 'eyon-audit'): bool
    {
        if (! $response) {
            return false;
        }
        if ($response['status'] === 404 || $response['status'] === 410) {
            return true;
        }
        if ($response['status'] !== 200) {
            return false;
        }
        $groups = [];
        $agents = [];
        $rules = [];
        $hasRules = false;
        foreach (preg_split('/\R/', $response['body']) as $line) {
            $line = trim(preg_replace('/#.*/', '', $line));
            if (preg_match('/^user-agent\s*:\s*(\S+)/i', $line, $m)) {
                if ($hasRules) {
                    $groups[] = ['agents' => $agents, 'rules' => $rules];
                    $agents = [];
                    $rules = [];
                    $hasRules = false;
                }
                $agents[] = strtolower($m[1]);
            } elseif ($agents && preg_match('/^(allow|disallow)\s*:\s*(.*)$/i', $line, $m)) {
                $hasRules = true;
                $pattern = trim($m[2]);
                if ($pattern !== '' && str_starts_with($pattern, '/')) {
                    $rules[] = ['allow' => strtolower($m[1]) === 'allow', 'pattern' => self::normalize($pattern)];
                }
            }
        }
        if ($agents) {
            $groups[] = ['agents' => $agents, 'rules' => $rules];
        }
        $agent = strtolower($userAgent);
        $specific = array_values(array_filter($groups, fn ($g) => in_array($agent, $g['agents'], true) || in_array($agent.'/1.0', $g['agents'], true)));
        $selected = $specific ?: array_values(array_filter($groups, fn ($g) => in_array('*', $g['agents'], true)));
        $path = self::normalize((parse_url($url, PHP_URL_PATH) ?: '/').(($q = parse_url($url, PHP_URL_QUERY)) !== null ? '?'.$q : ''));
        $best = -1;
        $allowed = true;
        foreach ($selected as $group) {
            foreach ($group['rules'] as $rule) {
                $pattern = $rule['pattern'];
                $end = str_ends_with($pattern, '$');
                if ($end) {
                    $pattern = substr($pattern, 0, -1);
                }
                $regex = '~^'.str_replace('\\*', '.*', preg_quote($pattern, '~')).($end ? '$' : '').'~';
                if (! preg_match($regex, $path)) {
                    continue;
                }
                $length = strlen(str_replace('*', '', $pattern));
                if ($length > $best || ($length === $best && $rule['allow'])) {
                    $best = $length;
                    $allowed = $rule['allow'];
                }
            }
        }

        return $allowed;
    }

    private static function normalize(string $path): string
    {
        $path = preg_replace_callback('/%([0-9a-f]{2})/i', fn ($m) => preg_match('/[A-Za-z0-9\-._~]/', chr(hexdec($m[1]))) ? chr(hexdec($m[1])) : '%'.strtoupper($m[1]), $path);

        return preg_replace_callback('/[^\x20-\x7e]/', fn ($m) => rawurlencode($m[0]), $path);
    }
}
