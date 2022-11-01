<?php

namespace CodeInsights\Debugger;

class Helper
{
    private static array $debuggingData = [];
    private static bool $firstDebugDuringThisRequest = true;

    public static function debug($variable, $variableName, $localContextVariables, $backtrace, $calledFromFile, $calledFromLine): void
    {
        $timestamp = microtime(true);
        $clock = time();

        if (self::$firstDebugDuringThisRequest === true) {
            register_shutdown_function(array(__CLASS__, 'sendDebugData'));

            self::$debuggingData['snapshot_info'] = [
                'request_time' => (isset($_SERVER['REQUEST_TIME']) ? date('H:i:s', $_SERVER['REQUEST_TIME']) : 'HH:mm:ss'),
                'request_uri' => $_SERVER['REQUEST_URI'] ?? '(uri)',
                'host' => $_SERVER['HTTP_HOST'] ?? '(host)',
                'client_ip' => $_SERVER['REMOTE_ADDR'] ?? '(user ip)',
            ];

            self::$debuggingData['frames'] = [];
        }

        $currentWorkingDirectory = getcwd() . DIRECTORY_SEPARATOR;

        $frame = [
            'filename' => str_replace($currentWorkingDirectory, '', $calledFromFile),
            'lineno' => $calledFromLine,
            'timestamp' => date('H:i:s', $clock),
            'date' => date('Y-m-d', $clock),
            'microtime' => $timestamp,
            'stack' => [],
        ];

        // print_r($frame);

        // === POPULATE STACKTRACE ===

        $stack = [];

        // First record of the backtrace contains debug_backtrace() method / function call made by the extension
        // unset($backtrace[0]);

        foreach ($backtrace as $depth => $backtraceDetails) {
            // Retain only absolute path (remove webroot) from backtrace
            $backtrace[$depth]['file'] = str_replace($currentWorkingDirectory, '', $backtraceDetails['file']);

            $arguments = [];

            foreach ($backtraceDetails['args'] as $arg) {
                $arguments[] = var_export($arg, true);
            }

            $arguments = (!empty($arguments) ? implode(', ', $arguments) : '');

            $arguments = self::cleanStringifiedObject($arguments);

            // Transform / wrangle backtrace data to the stacktrace format expected by the web "IDE"
            $stack[] = [
                'where' =>
                    (isset($backtraceDetails['class']) ? $backtraceDetails['class'] . $backtraceDetails['type'] : '') .
                    $backtraceDetails['function'] .
                    '(' . $arguments . ')'
                ,
                'type' => 'file',
                'filename' => $backtraceDetails['file'],
                'lineno' => $backtraceDetails['line'],
                'retrieve_context' => false,
            ];
        }

        if (isset($stack[0]) === false) {
            $stack[] = [
                'where' => '{main}',
                'type' => '',
                'filename' => $calledFromFile,
                'lineno' => $calledFromLine,
            ];
        }

        // === POPULATE FRAME WITH LOCAL CONTEXT VARIABLES ===

        // Remove superglobals, they're not really local context variables
        unset($localContextVariables['_GET']);
        unset($localContextVariables['_POST']);
        unset($localContextVariables['_COOKIE']);
        unset($localContextVariables['_FILES']);
        unset($localContextVariables['_ENV']);
        unset($localContextVariables['_REQUEST']);
        unset($localContextVariables['_SERVER']);

        if (empty($localContextVariables) === false) {
            $stack[0]['retrieve_context'] = true;
            $stack[0]['contexts'][] = ['name' => 'Locals'];

            $variableList = self::convertVariablesToStringifiedArrayList($localContextVariables);

            $stack[0]['dump'][] = ['readable' => $variableList]; // each array record = line with a variable and its value
        }

        // === POPULATE FRAME WITH GLOBAL VARIABLES ===

        if (self::$firstDebugDuringThisRequest === true) {
            $constants = get_defined_constants(true)['user'] ?? array();

            if (empty($GLOBALS) === false) {
                $stack[0]['retrieve_context'] = true;
                $stack[0]['contexts'][] = ['name' => 'Superglobals'];

                $variableList = self::convertVariablesToStringifiedArrayList($GLOBALS);

                $stack[0]['dump'][] = ['readable' => $variableList];
            }
        }

        // === POPULATE FRAME WITH CONSTANTS ===

        if (self::$firstDebugDuringThisRequest === true) {
            $constants = get_defined_constants(true)['user'] ?? array();

            if (empty($constants) === false) {
                $stack[0]['retrieve_context'] = true;
                $stack[0]['contexts'][] = ['name' => 'User defined constants'];

                $variableList = self::convertVariablesToStringifiedArrayList($constants, constantsListed: true);

                $stack[0]['dump'][] = ['readable' => $variableList];
            }
        }

        // print_r($stack);

        $frame['stack'] = $stack;

        // print_r($frame);

        self::$debuggingData['frames'][] = $frame;
        // echo '---' . "\n";

        self::$firstDebugDuringThisRequest = false;

        // TODO: Deump / log separately the variable monitored
    }

    public static function sendDebugData(): void
    {
        // print_r(self::$debuggingData); die();

        $pusher = new \Pusher\Pusher
        (
            $_ENV['PUSHER_APP_KEY'],
            $_ENV['PUSHER_APP_SECRET'],
            $_ENV['PUSHER_APP_ID'],
            [
                'cluster' => $_ENV['PUSHER_CLUSTER'],
                'useTLS' => true,
            ],
        );

        $payload = base64_encode(gzdeflate(json_encode(self::$debuggingData), 9, ZLIB_ENCODING_DEFLATE));

        // TODO: Check if payload is too big or other error occured and report that back to user
        $pusher->trigger('private-' . $_ENV['PUSHER_CHANNEL'], 'debugging-event', $payload);
    }

    private static function cleanStringifiedObject($object)
    {
        $object = str_replace("\n", '', $object);
        $object = preg_replace('/\s+/', ' ', $object);

        // TODO: Check for a cleaner way to dump objects when they are passed as arguments
        $object = str_replace(',)', ')', $object);
        $object = str_replace('( \'', '(\'', $object);

        return $object;
    }

    private static function convertVariablesToStringifiedArrayList($variables, $constantsListed = false)
    {
        $variableList = [];

        foreach ($variables as $variableName => $variableValue) {
            switch (gettype($variableValue)) {
                case 'object':

                    $variableValue = var_export($variableValue, true);
                    $variableValue = self::cleanStringifiedObject($variableValue);

                    break;

                case 'array':

                    if (empty($variableValue) === true) {
                        $variableValue = 'array()';
                    } else {
                        $variableValue = var_export($variableValue, true);
                        $variableValue = str_replace('array (', 'array(', $variableValue);
                    }

                    break;

                case 'string':
                case 'integer':
                case 'boolean':

                    $variableValue = var_export($variableValue, true);

                    break;

                default:

                    // die('-- ' . gettype($variableValue) . ' --');
                    $variableValue = var_export($variableValue, true);
            }

            $variableList[] = ($constantsListed !== true ? '$' : '') . $variableName . ' = ' . $variableValue . ';';
        }

        return $variableList;
    }
}
