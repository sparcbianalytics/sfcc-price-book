<?php
declare(strict_types=1);
namespace SPARC\SFCCPriceBook;


abstract class AbstractInterface {
    // Version string
    public const VERSION = '1.0.0';

    public bool $debug = false;
    public string $destinationPath;
    public bool $quiet = false;
    public string $sourceFile;



    public function __construct() {
        Runtime::init($this);
    }
}