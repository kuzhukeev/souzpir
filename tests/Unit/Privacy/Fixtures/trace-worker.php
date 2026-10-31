<?php

require __DIR__.'/../../../../vendor/autoload.php';

use App\Privacy\ContactBoundary;

$needle = 'private-synthetic@example.test';
foreach ([$needle, 'broken \\uD800 '.$needle, chr(255).$needle] as $value) {
    try {
        (new ContactBoundary)->assertSafe(['messages' => [['role' => 'user', 'content' => $value]]]);
        throw new RuntimeException('Unexpectedly accepted test input.');
    } catch (InvalidArgumentException $exception) {
        if ($exception->getPrevious() !== null || str_contains((string) $exception, $needle)) {
            throw new RuntimeException('Sensitive input leaked into exception text.');
        }
        $inspect = function (mixed $entry) use (&$inspect, $needle): bool {
            if ($entry instanceof SensitiveParameterValue) {
                return false;
            }
            if (is_array($entry)) {
                foreach ($entry as $item) {
                    if ($inspect($item)) {
                        return true;
                    }
                }

                return false;
            }

            return is_string($entry) && str_contains($entry, $needle);
        };
        if ($inspect($exception->getTrace())) {
            throw new RuntimeException('Sensitive input leaked into exception trace.');
        }
    }
}
echo "clean\n";
