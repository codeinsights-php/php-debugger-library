<?php

namespace CodeInsights\Debugger;

use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;

// use Symfony\Component\VarDumper\Dumper\HtmlDumper;

class Helper
{
    private static array $debuggingData = [];
    private static bool $firstDebugDuringThisRequest = true;

    private static \Symfony\Component\VarDumper\Cloner\VarCloner $varCloner;
    private static \Symfony\Component\VarDumper\Dumper\CliDumper $varDumper;

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

            self::$varCloner = new VarCloner();
            self::$varDumper = new CliDumper();

            self::$varCloner->setMaxItems(30);
            self::$varCloner->setMaxString(100);
        }

        $frame = [
            // TODO: Improve how absolute path of webroot is determined
            'filename' => str_replace($_ENV['WEBROOT'], '', $calledFromFile),
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
            $backtrace[$depth]['file'] = str_replace($_ENV['WEBROOT'], '', $backtraceDetails['file']);

            // Ignoring function/method arguments for now because they should be dumped with snapshot's local context variables anyway
            //
            // $arguments = [];
            //
            // if (isset($backtraceDetails['args']) === true && empty($backtraceDetails['args']) === false) {
            //     foreach ($backtraceDetails['args'] as $arg) {
            //         $arguments[] = var_export($arg, true);
            //     }
            // }
            //
            // $arguments = implode(', ', $arguments);
            //
            // $callSource = '';
            //
            // if (isset($backtraceDetails['class'])) {
            //     $callSource .= $backtraceDetails['class'] . $backtraceDetails['type'];
            // }

            $callSource = $backtraceDetails['function'];

            if ($callSource == 'unknown') {
                $callSource = '{extension}';
            }

            if (strpos($callSource, '{closure}') === false) {
                $callSource .= '()';
            }

            // Transform / wrangle backtrace data to the stacktrace format expected by the web "IDE"
            $stack[] = [
                'where' => $callSource,
                'type' => 'file',
                'filename' => str_replace($_ENV['WEBROOT'], '', $backtraceDetails['file']),
                'lineno' => $backtraceDetails['line'],
                'retrieve_context' => false,
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

        $pusher = new \Pusher\Pusher(
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

    private static function convertVariablesToStringifiedArrayList($variables, $constantsListed = false)
    {
        $variableList = [];

        foreach ($variables as $variableName => $variableValue) {
            $variableValue = trim(self::dump($variableValue));
            $variableList[] = ($constantsListed !== true ? '$' : '') . $variableName . ' = ' . $variableValue . ';';
        }

        return $variableList;
    }

    private static function dump($variable): string
    {
        $data = self::$varCloner->cloneVar($variable);

        // https://symfony.com/doc/current/components/var_dumper/advanced.html#cloners
        $data->withMaxDepth(2);
        $data->withMaxItemsPerDepth(50);

        return self::$varDumper->dump($data, true, [
            // 1 and 160 are the default values for these options
            'maxDepth' => 1,
            'maxStringLength' => 160,
        ]);
    }
}
