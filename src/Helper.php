<?php

declare(strict_types=1);

namespace CodeInsights\Debugger;

use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;

class Helper
{
    private static array $debuggingData = [];
    private static bool $firstDebugDuringThisRequest = true;
    private static string $logFilename;

    private static \Symfony\Component\VarDumper\Cloner\VarCloner $varCloner;
    private static \Symfony\Component\VarDumper\Dumper\CliDumper $varDumper;

    public static function debug($variable, $variableName, $localContextVariables, $backtrace, $calledFromFile, $calledFromLine): bool
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

            self::$logFilename = 'codeinsights_' . microtime(true) . '.' . mt_rand(10000, 99999) . '.dump';
        }

        $frame = [
            // TODO: Determine and remove webroot from absolute path?
            'filename' => $calledFromFile,
            'lineno' => $calledFromLine,
            'timestamp' => date('H:i:s', $clock),
            'date' => date('Y-m-d', $clock),
            'microtime' => $timestamp,
            'stack' => [],
        ];

        // === POPULATE STACKTRACE ===

        $stack = [];

        // First record of the backtrace contains debug_backtrace() method / function call made by the extension
        // unset($backtrace[0]);

        foreach ($backtrace as $depth => $backtraceDetails) {
            // TODO: Determine and remove webroot from absolute path?
            $backtrace[$depth]['file'] = $backtraceDetails['file'];

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
                // TODO: Determine and remove webroot from absolute path?
                'filename' => $backtraceDetails['file'],
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
                // TODO: Determine and remove webroot from absolute path?
                'filename' => $calledFromFile,
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

        $frame['stack'] = $stack;

        self::$debuggingData['frames'][] = $frame;

        self::$firstDebugDuringThisRequest = false;

        // TODO: Feature - Dump / log separately the variable monitored

        // Otherwise extension will treat calling of this method as unsucessful and report an error
        // by calling reportErrorWhenEvaluatingBreakpoint() method
        return true;
    }

    public static function dumpDataForAgent($logFilename, $data): void
    {
        $pathForLogDump = ini_get('codeinsights.directory');

        if (substr($pathForLogDump, -1) !== DIRECTORY_SEPARATOR)
        {
            $pathForLogDump .= DIRECTORY_SEPARATOR;
        }

        $pathForLogDump .= 'logs/';

        $logFile = $pathForLogDump . $logFilename;

        // echo 'Trying to write dump to: ' . $logFile . "\n";

        if (is_writable($pathForLogDump) !== true) {
            // TODO: Log error (folder for file creation might not exist or no write permissions)
            // echo 'Error: Folder for file creation might not exist or no write permissions.' . "\n";
            return;
        }

        file_put_contents($logFile, json_encode($data));
    }

    public static function sendDebugData(): void
    {
        self::dumpDataForAgent(self::$logFilename, self::$debuggingData);
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

    // Called if extension encounters fatal runtime errors & exceptions & parse (syntax) errors during evaluation of the breakpoint
    // or when debug() returns false
    public static function reportErrorWhenEvaluatingBreakpoint($breakpoint_id, $breakpoint_filename, $breakpoint_lineno, $error_message) {

        // Happens if extension passes exception object
        if (isset($error_message->message)) {
            $error_message = $error_message->message;
        }

        $filename = 'codeinsights_' . microtime(true) . '.' . mt_rand(10000, 99999) . '.message';

        self::dumpDataForAgent($filename, array(
            'type' => 'error-when-evaluating-breakpoint',
            'breakpoint_id' => $breakpoint_id,
            'error_message' => $error_message,
        ));

        return true;
    }

    // Extension registers this method as error handler for catching warnings & notices during breakpoint evaluation
    // https://www.php.net/set_error_handler
    public static function errorHandler($errno, $errstr, $errfile, $errline) {

        // Retrieve info which brealpoint is currently being processed by the extension
        codeinsights_current_breakpoint_info($breakpoint_id, $breakpoint_filename, $breakpoint_lineno);

        if (empty($breakpoint_id)) {
            // Not in the middle of breakpoint evaluation
            return;
        }

        $error_message = 'An error occured while trying to evaluate breakpoint (' . $errno . '): ' . $errstr;

        self::reportErrorWhenEvaluatingBreakpoint($breakpoint_id, $breakpoint_filename, $breakpoint_lineno, $error_message);

        // Don't execute PHP internal error handler:
        // return true;
    }
}
