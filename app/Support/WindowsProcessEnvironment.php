<?php

namespace App\Support;

class WindowsProcessEnvironment
{
    /**
     * Symfony filters inherited variables against $_SERVER on web SAPIs. Windows
     * PHP's built-in server omits SystemRoot there, breaking Python's OS RNG at
     * startup. Restore only OS runtime variables, never HTTP/request variables.
     *
     * @return array<string, string>
     */
    public static function variables(): array
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return [];
        }

        $environment = [];
        foreach (['SystemRoot', 'WINDIR', 'PATH', 'TEMP', 'TMP'] as $name) {
            $value = getenv($name, true);
            if (is_string($value) && $value !== '') {
                $environment[$name] = $value;
            }
        }

        return $environment;
    }
}
