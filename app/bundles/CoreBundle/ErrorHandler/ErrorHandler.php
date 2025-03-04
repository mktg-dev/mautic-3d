<?php

namespace Mautic\CoreBundle\ErrorHandler {
    use Mautic\CoreBundle\Exception\DatabaseConnectionException;
    use Mautic\CoreBundle\Exception\ErrorHandlerException;
    use Psr\Log\LoggerInterface;
    use Psr\Log\LogLevel;
    use Symfony\Component\Debug\Exception\FatalErrorException;
    use Symfony\Component\Debug\Exception\FatalThrowableError;
    use Symfony\Component\Debug\Exception\OutOfMemoryException;
    use Symfony\Component\ErrorHandler\Debug;
    use Symfony\Component\ErrorHandler\Exception\FlattenException;

    class ErrorHandler
    {
        public static $handler;

        /**
         * @var string
         */
        private static $environment;

        /**
         * @var LoggerInterface
         */
        private $debugLogger;

        /**
         * @var LoggerInterface
         */
        private $displayErrors;

        /**
         * @var LoggerInterface
         */
        private $logger;

        private $mainLogger;

        /**
         * @var string
         */
        private static $root;

        public function __construct()
        {
            self::$root = realpath(__DIR__.'/../../../../');
        }

        /**
         * @param mixed               $log
         * @param string|array<mixed> $context
         * @param bool                $backtrace
         */
        public static function logDebugEntry($log, $context = 'null', $backtrace = false)
        {
            if ($debugLogger = self::$handler->getDebugLogger()) {
                if (!is_array($context)) {
                    if (null === $context) {
                        $context = ['null'];
                    } else {
                        $context = (array) $context;
                    }
                }

                if (is_array($log)) {
                    array_unshift($context, $log);
                    $log = 'Array ('.count($log).')';
                } elseif (!is_string($log)) {
                    $log = var_export($log, true);
                }

                $debug = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
                if (true === $backtrace) {
                    $context['trace'] = array_slice($debug, 1, 5);
                }

                if (__FILE__ === $debug[0]['file']) {
                    $file             = $debug[1];
                    $file['function'] = $debug[2]['function'];
                } else {
                    $file             = $debug[1];
                    $file['function'] = $debug[1]['function'];
                }

                $log = str_replace(self::$root, '', $file['file']).':'.$file['line'].' in '.$file['function'].'(): '.$log;

                $debugLogger->debug($log, $context);
            }
        }

        /**
         * @return mixed
         */
        public function getDebugLogger()
        {
            return $this->debugLogger;
        }

        /**
         * @param $logger
         */
        public function setDebugLogger($logger)
        {
            $this->debugLogger = $logger;
        }

        /**
         * @return ErrorHandler
         */
        public static function getHandler()
        {
            if (!self::$handler) {
                // Handler has not been created so likely coming in through browser-kit client for tests
                self::register();
            }

            return self::$handler;
        }

        /**
         * @param        $level
         * @param        $message
         * @param string $file
         * @param int    $line
         * @param array  $context
         *
         * @return bool
         *
         * @throws \ErrorException
         */
        public function handleError($level, $message, $file = 'unknown', $line = 0, $context = [])
        {
            $errorReporting = ('dev' === self::$environment) ? -1 : error_reporting();
            if ($level & $errorReporting) {
                switch (true) {
                    case $level & E_STRICT:
                    case $level & E_NOTICE:
                    case $level & E_USER_NOTICE:
                        $logLevel = LogLevel::NOTICE;
                        break;
                    case $level & E_WARNING:
                    case $level & E_USER_WARNING:
                        $logLevel = LogLevel::WARNING;
                        break;
                    case $level & E_DEPRECATED:
                    case $level & E_USER_DEPRECATED:
                        $logLevel = LogLevel::DEBUG;
                        break;
                    default:
                        $logLevel = LogLevel::ERROR;
                }

                $message = 'PHP '.$this->getErrorName($level)." - $message";
                if (LogLevel::DEBUG === $logLevel) {
                    $this->log($logLevel, "$message - in file $file - at line $line", $context);
                } elseif ($this->displayErrors) {
                    throw new \ErrorException($message, 0, $level, $file, $line);
                } else {
                    $this->log($logLevel, "$message - in file $file - at line $line", $context);
                }
            }

            return false;
        }

        /**
         * @param      $exception
         * @param bool $returnContent
         * @param bool $inTemplate
         *
         * @return bool|string|void
         */
        public function handleException($exception, $returnContent = false, $inTemplate = false)
        {
            if (!$error = self::prepareExceptionForOutput($exception)) {
                return false;
            }

            $content = $this->generateResponse($error, $inTemplate);

            $message = isset($error['logMessage']) ? $error['logMessage'] : $error['message'];
            $this->log(LogLevel::ERROR, "$message - in file {$error['file']} - at line {$error['line']}", [], $error['trace']);

            if ($returnContent) {
                return $content;
            }

            http_response_code(500);

            if (!empty($GLOBALS['MAUTIC_AJAX_DIRECT_RENDER'])) {
                header('Content-Type: application/json');
                $content = json_encode(['newContent' => $content]);
            }

            echo $content;

            return false;
        }

        /**
         * Log fatal error to Mautic's logs and throw exception for the parent generic error page to catch.
         *
         * @throws \Exception
         */
        public function handleFatal()
        {
            static $handlingFatal = false;
            $error                = error_get_last();

            if (null !== $error) {
                $name = $this->getErrorName($error['type']);
                if ($error && $error['type'] &= E_PARSE | E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR) {
                    if (!$handlingFatal) {
                        // Prevent fatal loop
                        $handlingFatal = true;
                        $this->log(LogLevel::ERROR, "PHP $name: {$error['message']} - in file {$error['file']} - at line {$error['line']}");

                        if (0 === strpos($error['message'], 'Allowed memory') || 0 === strpos($error['message'], 'Out of memory')) {
                            $exception = new OutOfMemoryException(
                                $this->getErrorName($error['type']).': '.$error['message'],
                                0,
                                $error['type'],
                                $error['file'],
                                $error['line'],
                                2,
                                false
                            );
                        } else {
                            $exception = new FatalErrorException(
                                $this->getErrorName($error['type']).': '.$error['message'],
                                0,
                                $error['type'],
                                $error['file'],
                                $error['line'],
                                2,
                                true
                            );
                        }

                        $this->handleException($exception);
                    }
                }
            }

            return false;
        }

        /**
         * @param $exception
         *
         * @return array
         */
        public static function prepareExceptionForOutput($exception)
        {
            $inline     = null;
            $logMessage = null;

            if (!$exception instanceof \Exception && !$exception instanceof FlattenException) {
                if ($exception instanceof \Throwable) {
                    $exception = new FatalThrowableError($exception);
                    $inline    = false;
                } else {
                    return false;
                }
            }

            $showExceptionMessage = false;
            if ($exception instanceof ErrorHandlerException) {
                $showExceptionMessage = $exception->showMessage();
                $message              = $exception->getMessage();

                if ($previous = $exception->getPrevious()) {
                    $exception  = $previous;
                    $logMessage = $exception->getMessage();

                    if ('dev' === self::$environment) {
                        $message = '<strong>'.get_class($exception).':</strong> '.$exception->getMessage();
                    }
                }
            } elseif ($exception instanceof DatabaseConnectionException) {
                $showExceptionMessage = true;
            }

            $type = ($exception instanceof \ErrorException) ? $exception->getSeverity() : E_ERROR;

            if (!$exception instanceof FlattenException) {
                $exception = FlattenException::createFromThrowable($exception);
            }

            if (empty($message)) {
                $message = ($showExceptionMessage && 'dev' !== self::$environment) ? $exception->getMessage()
                    : '<strong>'.$exception->getClass().':</strong> '.$exception->getMessage();
            }

            if ($previous = $exception->getPrevious()) {
                if ($previous = self::prepareExceptionForOutput($previous)) {
                    $previous['isPrevious'] = true;
                }
            }

            $handlingException = true;
            $line              = $exception->getLine();
            $file              = $exception->getFile();
            $trace             = $exception->getTrace();
            $context           = (method_exists($exception, 'getContext')) ? $exception->getContext() : [];

            return compact(['inline', 'type', 'message', 'logMessage', 'line', 'file', 'trace', 'context', 'showExceptionMessage', 'previous']);
        }

        /**
         * @param string $environment
         *
         * @return ErrorHandler
         */
        public static function register($environment = 'prod')
        {
            if ('dev' === $environment) {
                Debug::enable();
            }

            self::$handler = new self();
            self::$handler->setEnvironment($environment);

            /**
             * We need PHPUnit to convert notices/warnings/etc. to exceptions, so
             * we can't use our own ErrorHandler in that case.
             */
            if (!defined('IS_PHPUNIT')) {
                // Log PHP fatal errors
                register_shutdown_function([self::$handler, 'handleFatal']);

                // Log general PHP errors
                set_exception_handler([self::$handler, 'handleException']);
                set_error_handler([self::$handler, 'handleError']);

                // Hide errors by default so we can format them
                self::$handler->setDisplayErrors(('dev' === $environment) ? 1 : 0); //ini_get('display_errors'));
                ini_set('display_errors', '0');
            }

            return self::$handler;
        }

        /**
         * @param mixed $displayErrors
         *
         * @return ErrorHandler
         */
        public function setDisplayErrors($displayErrors)
        {
            $this->displayErrors = $displayErrors;

            return $this;
        }

        /**
         * @param string $environment
         *
         * @return ErrorHandler
         */
        public function setEnvironment($environment)
        {
            self::$environment = $environment;

            return $this;
        }

        /**
         * @param LoggerInterface $logger
         *
         * @return ErrorHandler
         */
        public function setLogger($logger)
        {
            $this->logger = $logger;

            return $this;
        }

        /**
         * @param mixed $mainLogger
         *
         * @return ErrorHandler
         */
        public function setMainLogger($mainLogger)
        {
            $this->mainLogger = $mainLogger;

            return $this;
        }

        /**
         * @param       $logLevel
         * @param       $message
         * @param array $context
         * @param null  $debugTrace
         */
        protected function log($logLevel, $message, $context = [], $debugTrace = null)
        {
            $message = strip_tags($message);
            if ($this->logger) {
                if (LogLevel::DEBUG === $logLevel) {
                    $this->mainLogger->log($logLevel, $message, $context);
                } else {
                    $this->logger->log($logLevel, $message, $context);

                    if ($this->debugLogger) {
                        if ($debugTrace) {
                            // Just a snippet
                            $context['trace'] = array_slice($debugTrace, 0, 50);
                        }
                        $this->debugLogger->log($logLevel, $message, $context);
                    }
                }
            } else {
                error_log($message);
            }
        }

        /**
         * @param mixed[] $error
         * @param bool    $inTemplate
         *
         * @return mixed|string
         */
        private function generateResponse($error, $inTemplate = false)
        {
            // Get a trace
            if ('dev' == self::$environment) {
                if (empty($error['trace'])) {
                    ob_start();
                    debug_print_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
                    $trace = ob_get_contents();
                    ob_end_clean();

                    // Remove first item from backtrace as it's this function which
                    // is redundant.
                    $error['trace'] = preg_replace('/^#0\s+'.__FUNCTION__."[^\n]*\n/", '', $trace, 1);

                    // Renumber backtrace items.
                    $error['trace'] = preg_replace_callback(
                        '/^#(\d+)/m',
                        function ($matches) {
                            return '#'.($matches[1] + 1).'&nbsp;&nbsp;';
                        },
                        $error['trace']
                    );
                }
            }

            $isAjax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 'XMLHttpRequest' == $_SERVER['HTTP_X_REQUESTED_WITH'])
                || (isset($_SERVER['HTTP_ACCEPT']) && 'application/json' === $_SERVER['HTTP_ACCEPT']);

            if (!$inTemplate && !defined('MAUTIC_RENDERING_TEMPLATE') && $isAjax) {
                $dataArray = [];
                if (!$this->displayErrors && empty($error['showExceptionMessage'])) {
                    $error['message'] = 'The site is currently offline due to encountering an error. If the problem persists, please contact the system administrator. System administrators, check server logs for errors.';
                }

                $error['message']    = strip_tags($error['message']);
                $dataArray['errors'] = [
                    [
                        'message' => $error['message'],
                        'code'    => 500,
                        'type'    => null,
                    ],
                ];

                if ('dev' == self::$environment) {
                    $dataArray['trace'] = $error['trace'];
                    if (isset($error['context'])) {
                        $dataArray['context'] = $error['context'];
                    }

                    foreach ($dataArray['trace'] as &$trace) {
                        unset($trace['args']);
                    }
                }

                header('Content-Type: application/json');

                return json_encode($dataArray);
            }

            if ('dev' == self::$environment || $this->displayErrors) {
                $error['file']          = str_replace(self::$root, '', $error['file']);
                $errorMessage           = (isset($error['logMessage'])) ? $error['logMessage'] : $error['message'];
                $error['message']       = "$errorMessage - in file {$error['file']} - at line {$error['line']}";
            } else {
                if (empty($error['showExceptionMessage'])) {
                    unset($error);
                    $error['message']    = 'The site is currently offline due to encountering an error. If the problem persists, please contact the system administrator.';
                    $error['submessage'] = 'System administrators, check server logs for errors.';
                }
            }

            defined('MAUTIC_OFFLINE') or define('MAUTIC_OFFLINE', 1);

            try {
                // Get the URLs base path
                $base  = str_replace(['index.php', 'index_dev.php'], '', $_SERVER['SCRIPT_NAME']);

                // Determine if there is an asset prefix
                $root = self::$root;

                /** @var array<string, mixed> $paths */
                $paths = [];
                include self::$root.'/app/config/paths.php';

                $assetPrefix = $paths['asset_prefix'];
                if (!empty($assetPrefix)) {
                    if ('/' == substr($assetPrefix, -1)) {
                        $assetPrefix = substr($assetPrefix, 0, -1);
                    }
                }
                $assetBase          = $assetPrefix.$base.$paths['assets'];
                $error['assetBase'] = $assetBase;

                // Allow a custom error page
                $loader             = new \Twig\Loader\FilesystemLoader(['app/bundles/CoreBundle/Resources/views/Offline', 'app/bundles/CoreBundle/Resources/views/Exception']);
                $twig               = new \Twig\Environment($loader);
                // This is the same filter Located at Mautic\CoreBundle\Twig\Extension\ExceptionExtension;
                $twig->addFunction(new \Twig\TwigFunction('getRootPath', function () {
                    $root = realpath(__DIR__.'/../../../../');

                    return $root;
                }));

                if ($loader->exists('custom_offline.html.twig')) {
                    $content = $twig->render('custom_offline.html.twig', ['error' => $error]);
                } else {
                    $content = $twig->render('offline.html.twig', ['error' => $error]);
                }
            } catch (\Exception $exception) {
                return $exception->getMessage();
            }

            if ('dev' == self::$environment && !empty($error['previous'])) {
                $previousContent = '<div><h4>Previous Exceptions</h4>'.$this->generateResponse($error['previous']).'</div>';
                $content         = str_replace('<div id="previous"></div>', $previousContent, $content);
            }

            return $content;
        }

        /**
         * @param $bit
         *
         * @return string
         */
        private function getErrorName($bit)
        {
            switch ($bit) {
                case E_PARSE:
                    return 'Parse Error';

                case E_ERROR:
                case E_USER_ERROR:
                case E_CORE_ERROR:
                case E_RECOVERABLE_ERROR:
                    return 'Error';

                case E_WARNING:
                case E_USER_WARNING:
                case E_CORE_WARNING:
                    return 'Warning';

                case E_DEPRECATED:
                case E_USER_DEPRECATED:
                    return 'Deprecation';

                default:
                    return 'Notice';
            }
        }
    }
}

namespace {
    use Mautic\CoreBundle\ErrorHandler\ErrorHandler;

    if (!function_exists('debugIt')) {
        function debug_it($log, ...$context)
        {
            if ('dev' === MAUTIC_ENV) {
                // Only allowing dev mode just in case uses accidentally left in code
                if (1 === count($context) && true === $context[0]) {
                    ErrorHandler::logDebugEntry($log, $context, true);
                } else {
                    ErrorHandler::logDebugEntry($log, (empty($context)) ? [] : $context);
                }
            }
        }

        // Call this at each point of interest, passing a descriptive string
        function prof_flag($str)
        {
            global $prof_timing, $prof_names;
            $prof_timing[] = microtime(true);
            $prof_names[]  = $str;
        }

        // Call this when you're done and want to see the results
        function prof_print()
        {
            global $prof_timing, $prof_names;
            $size = count($prof_timing);
            for ($i=0; $i < $size - 1; ++$i) {
                echo "<b>{$prof_names[$i]}</b><br>";
                echo sprintf('&nbsp;&nbsp;&nbsp;%f<br>', $prof_timing[$i + 1] - $prof_timing[$i]);
            }
            echo "<b>{$prof_names[$size - 1]}</b><br>";
        }
    }
}
