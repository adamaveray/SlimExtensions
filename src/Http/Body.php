<?php
declare(strict_types=1);

namespace AdamAveray\SlimExtensions\Http;

use Psr\Http\Message\StreamInterface;

class Body extends \Slim\Http\Body
{
    /**
     * @param resource|string|null $stream
     */
    public function __construct($stream = null)
    {
        if ($stream === null) {
            // Default to empty stream
            $stream = $this->getTempStream();
        } elseif (\is_string($stream)) {
            // Convert string to stream
            $string = $stream;
            $stream = $this->getTempStream();
            fwrite($stream, $string);
        }

        parent::__construct($stream);
    }

    /**
     * @param string|StreamInterface $stringOrStream A string to append, or a StreamInterface to rewind and append
     * @return int
     */
    public function write($stringOrStream): int
    {
        $string = $stringOrStream;
        if ($stringOrStream instanceof StreamInterface) {
            // Stream provided - convert to string
            $stringOrStream->rewind();
            $string = $stringOrStream->getContents();
        }

        return parent::write($string);
    }

    /**
     * @return resource
     */
    private function getTempStream()
    {
        $stream = fopen('php://temp', 'rb+');
        if ($stream === false) {
            throw new \UnexpectedValueException('Failed opening temporary resource');
        }
        return $stream;
    }
}
