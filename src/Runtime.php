<?php
declare(strict_types=1);
namespace SPARC\SFCCPriceBook;
use Aws\Sns\SnsClient,
    MensBeam\Catcher,
    MensBeam\Catcher\AWSSNSHandler,
    MensBeam\Catcher\PlainTextHandler,
    MensBeam\Logger,
    MensBeam\Logger\StreamHandler;


class Runtime {
    public static Catcher $catcher;
    public static array $config = [];
    public static string $cwd;
    public static AbstractInterface $interface;
    public static Logger $logger;
    public static \DateTimeImmutable $now;
    public static string $user;
    public static int $userId;

    public static function init(AbstractInterface $interface) {
        self::$interface = $interface;
        self::$now = new \DateTimeImmutable();
        self::$cwd = $cwd = dirname(__DIR__);
        self::$userId = $userId = posix_geteuid();
        self::$user = posix_getpwuid($userId)['name'];

        // Set up the catcher. Logger will eventually print error messages instead of
        // Catcher, but until then we need to have Catcher remain vocal.
        self::$catcher = new Catcher(new PlainTextHandler([ 'outputBacktrace' => true ]));

        $handlerOptions = [
            'entryTransform' => [ self::class, 'entryTransform' ],
            'messageTransform' => [ self::class, 'messageTransform' ],
            'timeFormat' => 'H:i:s'
        ];

        // Quiet mode doesn't prevent printing of errors.
        $loggers = [
            new StreamHandler(
                stream: 'php://stderr',
                levels: range(0, 3),
                options: $handlerOptions
            ),
            new StreamHandler(
                stream: "$cwd/logs/sfcc-price-book.log",
                levels: range(0, (self::$interface->debug) ? 7 : 6),
                options: [
                    'entryTransform' => [ self::class, 'entryTransformFile' ],
                    'messageTransform' => [ self::class, 'messageTransform' ],
                ]
            )
        ];

        if (!self::$interface->quiet) {
            $loggers[] = new StreamHandler(
                stream: 'php://stdout',
                levels: range(4, (self::$interface->debug) ? 7 : 6),
                options: $handlerOptions
            );
        }

        self::$logger = new Logger(
            'core',
            ...$loggers
        );

        self::$catcher->errorHandlingMethod = Catcher::THROW_ALL_ERRORS;
        try {
            self::$config = (include "$cwd/aws-credentials.php");
        } catch (\Throwable $t) {
            throw new \RuntimeException(sprintf('File "%s/aws-credentials.php" is missing or invalid', $cwd));
        }
        self::$catcher->errorHandlingMethod = Catcher::THROW_FATAL_ERRORS;

        if (!self::$interface->debug) {
            $client = new SnsClient([
                'version' => 'latest',
                'region' => self::$config['region'],
                'credentials' => [
                    'key' => self::$config['key'],
                    'secret' => self::$config['secret']
                ]
            ]);
            self::$catcher->pushHandler(new AWSSNSHandler($client, self::$config['sns_arn']));
        }

        Runtime::$logger->info('SFCC Price Book %s', [ $interface::VERSION ]);

        $catcherHandlers = self::$catcher->getHandlers();
        $catcherHandlers[0]->setOption('logger', self::$logger);
        // Only set catcher handler to silent after the logger has been set up.
        $catcherHandlers[0]->setOption('silent', true);
    }


    public static function entryTransform(string $time, int $level, string $levelName, string $channel, string $message): string {
        return "$time  $message";
    }

    public static function entryTransformFile(string $time, int $level, string $levelName, string $channel, string $message): string {
        return "$time $channel $levelName  $message";
    }

    public static function messageTransform(string $message, array $context = []): string {
        try {
            return vsprintf($message, $context);
        } catch (\ValueError $e) {
            trigger_error($e->getMessage(), \E_USER_WARNING);
        }

        return $message;
    }


    private function __construct() {}
}