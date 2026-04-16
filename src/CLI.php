<?php
declare(strict_types=1);
namespace SPARC\SFCCPriceBook;
use Aws\S3\S3Client,
    Aws\S3\MultipartUploader,
    GuzzleHttp\Exception\ConnectException,
    JKingWeb\DrUUID\UUID,
    MensBeam\Catcher,
    MensBeam\Filesystem as Fs,
    MensBeam\Path;


class CLI extends AbstractInterface {
    public const USAGE = <<<USAGE_TEXT
    Usage:
        sfcc-price-book [options] <source_file> <destination_path>
        sfcc-price-book -h | --help

    Options:
        -h --help                    Show this screen
        -q --quiet                   Quiet mode
        -d --debug                   Debug mode
    USAGE_TEXT;

    protected ?S3Client $s3Client = null;




    public function __construct() {
        $args = ((array)\Docopt::handle(self::USAGE))['args'];
        $this->debug = $args['--debug'];
        // If debug is true quiet doesn't matter
        $this->quiet = (!$this->debug) ? $args['--quiet'] ?? false : false;
        $this->sourceFile = $args['<source_file>'];
        $this->destinationPath = $args['<destination_path>'];
        parent::__construct();
        $this->dispatch();
    }




    public function dispatch(): void {
        $parts = explode('/', Path::normalize($this->sourceFile));
        if ($parts < 1) {
            throw new \Exception('<source_path> must contain an s3 bucket');
        }
        $sourceBucket = $parts[0];
        array_shift($parts);
        $sourceKey = implode('/', $parts);
        if ($sourceKey === '') {
            throw new \Exception('<source_path> must contain an s3 key');
        }

        $parts = explode('/', Path::normalize($this->destinationPath));
        if ($parts < 1) {
            throw new \Exception('<destination_path> must contain an s3 bucket');
        }

        $this->s3Client = new S3Client([
            'version' => 'latest',
            'region' => Runtime::$config['region'],
            'credentials' => [
                'key' => Runtime::$config['key'],
                'secret' => Runtime::$config['secret']
            ]
        ]);
        $this->s3Client->registerStreamWrapper();
        $context = stream_context_create([ 's3' => [ 'seekable' => true ] ]);

        $sourceFullPath = Path::join($sourceBucket, $sourceKey);
        // Some files can have multiple file extensions, separate them all
        preg_match('/^(.+?)((?:\.[a-z0-9]+)+)?$/i', basename($sourceKey), $m);
        $sourceBaseName = $m[1];
        $sourceExt = trim($m[2] ?? '', '.');
        $sourceExt = ($sourceExt !== '') ? ".$sourceExt" : '';

        $lastExt = '.' . pathinfo($sourceKey, \PATHINFO_EXTENSION);
        if ($lastExt !== '.xml') {
            Runtime::$logger->info('Source file s3://%s does not have a file extension supported by this tool (*.xml); exiting', [ $sourceFullPath ]);
            return;
        }

        $destinationBucket = $parts[0];
        array_shift($parts);
        $destinationPath = implode('/', $parts);

        $destinationBaseName = $sourceBaseName;
        $destinationPathName = $tempFileName = Path::join("$destinationBaseName.csv");
        $destinationKey = Path::join($destinationPath, $destinationPathName);
        $destinationFullPath = Path::join($destinationBucket, $destinationKey);

        Runtime::$logger->info('Opening file s3://%s', [ $sourceFullPath ]);

        $UUID = UUID::mintStr();
        $tempFolder = (!$this->debug) ? "/tmp/sfcc-price-book/$UUID" : Runtime::$cwd . "/debug/$UUID";
        Fs::mkdir($tempFolder, 0770);
        $tempFileFullPath = Path::join ($tempFolder, $tempFileName);
        $tempFileStream = null;

        try {
            Runtime::$logger->info('Parsing file s3://%s', [ $sourceFullPath ]);

            $sourceStream = fopen(
                filename: sprintf('s3://%s', $sourceFullPath),
                mode: 'rb',
                context: $context
            );
            $sourceData = stream_get_contents($sourceStream);

            $dom = new \DOMDocument();
            $dom->loadXML($sourceData);
            unset($sourceData);
            $xpath = new \DOMXPath($dom);
            $ns = 'http://www.demandware.com/xml/impex/pricebook/2006-10-31';
            $xpath->registerNamespace('bs', 'http://www.demandware.com/xml/impex/pricebook/2006-10-31');
            $rowsWritten = false;

            /** @var \DOMNodeList<\DOMElement> */
            $priceBooks = $xpath->query('//bs:pricebook');
            foreach ($priceBooks as $priceBook) {
                $priceBookId = $xpath->query('.//bs:header/@pricebook-id', $priceBook);

                /** @var ?string */
                $priceBookId = ($priceBookId[0] ?? [])?->value;
                if ($priceBookId === null || $priceBookId === '') {
                    throw new \RuntimeException(sprintf('Could not find the pricebook-id in %s', $sourceFullPath));
                }

                /** @var \DOMNodeList<\DOMElement> */
                $priceTables = $xpath->query('.//bs:price-tables/bs:price-table', $priceBook);
                foreach ($priceTables as $priceTable) {
                    $productId = $priceTable->getAttribute('product-id');
                    if ($productId === null || $productId === '') {
                        continue;
                    }
                    if (!is_resource($tempFileStream)) {
                        $tempFileStream = fopen($tempFileFullPath, 'w');
                        fputcsv(stream: $tempFileStream, fields: [ 'price_book_id', 'product_id', 'quantity', 'amount' ], escape: '');
                    }

                    /** @var ?\DomElement */
                    $amountElement = $priceTable->getElementsByTagNameNS($ns, 'amount')?->item(0);
                    $amount = $quantity = null;
                    if ($amountElement !== null) {
                        $quantity = $amountElement->getAttribute('quantity');
                        $amount = $amountElement->textContent;
                    }

                    fputcsv(stream: $tempFileStream, fields: [ $priceBookId, $productId, $quantity, $amount ], escape: '');
                    $rowsWritten = true;
                }
            }

            if ($rowsWritten) {
                fclose($tempFileStream);
                Runtime::$logger->info('Writing s3://%s', [ $destinationFullPath ]);
                $stream = $this->writeToBucket($destinationBucket, $destinationKey, $tempFileFullPath);
                fclose($stream);
            } else {
                Runtime::$logger->info('No data to write; exiting');
            }
        } catch (\Throwable $t) {
            throw $t;
        } finally {
            if (!$this->debug) {
                Fs::remove($tempFolder);
            }
        }
    }

    /** @param resource $from */
    protected function copyFile($from, string $toFileName) {
        $failCount = 10;
        Runtime::$catcher->errorHandlingMethod = Catcher::THROW_ALL_ERRORS;
        while (true) {
            try {
                $to = fopen(
                    filename: $toFileName,
                    mode: 'w+b'
                );
                stream_copy_to_stream($from, $to);
            } catch (\Throwable $t) {
                if (str_contains(strtolower($t->getMessage()), 'idle timeout') && ++$failCount <= 10) {
                    continue;
                }

                throw $t;
            }

            break;
        }
        Runtime::$catcher->errorHandlingMethod = Catcher::THROW_FATAL_ERRORS;

        return $to;
    }

    protected function writeToBucket(string $bucket, string $key, string $pathToFile) {
        $request = [
            'Bucket' => $bucket,
            'Key' => $key
        ];
        if ($this->s3Client->doesObjectExist($bucket, $key)) {
            $this->s3Client->deleteObject($request);
        }

        $stream = fopen(
            filename: $pathToFile,
            mode: 'rb'
        );
        $request['Body'] = $stream;

        $failCount = 0;
        while (true) {
            try {
                $this->s3Client->putObject($request);
            } catch (\Throwable $t) {
                if ($t instanceof ConnectException && ++$failCount > 10) {
                    Runtime::$logger->debug('Connection timed out; retrying...');

                    // It is more likely the connection timed out, so try it again.
                    fclose($stream);
                    $stream = fopen(
                        filename: $pathToFile,
                        mode: 'rb'
                    );
                    $request['Body'] = $stream;

                    sleep(1);
                    continue;
                }

                throw $t;
            }

            break;
        }

        return $stream;
    }
}