<?php

namespace CodeInsights\Debugger;

use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;

// use Symfony\Component\VarDumper\Dumper\HtmlDumper;

class Helper
{
    private static array $debuggingData = [];
    private static bool $firstDebugDuringThisRequest = true;
    private static string $logFilename;

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

            self::$logFilename = 'codeinsights_' . microtime(true) . '.' . mt_rand(10000, 99999) . '.log';
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
            elseif (strpos($callSource, '{closure}') === false) {
                $callSource .= '()';
            }

            // Transform / wrangle backtrace data to the stacktrace format expected by the web "IDE"
            $stack[] = [
                'where' => $callSource,
                'type' => 'file',
                'filename' => str_replace($_ENV['WEBROOT'], '', $backtraceDetails['file']),
                'lineno' => $backtraceDetails['line'],
                'retrieve_context' => false,
                'dump_readable' => '',
                // 'debugOriginalBacktrace' => $backtraceDetails,
            ];
        }

        if ($frame['filename'] == 'codeinsights://debug-eval') {
            $frame['filename'] = $backtrace[0]['file'];
            $frame['lineno'] = $backtrace[0]['line'];
        }

        if (empty($backtrace))
        {
            $stack[] = [
                'where' => '{main}',
                'type' => 'file',
                'filename' => str_replace($_ENV['WEBROOT'], '', $calledFromFile),
                'lineno' => $calledFromLine,
                'retrieve_context' => false,
                'dump_readable' => '',
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
            self::dumpVariablesForDebugging($stack[0], 'Locals', $localContextVariables);
        }

        // === POPULATE FRAME WITH GLOBAL VARIABLES ===

        if (self::$firstDebugDuringThisRequest === true) {
            $constants = get_defined_constants(true)['user'] ?? array();

            if (empty($GLOBALS) === false) {
                self::dumpVariablesForDebugging($stack[0], 'Superglobals', $GLOBALS);
            }
        }

        // === POPULATE FRAME WITH CONSTANTS ===

        if (self::$firstDebugDuringThisRequest === true) {
            $constants = get_defined_constants(true)['user'] ?? array();

            if (empty($constants) === false) {
                self::dumpVariablesForDebugging($stack[0], 'User defined constants', $constants, constantsListed: true);
            }
        }

        // print_r($stack);

        $frame['stack'] = $stack;

        // print_r($frame);

        self::$debuggingData['frames'][] = $frame;
        // echo '---' . "\n";

        self::$firstDebugDuringThisRequest = false;

        // TODO: Feature - Dump / log separately the variable monitored
    }

    public static function sendDebugData(): void
    {
        // print_r(self::$debuggingData); die();

        $pathForLogDump = dirname(ini_get('codeinsights.breakpoint_file')) . '/logs/';
        $logFile = $pathForLogDump . self::$logFilename;

        file_put_contents($logFile, json_encode(self::$debuggingData));
    }

    private static function dumpVariablesForDebugging(&$stacktrace, $groupName, $variables, $constantsListed = false) : void
    {
        $stacktrace['retrieve_context'] = true;

        if (empty($stacktrace['dump_readable']) === false) {
            $stacktrace['dump_readable'] .= "\n";
        }

        $stacktrace['dump_readable'] .= '// ' . $groupName . "\n";

        foreach ($variables as $variableName => $variableValue) {
            $variableValue = trim(self::dump($variableValue));
            $stacktrace['dump_readable'] .= ($constantsListed !== true ? '$' : '') . $variableName . ' = ' . $variableValue . ';' . "\n";
        }
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
